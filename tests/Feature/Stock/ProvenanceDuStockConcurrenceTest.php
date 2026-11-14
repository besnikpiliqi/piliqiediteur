<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Stock\Support\GardesDuStock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C94 : deux mouvements au même instant sur la même vente, chacun dans
 * son processus, sur une vraie base PostgreSQL. La garde de la provenance
 * (voir GardesDuStock::poserLaProvenance()) verrouille d'abord la ligne du
 * produit : le second mouvement attend le premier, puis le voit, et la
 * vente ne fait jamais sortir plus que sa ligne de 5 articles.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé ; les contrôles différés (auteur du mouvement)
 * jouent donc aussi.
 */
class ProvenanceDuStockConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le 09/10/2026 à 10 h, heure de Paris : un ticket de 5 carnets émis
     * par le responsable de test quand le carnet ne suivait pas son stock
     * (aucun mouvement), qui le suit désormais, 10 en stock.
     *
     * @return array{0: User, 1: Produit, 2: Facture}
     */
    private function venteDeCinqCarnets(): array
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::auteur();
        $carnet = Produit::factory()->create(['reference' => 'CARNET', 'nom' => 'Carnet', 'prix_vente_ttc' => 6, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);
        $carnet->update(['suivi_stock' => false]);
        $vente = self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $carnet->id, 'quantite' => 5]]);
        $carnet->update(['suivi_stock' => true]);

        return [$responsable, $carnet->fresh() ?? $carnet, $vente];
    }

    /**
     * Le mouvement de vente que le processus concurrent insère directement,
     * comme un administrateur de la base, au nom de l'émetteur, à
     * l'instant figé du test.
     */
    private function lancerUnMouvementConcurrent(Produit $carnet, Facture $vente, User $responsable, int $quantite, ?InvokedProcess &$concurrente): void
    {
        $mouvement = [
            'magasin_id' => $carnet->magasin_id,
            'produit_id' => $carnet->id,
            'type' => TypeMouvementStock::Vente->value,
            'quantite' => $quantite,
            'source_type' => $vente->getMorphClass(),
            'source_id' => $vente->id,
            'survenu_le' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'cree_par' => $responsable->id,
            'cree_par_nom' => $responsable->name,
        ];

        $this->concurrencerDesQueLaLigneEstVerrouillee('produits', 'for update', static function () use ($mouvement): void {
            DB::table('mouvements_stock')->insert($mouvement);
        }, $concurrente);
    }

    private function inscrireLaVente(Produit $carnet, Facture $vente, User $responsable, int $quantite): void
    {
        $this->commeAuteurDuDocument(fn () => DB::transaction(fn () => app(StockLedgerService::class)->enregistrer($carnet, TypeMouvementStock::Vente, $quantite, $vente)), $responsable);
    }

    public function test_un_second_mouvement_au_dela_de_la_ligne_attend_le_premier_puis_est_refuse(): void
    {
        [$responsable, $carnet, $vente] = $this->venteDeCinqCarnets();
        $concurrente = null;
        $this->lancerUnMouvementConcurrent($carnet, $vente, $responsable, 3, $concurrente);

        $this->inscrireLaVente($carnet, $vente, $responsable, 3);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT, $resultat->errorOutput());
        $this->assertStringContainsString('P0001', $resultat->errorOutput());
        $this->assertSame([[3], 7], [MouvementStock::query()->where('type', TypeMouvementStock::Vente->value)->pluck('quantite')->all(), $carnet->fresh()?->stock_quantite]);
    }

    public function test_deux_mouvements_dans_la_ligne_passent_l_un_apres_l_autre(): void
    {
        [$responsable, $carnet, $vente] = $this->venteDeCinqCarnets();
        $concurrente = null;
        $this->lancerUnMouvementConcurrent($carnet, $vente, $responsable, 3, $concurrente);

        $this->inscrireLaVente($carnet, $vente, $responsable, 2);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame([[2, 3], 5], [MouvementStock::query()->where('type', TypeMouvementStock::Vente->value)->pluck('quantite')->sort()->values()->all(), $carnet->fresh()?->stock_quantite]);
    }
}
