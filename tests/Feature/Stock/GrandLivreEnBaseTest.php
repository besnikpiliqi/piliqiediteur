<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Support\GardesDuStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le grand livre du stock et le stock de chaque produit, tenus par la base
 * elle-même (voir GardesDuStock), quel que soit le code qui écrit : le
 * grand livre ne se réécrit, ne se supprime ni ne se vide (C08) ; le stock
 * d'un produit ne change que par l'insertion d'un mouvement, qui l'y
 * ajoute aussitôt (C10) ; un mouvement porte une quantité strictement
 * positive, un type connu et un coût positif là où son type le porte, et
 * une ligne de document au moins un article (C12).
 */
class GrandLivreEnBaseTest extends TestCase
{
    use RefreshDatabase;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());
        $this->fournisseur = Fournisseur::factory()->create();
    }

    /**
     * Un produit suivi en stock reçu par une facture d'achat validée de
     * $quantite articles à 2,00 € HT : son seul mouvement est sa réception.
     */
    private function produitRecu(int $quantite = 10): Produit
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);
        $this->receptionner($produit, $quantite);

        return $produit->fresh() ?? $produit;
    }

    private function receptionner(Produit $produit, int $quantite): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $this->fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => 2],
        ], ['date_facture' => '2026-10-08']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function ligne(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    /**
     * @return array<string, array{0: string, 1: Closure(): mixed}>
     */
    public static function reecrituresDUnMouvement(): array
    {
        return [
            'quantité' => ['quantite', static fn (): int => 999],
            'type' => ['type', static fn (): string => TypeMouvementStock::Vente->value],
            'produit' => ['produit_id', static fn (): int => Produit::factory()->create()->id],
            'magasin' => ['magasin_id', static fn (): int => Magasin::factory()->create()->id],
            'instant' => ['survenu_le', static fn (): string => self::enUtc('2020-01-01 00:00:00')->format('Y-m-d H:i:s')],
            'coût' => ['cout_unitaire_ht', static fn (): string => '0.0100'],
            'nom figé de l\'auteur' => ['cree_par_nom', static fn (): string => 'Quelqu\'un d\'autre'],
        ];
    }

    /**
     * C08 : un mouvement du grand livre ne se réécrit, ne se supprime ni ne
     * se vide par une requête directe, et la ligne relue est identique. Les
     * contrôles différés de l'achat sont joués avant le vidage, comme à la
     * validation de sa transaction : PostgreSQL ne vide pas une table qui
     * attend encore un contrôle différé.
     *
     * @param  Closure(): mixed  $valeur
     */
    #[DataProvider('reecrituresDUnMouvement')]
    public function test_la_base_refuse_de_reecrire_ou_de_supprimer_un_mouvement_du_grand_livre(string $colonne, Closure $valeur): void
    {
        $mouvement = MouvementStock::query()->where('produit_id', $this->produitRecu()->id)->sole();
        $nouvelleValeur = $valeur();
        $avant = self::ligne('mouvements_stock', $mouvement->id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('mouvements_stock')->where('id', $mouvement->id)->update([$colonne => $nouvelleValeur])), QueryException::class, 'mouvements_stock : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('mouvements_stock')->where('id', $mouvement->id)->delete()), QueryException::class, 'mouvements_stock : table en ajout seul, suppression refusée');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('mouvements_stock')->truncate()), QueryException::class, 'mouvements_stock : table en ajout seul, vidage refusé');

        $this->assertSame($avant, self::ligne('mouvements_stock', $mouvement->id));
    }

    /**
     * C10 : le stock d'un produit ne se réécrit pas sans mouvement, ni avec
     * un dernier mouvement déjà compté, celui d'un autre produit ou aucun ;
     * chaque mouvement qu'inscrit l'application, lui, y est ajouté par la
     * base, et le stock reste la somme signée du grand livre.
     */
    public function test_le_stock_d_un_produit_ne_change_que_par_un_mouvement_du_grand_livre(): void
    {
        $produit = $this->produitRecu();
        $autre = $this->produitRecu(4);
        $compte = (int) $produit->getAttribute('dernier_mouvement_stock_id');
        $avant = self::ligne('produits', $produit->id);
        $reecrire = fn (array $colonnes): Closure => $this->dansUnPointDeSauvegarde(fn () => DB::table('produits')->where('id', $produit->id)->update($colonnes));

        $this->assertSame([10, MouvementStock::query()->where('produit_id', $produit->id)->sole()->id], [$produit->stock_quantite, $compte]);
        foreach ([
            ['stock_quantite' => 999],
            ['stock_quantite' => 999, 'dernier_mouvement_stock_id' => $compte],
            ['stock_quantite' => 14, 'dernier_mouvement_stock_id' => $autre->getAttribute('dernier_mouvement_stock_id')],
            ['stock_quantite' => 0, 'dernier_mouvement_stock_id' => null],
        ] as $colonnes) {
            $this->assertThrows($reecrire($colonnes), QueryException::class, GardesDuStock::REFUS_STOCK_SANS_MOUVEMENT);
        }
        $this->assertSame($avant, self::ligne('produits', $produit->id));

        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3]]);

        $variations = MouvementStock::query()->where('produit_id', $produit->id)->orderBy('id')->get()->map(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite));
        $this->assertSame([10, -3], $variations->all());
        $this->assertSame([7, MouvementStock::query()->where('produit_id', $produit->id)->max('id')], [$produit->fresh()?->stock_quantite, $produit->fresh()?->getAttribute('dernier_mouvement_stock_id')]);
    }

    /**
     * Un mouvement inséré directement, malformé : chacun refusé par sa
     * contrainte, grand livre et stock inchangés.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function mouvementsMalFormes(): array
    {
        return [
            'quantité nulle' => [['quantite' => 0], 'mouvements_stock_quantite_positive'],
            'quantité négative' => [['quantite' => -5], 'mouvements_stock_quantite_positive'],
            'sortie négative' => [['type' => TypeMouvementStock::Vente->value, 'quantite' => -3], 'mouvements_stock_quantite_positive'],
            'coût négatif' => [['type' => TypeMouvementStock::InventaireOuverture->value, 'cout_unitaire_ht' => '-1.0000'], 'mouvements_stock_cout_positif'],
            'type inconnu' => [['type' => 'cadeau_de_stock'], 'mouvements_stock_type_connu'],
            'coût absent d\'un retour au fournisseur' => [['type' => TypeMouvementStock::RetourFournisseur->value, 'cout_unitaire_ht' => null], 'mouvements_stock_cout_selon_le_type'],
            'coût sur une vente' => [['type' => TypeMouvementStock::Vente->value, 'cout_unitaire_ht' => '2.0000'], 'mouvements_stock_cout_selon_le_type'],
        ];
    }

    /**
     * C12 : la base refuse un mouvement à quantité nulle ou négative, à
     * coût négatif, d'un type inconnu, ou au coût absent ou présent à tort
     * selon son type ; rien ne change.
     *
     * @param  array<string, mixed>  $colonnes
     */
    #[DataProvider('mouvementsMalFormes')]
    public function test_la_base_refuse_un_mouvement_mal_forme(array $colonnes, string $contrainte): void
    {
        $produit = $this->produitRecu();
        $reception = MouvementStock::query()->where('produit_id', $produit->id)->sole();
        $avant = $this->instantaneDesTables('mouvements_stock', 'produits');
        // La garde de la provenance, qui passe avant les contraintes de la
        // table, a ses propres tests (voir ProvenanceDuStockTest) : levée
        // ici, le temps de la transaction du test, pour éprouver chaque
        // contrainte seule.
        $this->retirerLeDeclencheur('mouvements_stock', 'mouvements_stock_justifies');

        try {
            $this->dansUnPointDeSauvegarde(fn () => DB::table('mouvements_stock')->insert([...Arr::except(self::ligne('mouvements_stock', $reception->id), ['id']), ...$colonnes]))();
            $this->fail("Le mouvement mal formé est accepté ({$contrainte}).");
        } catch (QueryException $refus) {
            $this->assertSame('23514', $refus->errorInfo[0] ?? null, $refus->getMessage());
            $this->assertStringContainsString("\"{$contrainte}\"", (string) ($refus->errorInfo[2] ?? ''));
        }

        $this->assertSame($avant, $this->instantaneDesTables('mouvements_stock', 'produits'));
    }

    /**
     * C12 : une ligne de facture, de devis, d'avoir, d'achat, d'avoir
     * fournisseur ou d'inventaire d'ouverture porte au moins un article :
     * ni insérée ni réécrite à 0 ou en négatif, sur un brouillon, que
     * rien d'autre ne garde.
     */
    public function test_une_ligne_de_document_porte_au_moins_un_article(): void
    {
        $produit = $this->produitRecu();
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 3, 'cout_unitaire_ht' => '2.00']]);
        $client = Client::factory()->create();
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]]);
        $achat = $this->receptionner($produit, 5);
        $lignes = [
            'lignes_facture' => $facture->lignes->sole()->id,
            'lignes_devis' => app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]])->lignes->sole()->id,
            'lignes_avoir' => app(CreerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]])->lignes->sole()->id,
            'lignes_achat' => app(CreerFactureAchatAction::class)->handle(self::auteur(), $this->fournisseur, [['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 2]], ['date_facture' => '2026-10-08'])->lignes->sole()->id,
            'lignes_avoir_fournisseur' => app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $achat, [['ligne_achat_id' => $achat->lignes->sole()->id, 'quantite' => 1]], 'Abîmé')->lignes->sole()->id,
            'lignes_inventaire_ouverture' => $inventaire->lignes->sole()->id,
        ];
        $avant = $this->instantaneDesTables(...array_keys($lignes));

        foreach ($lignes as $table => $id) {
            foreach ([0, -2] as $quantite) {
                foreach ([
                    fn (): int => DB::table($table)->where('id', $id)->update(['quantite' => $quantite]),
                    fn (): bool => DB::table($table)->insert([...Arr::except(self::ligne($table, $id), ['id']), 'quantite' => $quantite]),
                ] as $ecriture) {
                    try {
                        $this->dansUnPointDeSauvegarde($ecriture)();
                        $this->fail("{$table} accepte une ligne de {$quantite} article(s).");
                    } catch (QueryException $refus) {
                        $this->assertSame('23514', $refus->errorInfo[0] ?? null, $refus->getMessage());
                        $this->assertStringContainsString("\"{$table}_quantite_au_moins_1\"", (string) ($refus->errorInfo[2] ?? ''));
                    }
                }
            }
        }

        $this->assertSame($avant, $this->instantaneDesTables(...array_keys($lignes)));
    }

    /**
     * C19 : une ligne d'un achat validé ne se réécrit pas sans corriger le
     * stock : la base la refuse à la validation de la transaction (le test
     * force ce contrôle différé, que sa transaction n'atteint jamais, puis
     * le diffère de nouveau) ; la correction de l'achat par son action, qui
     * écrit la ligne et l'ajustement du stock ensemble, passe.
     */
    public function test_une_ligne_d_un_achat_valide_ne_se_reecrit_pas_sans_son_stock(): void
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);
        $achat = $this->receptionner($produit, 5);
        $ligne = $achat->lignes->sole();
        $mode = fn (string $mode): bool => DB::statement('SET CONSTRAINTS '.GardesDuStock::COHERENCE_DES_LIGNES.', '.GardesDuStock::COHERENCE_DES_MOUVEMENTS." {$mode}");
        $controler = fn (): bool => $mode('IMMEDIATE');
        $this->assertTrue($controler());

        $this->assertThrows($this->dansUnPointDeSauvegarde(function () use ($ligne, $controler): void {
            DB::table('lignes_achat')->where('id', $ligne->id)->update(['quantite' => 50]);
            $controler();
        }), QueryException::class, GardesDuStock::REFUS_ACHAT_SANS_SON_STOCK);
        $this->assertSame([5, 5], [(int) DB::table('lignes_achat')->where('id', $ligne->id)->value('quantite'), $produit->fresh()?->stock_quantite]);
        $mode('DEFERRED');

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat->fresh() ?? $achat, $this->fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertTrue($controler());
        $this->assertSame(8, $produit->fresh()?->stock_quantite);
    }
}
