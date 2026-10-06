<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_reception_dachat_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10);

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    public function test_une_vente_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_un_retour_fournisseur_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourFournisseur, 4);

        $this->assertSame(6, $produit->fresh()->stock_quantite);
    }

    public function test_un_retour_client_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 5]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourClient, 2);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_chaque_mouvement_est_trace_dans_le_ledger(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 5);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);

        $this->assertSame(2, $produit->mouvementsStock()->count());
    }

    public function test_un_ajustement_calcule_le_delta_depuis_la_quantite_cible(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->ajuster($produit, 7, 'Inventaire physique');

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_une_quantite_negative_ou_nulle_est_refusee(): void
    {
        $produit = Produit::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 0);
    }

    public function test_un_produit_sans_suivi_de_stock_refuse_tout_mouvement(): void
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->expectException(\LogicException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 1);
    }
}
