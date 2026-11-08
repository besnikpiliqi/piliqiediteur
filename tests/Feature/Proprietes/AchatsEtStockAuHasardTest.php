<?php

namespace Tests\Feature\Proprietes;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Des achats tirés au hasard (décision de l'éditeur du 01/11/2026, point
 * 5 bis) : factures d'achat de plusieurs lignes, à des PU HT à six
 * décimales, des taux et des remises variés, validées, retournées en
 * partie au fournisseur par des avoirs, et des ventes de ces produits à la
 * caisse, dans la limite du stock, dans tous les ordres. Après chaque cas,
 * ce qui doit toujours tenir tient :
 *
 * - chaque achat et chaque avoir fournisseur totalise exactement ses
 *   lignes, sa TVA jamais négative ;
 * - un avoir fournisseur ne retourne jamais plus d'articles qu'une ligne
 *   n'en a reçu, ni plus de HT ni de TVA ;
 * - le stock de chaque produit est la somme de ses mouvements, et la
 *   somme des réceptions moins les retours et les ventes ;
 * - le coût moyen pondéré n'est jamais négatif, et la valeur du stock ne
 *   descend jamais sous 0 €.
 */
class AchatsEtStockAuHasardTest extends TestCase
{
    use RefreshDatabase, VerifieDesProprietes;

    private const GRAINE = 20261108;

    private const NOMBRE_DE_CAS = 120;

    private Magasin $magasin;

    private User $responsable;

    /** @var list<Produit> */
    private array $produits;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-14 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $this->responsable = self::responsableDeTest($this->magasin);
        $this->actingAs($this->responsable);
        $this->produits = array_map(
            fn (array $produit): Produit => Produit::factory()->create(['prix_vente_ttc' => $produit[0], 'taux_tva' => $produit[1], 'suivi_stock' => true, 'stock_quantite' => 0]),
            [['19.90', 20], ['4.50', 5.5], ['89.00', 10]],
        );
        $this->fournisseur = Fournisseur::factory()->create();
    }

    public function test_des_achats_retours_au_fournisseur_et_ventes_au_hasard_gardent_le_stock_coherent(): void
    {
        $this->pourChaqueCas(
            self::GRAINE,
            self::NOMBRE_DE_CAS,
            fn (Tirage $tirage): array => array_map(fn (): array => $this->etape($tirage), range(1, $tirage->entier(1, 6))),
            function (array $etapes): void {
                foreach ($etapes as $etape) {
                    $this->jouer($etape);
                }

                $this->verifierLesRegles();
            },
            fn (array $etapes): iterable => self::etapesReduites($etapes, self::reduireUneEtape(...)),
        );
    }

    /**
     * Le plus petit cas qu'un tirage a trouvé (graine 20261109) : un avoir
     * fournisseur sur deux lignes d'achat sortait leur stock en relisant
     * le produit et la facture d'achat de chaque ligne, une requête par
     * ligne, que la prévention des requêtes N+1 refuse ; ses lignes se
     * lisent désormais ensemble (voir
     * DecrementerStockApresEmissionAvoirFournisseur).
     */
    public function test_un_avoir_fournisseur_de_deux_lignes_sort_leur_stock_sans_une_requete_par_ligne(): void
    {
        $this->jouer(['geste' => 'achat', 'lignes' => [
            ['produit' => 1, 'quantite' => 41, 'prix' => 1_398_138, 'remise' => 0],
            ['produit' => 0, 'quantite' => 44, 'prix' => 32_252_242, 'remise' => 0],
        ]]);
        $this->jouer(['geste' => 'retour', 'achat' => 6, 'pourcents' => [29, 51, 61, 2]]);

        $this->assertSame([11, 22], AvoirFournisseur::query()->sole()->lignes()->pluck('quantite')->map(fn ($quantite): int => (int) $quantite)->all());
        $this->verifierLesRegles();
    }

    /**
     * @return array<string, mixed>
     */
    private function etape(Tirage $tirage): array
    {
        return match ($tirage->parmi(['achat', 'achat', 'retour', 'vente'])) {
            'achat' => ['geste' => 'achat', 'lignes' => array_map(fn (): array => [
                'produit' => $tirage->entier(0, count($this->produits) - 1),
                'quantite' => $tirage->entier(1, 50),
                'prix' => $tirage->entier(1, 99_999_999),
                'remise' => $tirage->uneFoisSur(3) ? $tirage->entier(1, 5000) : 0,
            ], range(1, $tirage->entier(1, 4)))],
            'retour' => ['geste' => 'retour', 'achat' => $tirage->entier(0, 9), 'pourcents' => array_map(fn (): int => $tirage->entier(0, 100), range(1, 4))],
            'vente' => ['geste' => 'vente', 'produit' => $tirage->entier(0, count($this->produits) - 1), 'quantite' => $tirage->entier(1, 30)],
        };
    }

    /**
     * @param  array<string, mixed>  $etape
     * @return iterable<array<string, mixed>>
     */
    private static function reduireUneEtape(array $etape): iterable
    {
        foreach (array_keys($etape['lignes'] ?? []) as $rang) {
            if (count($etape['lignes']) > 1) {
                yield [...$etape, 'lignes' => array_values(array_diff_key($etape['lignes'], [$rang => true]))];
            }

            foreach (['quantite' => 1, 'remise' => 0] as $champ => $simple) {
                if ($etape['lignes'][$rang][$champ] !== $simple) {
                    $lignes = $etape['lignes'];
                    $lignes[$rang][$champ] = $simple;

                    yield [...$etape, 'lignes' => $lignes];
                }
            }
        }

        if (($etape['quantite'] ?? 1) > 1) {
            yield [...$etape, 'quantite' => 1];
        }
    }

    /**
     * @param  array<string, mixed>  $etape
     */
    private function jouer(array $etape): void
    {
        match ($etape['geste']) {
            'achat' => $this->acheter($etape['lignes']),
            'retour' => $this->retourner($etape['achat'], $etape['pourcents']),
            'vente' => $this->vendre($this->produits[$etape['produit']]->fresh(), $etape['quantite']),
        };
    }

    /**
     * Une vente à la caisse de ce produit, dans la limite de son stock ;
     * aucun stock, rien.
     */
    private function vendre(Produit $produit, int $quantite): void
    {
        $quantite = min($quantite, (int) $produit->stock_quantite);

        if ($quantite > 0) {
            self::vendreAuComptant($this->responsable, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->getKey(), 'quantite' => $quantite]], ModePaiement::CarteBancaire);
        }
    }

    /**
     * @param  list<array{produit: int, quantite: int, prix: int, remise: int}>  $lignes  PU HT en millionièmes d'euro, remise en centièmes de point
     */
    private function acheter(array $lignes): void
    {
        $calculateur = app(CalculateurLigne::class);
        $facture = app(CreerFactureAchatAction::class)->handle($this->responsable, $this->fournisseur, array_map(fn (array $ligne): array => [
            'produit_id' => $this->produits[$ligne['produit']]->getKey(),
            'quantite' => $ligne['quantite'],
            'prix_unitaire_ht' => $calculateur->enDecimalAuMillionieme($ligne['prix']),
            'taux_tva' => $this->produits[$ligne['produit']]->taux_tva,
            'remise_pourcentage' => $calculateur->enDecimal($ligne['remise']),
        ], $lignes), ['date_facture' => '2026-10-14']);

        app(ValiderFactureAchatAction::class)->handle($this->responsable, $facture);
    }

    /**
     * Un avoir fournisseur sur l'une des factures d'achat validées, pour une
     * part de ce que chaque ligne peut encore retourner ; rien, rien.
     *
     * @param  list<int>  $pourcents
     */
    private function retourner(int $rang, array $pourcents): void
    {
        $validees = FactureAchat::query()->whereNotNull('numero')->with('lignes.produit')->orderBy('id')->get();

        if ($validees->isEmpty()) {
            return;
        }

        $facture = $validees[$rang % $validees->count()];
        $selection = [];

        foreach ($facture->lignes->values() as $rangDeLigne => $ligne) {
            $quantite = intdiv(min($ligne->quantiteRetournableRestante(), max(0, $ligne->produit->stock_quantite)) * $pourcents[$rangDeLigne % count($pourcents)], 100);

            if ($quantite > 0) {
                $selection[] = ['ligne_achat_id' => $ligne->getKey(), 'quantite' => $quantite];
            }
        }

        if ($selection !== []) {
            app(EnregistrerAvoirFournisseurAction::class)->handle($this->responsable, $facture, $selection, 'Retour tiré au hasard');
        }
    }

    private function verifierLesRegles(): void
    {
        $calculateur = app(CalculateurLigne::class);
        $centimes = fn (mixed $montant): int => $calculateur->enCentiemes($montant);

        foreach ([...FactureAchat::query()->whereNotNull('numero')->with('lignes')->get(), ...AvoirFournisseur::query()->whereNotNull('numero')->with('lignes')->get()] as $document) {
            $this->assertSame($centimes($document->total_ht), $document->lignes->sum(fn (LigneAchat|LigneAvoirFournisseur $ligne): int => $centimes($ligne->montant_ht)), "{$document->numero} : HT différent de ses lignes.");
            $this->assertSame($centimes($document->total_tva), $document->lignes->sum(fn (LigneAchat|LigneAvoirFournisseur $ligne): int => $centimes($ligne->montant_tva)), "{$document->numero} : TVA différente de ses lignes.");
            $this->assertSame($centimes($document->total_ht) + $centimes($document->total_tva), $centimes($document->total_ttc), "{$document->numero} : TTC différent de HT + TVA.");
            $this->assertGreaterThanOrEqual(0, $centimes($document->total_tva), "{$document->numero} : TVA négative.");
        }

        foreach (LigneAvoirFournisseur::query()->with('ligneAchat')->get()->groupBy('ligne_achat_id') as $retours) {
            $achetee = $retours->first()->ligneAchat;
            $this->assertLessThanOrEqual($achetee->quantite, $retours->sum('quantite'), 'Un avoir fournisseur retourne plus d\'articles que la ligne n\'en a reçu.');
            $this->assertLessThanOrEqual($centimes($achetee->montant_ht), $retours->sum(fn (LigneAvoirFournisseur $ligne): int => $centimes($ligne->montant_ht)), 'Un avoir fournisseur retourne plus de HT que la ligne.');
            $this->assertLessThanOrEqual($centimes($achetee->montant_tva), $retours->sum(fn (LigneAvoirFournisseur $ligne): int => $centimes($ligne->montant_tva)), 'Un avoir fournisseur retourne plus de TVA que la ligne.');
        }

        foreach ($this->produits as $produit) {
            $produit = $produit->fresh();
            $mouvements = MouvementStock::query()->where('produit_id', $produit->getKey())->get();
            $this->assertSame($mouvements->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite)), (int) $produit->stock_quantite, "Le stock de {$produit->nom} n'est pas la somme de ses mouvements.");

            $recu = (int) LigneAchat::query()->where('produit_id', $produit->getKey())->whereHas('factureAchat', fn ($factures) => $factures->whereNotNull('numero'))->sum('quantite');
            $retourne = (int) LigneAvoirFournisseur::query()->whereHas('ligneAchat', fn ($lignes) => $lignes->where('produit_id', $produit->getKey()))->sum('quantite');
            $vendu = (int) $mouvements->filter(fn (MouvementStock $mouvement): bool => $mouvement->type->value === 'vente')->sum('quantite');
            $this->assertSame($recu - $retourne - $vendu, (int) $produit->stock_quantite, "Le stock de {$produit->nom} n'est pas ses réceptions moins ses retours et ses ventes.");

            $this->assertGreaterThanOrEqual(0.0, (float) $produit->cmup_ht, "Le coût moyen de {$produit->nom} est négatif.");
            $this->assertGreaterThanOrEqual(0.0, max(0, $produit->stock_quantite) * (float) $produit->cmup_ht, "La valeur du stock de {$produit->nom} descend sous 0 €.");
        }
    }
}
