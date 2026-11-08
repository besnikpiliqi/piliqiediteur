<?php

namespace Tests\Feature\Proprietes;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Des ventes tirées au hasard (décision de l'éditeur du 01/11/2026, point
 * 5 bis) : tickets à la caisse et factures, à un particulier, au client
 * comptoir ou à un professionnel, des lignes de produits à quatre taux, en
 * quantités, prix saisis et remises variés, réglées en un ou plusieurs
 * modes, en tout ou en partie, puis encaissées, corrigées par des avoirs
 * partiels et des contre-passations, dans tous les ordres, et la journée
 * clôturée. Après chaque cas, ce qui doit toujours tenir tient :
 *
 * - chaque document totalise exactement ses lignes, et la TVA de chaque
 *   ligne vaut son TTC moins son HT, jamais négative ;
 * - un avoir ne rend jamais plus qu'une ligne, en quantité, en HT ni en
 *   TVA, ni plus que la facture ;
 * - chaque paiement vaut le total qu'il impute, et rien n'est réglé
 *   au-delà de ce qui est dû ;
 * - le stock de chaque produit est son stock de départ, moins ce qui est
 *   vendu, plus ce qui est rendu ;
 * - la clôture du jour égale la somme de ses tickets moins celle de leurs
 *   avoirs, et ses encaissements la somme des paiements du jour ;
 * - chaque document émis reste signé et inaltéré (vérificateur
 *   d'intégrité sans anomalie).
 */
class VentesAuHasardTest extends TestCase
{
    use RefreshDatabase, VerifieDesProprietes;

    private const GRAINE = 20261107;

    private const NOMBRE_DE_CAS = 120;

    private const STOCK_DE_DEPART = 100000;

    private Magasin $magasin;

    private User $responsable;

    /** @var list<Produit> */
    private array $produits;

    /** @var array<string, Client> */
    private array $clients;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-14 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $this->responsable = self::responsableDeTest($this->magasin);
        $this->actingAs($this->responsable);
        $this->produits = array_map(
            fn (array $produit): Produit => Produit::factory()->create(['prix_vente_ttc' => $produit[0], 'taux_tva' => $produit[1], 'suivi_stock' => true, 'stock_quantite' => self::STOCK_DE_DEPART]),
            [['12.50', 20], ['0.99', 5.5], ['149.90', 10], ['3.33', 2.1]],
        );
        $this->clients = [
            'comptoir' => Client::query()->where('est_comptoir', true)->sole(),
            'particulier' => Client::factory()->create(),
            'professionnel' => Client::factory()->professionnel()->create(),
        ];
    }

    public function test_des_ventes_avoirs_paiements_et_contre_passations_au_hasard_gardent_leurs_regles(): void
    {
        $this->pourChaqueCas(
            self::GRAINE,
            self::NOMBRE_DE_CAS,
            fn (Tirage $tirage): array => array_map(fn (): array => $this->etape($tirage), range(1, $tirage->entier(1, 7))),
            function (array $etapes): void {
                foreach ($etapes as $etape) {
                    $this->jouer($etape);
                }

                app(CloturerCaisseAction::class)->handle($this->responsable, $this->magasin, '2026-10-14');
                $this->verifierLesRegles();
            },
            fn (array $etapes): iterable => self::etapesReduites($etapes, self::reduireUneEtape(...)),
        );
    }

    /**
     * Une étape tirée au hasard.
     *
     * @return array<string, mixed>
     */
    private function etape(Tirage $tirage): array
    {
        $lignes = fn (): array => array_map(fn (): array => [
            'produit' => $tirage->entier(0, count($this->produits) - 1),
            'quantite' => $tirage->uneFoisSur(4) ? $tirage->entier(2, 40) : 1,
            'prix' => $tirage->uneFoisSur(3) ? $tirage->entier(1, 99999) : null,
            'remise' => $tirage->uneFoisSur(3) ? $tirage->entier(1, 9999) : 0,
        ], range(1, $tirage->entier(1, 5)));

        return match ($tirage->parmi(['caisse', 'caisse', 'facture', 'facture', 'paiement', 'avoir', 'contre_passation'])) {
            'caisse' => ['geste' => 'caisse', 'client' => $tirage->parmi(['comptoir', 'particulier']), 'lignes' => $lignes(), 'modes' => $tirage->entier(1, 4)],
            'facture' => ['geste' => 'facture', 'client' => $tirage->parmi(['particulier', 'professionnel']), 'lignes' => $lignes(), 'encaisse' => $tirage->parmi([0, 0, 100, $tirage->entier(1, 99)]), 'modes' => $tirage->entier(1, 4)],
            'paiement' => ['geste' => 'paiement', 'document' => $tirage->entier(0, 9), 'pourcent' => $tirage->parmi([100, $tirage->entier(1, 99)]), 'mode' => $tirage->entier(0, 4)],
            'avoir' => ['geste' => 'avoir', 'document' => $tirage->entier(0, 9), 'pourcents' => array_map(fn (): int => $tirage->entier(0, 100), range(1, 5))],
            'contre_passation' => ['geste' => 'contre_passation', 'paiement' => $tirage->entier(0, 9)],
        };
    }

    /**
     * Les étapes plus petites : moins de lignes, une quantité de 1, sans
     * prix saisi ni remise, un seul mode.
     *
     * @param  array<string, mixed>  $etape
     * @return iterable<array<string, mixed>>
     */
    private static function reduireUneEtape(array $etape): iterable
    {
        foreach (array_keys($etape['lignes'] ?? []) as $rang) {
            if (count($etape['lignes']) > 1) {
                yield [...$etape, 'lignes' => array_values(array_diff_key($etape['lignes'], [$rang => true]))];
            }

            foreach (['quantite' => 1, 'prix' => null, 'remise' => 0] as $champ => $simple) {
                if ($etape['lignes'][$rang][$champ] !== $simple) {
                    $lignes = $etape['lignes'];
                    $lignes[$rang][$champ] = $simple;

                    yield [...$etape, 'lignes' => $lignes];
                }
            }
        }

        if (($etape['modes'] ?? 1) > 1) {
            yield [...$etape, 'modes' => 1];
        }
    }

    /**
     * @param  array<string, mixed>  $etape
     */
    private function jouer(array $etape): void
    {
        $calculateur = app(CalculateurLigne::class);
        $lignes = array_map(fn (array $ligne): array => [
            'produit_id' => $this->produits[$ligne['produit']]->getKey(),
            'quantite' => $ligne['quantite'],
            ...($ligne['prix'] === null ? [] : ['prix_unitaire_ttc' => $calculateur->enDecimal($ligne['prix'])]),
            ...($ligne['remise'] === 0 ? [] : ['remise_pourcentage' => $calculateur->enDecimal($ligne['remise'])]),
        ], $etape['lignes'] ?? []);

        match ($etape['geste']) {
            'caisse' => app(EnregistrerVenteCaisseAction::class)->handle($this->responsable, $this->clients[$etape['client']], $lignes, new ReglementComptant(self::parts(self::resteAPayerAuComptant($this->clients[$etape['client']], $lignes), $etape['modes']))),
            'facture' => $this->facturer($this->clients[$etape['client']], $lignes, $etape['encaisse'], $etape['modes']),
            'paiement' => $this->encaisser($etape['document'], $etape['pourcent'], $etape['mode']),
            'avoir' => $this->crediter($etape['document'], $etape['pourcents']),
            'contre_passation' => $this->contrePasser($etape['paiement']),
        };
    }

    /**
     * Les parts d'un règlement de ce montant, en centimes, découpé entre
     * les premiers modes de la caisse ; rien à payer, aucune part.
     *
     * @return list<PartDeReglement>
     */
    private static function parts(int $montant, int $modes): array
    {
        if ($montant <= 0) {
            return [];
        }

        $parts = (new Tirage($montant + $modes))->decouper($montant, $modes);

        return array_map(
            fn (int $part, ModePaiement $mode): PartDeReglement => new PartDeReglement($mode, $part, $mode === ModePaiement::Cheque ? 'CHQ-1' : null),
            $parts,
            array_slice(ModePaiement::aLaCaisse(), 0, count($parts)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function facturer(Client $client, array $lignes, int $pourcentEncaisse, int $modes): void
    {
        $facture = app(CreerFactureAction::class)->handle($this->responsable, $client, $lignes);
        $encaisse = intdiv(ApercuDuReglement::aLEmission($facture)->resteARepartir * $pourcentEncaisse, 100);

        app(EmettreFactureAction::class)->handle($this->responsable, $facture, $encaisse > 0 ? ReglementComptant::horsDeLaCaisse(self::parts($encaisse, $modes)) : null);
    }

    /**
     * Encaisse une partie de ce qui reste dû sur l'une des ventes qui
     * attendent un règlement, d'un mode ; aucune, rien.
     */
    private function encaisser(int $rang, int $pourcent, int $mode): void
    {
        $attendent = Facture::query()->whereNotNull('numero')->with(['client', 'allocationsPaiement', 'allocationsAvoir'])->orderBy('id')->get()
            ->filter(fn (Facture $facture): bool => $facture->resteDuEnCentimes() > 0)
            ->values();

        if ($attendent->isEmpty()) {
            return;
        }

        $facture = $attendent[$rang % $attendent->count()];
        $calculateur = app(CalculateurLigne::class);
        $montant = max(1, intdiv($facture->resteDuEnCentimes() * $pourcent, 100));
        $modes = [...ModePaiement::aLaCaisse(), ModePaiement::Prelevement];

        app(EnregistrerPaiementAction::class)->handle($this->responsable, $facture->client, [
            'mode_paiement' => $modes[$mode]->value,
            'montant' => $calculateur->enDecimal($montant),
            'reference' => $modes[$mode] === ModePaiement::Cheque ? 'CHQ-2' : null,
            'recu_le' => '2026-10-14',
        ], [['facture_id' => $facture->getKey(), 'montant' => $calculateur->enDecimal($montant)]]);
    }

    /**
     * Un avoir sur l'une des ventes émises, pour une part de ce qui reste
     * créditable de chacune de ses lignes ; rien de créditable, rien.
     *
     * @param  list<int>  $pourcents
     */
    private function crediter(int $rang, array $pourcents): void
    {
        $emises = Facture::query()->whereNotNull('numero')->with(['client', 'lignes'])->orderBy('id')->get();

        if ($emises->isEmpty()) {
            return;
        }

        $facture = $emises[$rang % $emises->count()];
        $selection = [];

        foreach ($facture->lignes->values() as $rangDeLigne => $ligne) {
            $restante = $ligne->quantiteCreditableRestante();
            $quantite = intdiv($restante * $pourcents[$rangDeLigne % count($pourcents)], 100);

            if ($quantite > 0) {
                $selection[] = ['ligne_facture_id' => $ligne->getKey(), 'quantite' => $quantite];
            }
        }

        if ($selection !== []) {
            app(EnregistrerAvoirAction::class)->handle($this->responsable, $facture->client, $selection, 'Retour tiré au hasard');
        }
    }

    /**
     * Contre-passe l'un des paiements qui peuvent l'être ; aucun, rien.
     */
    private function contrePasser(int $rang): void
    {
        $paiements = Paiement::query()->where('montant', '>', 0)->whereDoesntHave('contrePassation')->orderBy('id')->get();

        if ($paiements->isNotEmpty()) {
            app(ContrePasserPaiementAction::class)->handle($this->responsable, $paiements[$rang % $paiements->count()], 'Saisi par erreur');
        }
    }

    private function verifierLesRegles(): void
    {
        $calculateur = app(CalculateurLigne::class);
        $centimes = fn (mixed $montant): int => $calculateur->enCentiemes($montant);
        $factures = Facture::query()->whereNotNull('numero')->with(['lignes', 'allocationsPaiement', 'allocationsAvoir'])->get();
        $avoirs = Avoir::query()->whereNotNull('numero')->with('lignes.ligneFacture')->get();

        foreach ([...$factures, ...$avoirs] as $document) {
            $lignes = $document->lignes;
            $this->assertSame($centimes($document->total_ttc), $lignes->sum(fn (LigneFacture|LigneAvoir $ligne): int => $centimes($ligne->montant_ttc)), "{$document->numero} : TTC différent de ses lignes.");
            $this->assertSame($centimes($document->total_ht), $lignes->sum(fn (LigneFacture|LigneAvoir $ligne): int => $centimes($ligne->montant_ht)), "{$document->numero} : HT différent de ses lignes.");
            $this->assertSame($centimes($document->total_tva), $lignes->sum(fn (LigneFacture|LigneAvoir $ligne): int => $centimes($ligne->montant_tva)), "{$document->numero} : TVA différente de ses lignes.");

            foreach ($lignes as $ligne) {
                $this->assertSame($centimes($ligne->montant_ttc) - $centimes($ligne->montant_ht), $centimes($ligne->montant_tva), "{$document->numero} : TVA d'une ligne différente de son TTC moins son HT.");
                $this->assertGreaterThanOrEqual(0, $centimes($ligne->montant_tva), "{$document->numero} : TVA négative.");
            }
        }

        foreach ($avoirs->flatMap->lignes->groupBy('ligne_facture_id') as $rendus) {
            $vendue = $rendus->first()->ligneFacture;
            $this->assertLessThanOrEqual($vendue->quantite, $rendus->sum('quantite'), 'Un avoir rend plus d\'articles que la ligne.');
            $this->assertLessThanOrEqual($centimes($vendue->montant_ht), $rendus->sum(fn (LigneAvoir $ligne): int => $centimes($ligne->montant_ht)), 'Un avoir rend plus de HT que la ligne.');
            $this->assertLessThanOrEqual($centimes($vendue->montant_tva), $rendus->sum(fn (LigneAvoir $ligne): int => $centimes($ligne->montant_tva)), 'Un avoir rend plus de TVA que la ligne.');
        }

        foreach (Paiement::query()->with('allocations')->get() as $paiement) {
            $this->assertSame($centimes($paiement->montant), $paiement->allocations->sum(fn ($allocation): int => $centimes($allocation->montant_alloue)), 'Un paiement ne vaut pas le total qu\'il impute.');
        }

        foreach ($factures as $facture) {
            $this->assertGreaterThanOrEqual(0, $facture->resteDuEnCentimes(), "{$facture->numero} : réglée au-delà de ce qu'elle doit.");
        }

        foreach ($this->produits as $produit) {
            $vendu = (int) LigneFacture::query()->whereIn('facture_id', $factures->modelKeys())->where('produit_id', $produit->getKey())->sum('quantite');
            $rendu = (int) LigneAvoir::query()->whereIn('avoir_id', $avoirs->modelKeys())->whereHas('ligneFacture', fn ($lignes) => $lignes->where('produit_id', $produit->getKey()))->sum('quantite');
            $this->assertSame(self::STOCK_DE_DEPART - $vendu + $rendu, (int) $produit->fresh()->stock_quantite, "Stock de {$produit->nom} incohérent.");
        }

        $cloture = ClotureCaisse::query()->sole();
        $recapitulatif = $cloture->recapitulatif;
        $tickets = $factures->filter(fn (Facture $facture): bool => ! $facture->estPourUnProfessionnel());
        $avoirsDeTickets = $avoirs->filter(fn (Avoir $avoir): bool => ! $avoir->estPourUnProfessionnel());
        $this->assertSame($tickets->sum(fn (Facture $facture): int => $centimes($facture->total_ttc)), $recapitulatif['ventes_particuliers']['ttc'], 'Les ventes de la clôture ne sont pas la somme de ses tickets.');
        $this->assertSame(-$avoirsDeTickets->sum(fn (Avoir $avoir): int => $centimes($avoir->total_ttc)), $recapitulatif['avoirs_particuliers']['ttc'], 'Les avoirs de la clôture ne sont pas, en déduction, la somme des avoirs de ses tickets.');
        $this->assertSame($recapitulatif['ventes_particuliers']['ttc'] + $recapitulatif['avoirs_particuliers']['ttc'], $centimes($cloture->total_ttc), 'Le net de la clôture n\'est pas ses ventes moins ses avoirs.');
        $this->assertSame((int) Paiement::query()->get()->sum(fn (Paiement $paiement): int => $centimes($paiement->montant)), $cloture->totalEncaisseEnCentimes(), 'Les encaissements de la clôture ne sont pas la somme des paiements du jour.');

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($this->magasin)->anomalies(), 'Le vérificateur d\'intégrité trouve une anomalie.');
    }
}
