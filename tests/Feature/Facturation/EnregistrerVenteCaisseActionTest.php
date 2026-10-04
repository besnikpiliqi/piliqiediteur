<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Une vente comptoir (caisse) n'est jamais "à régler plus tard" : voir le
 * docblock de EnregistrerVenteCaisseAction.
 */
class EnregistrerVenteCaisseActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_encaisser_une_vente_cree_emet_et_paie_integralement_la_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 2]],
            ModePaiement::Especes,
        );

        $this->assertNotNull($facture->numero);
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame('120.00', $facture->total_ttc); // 2 x 50 HT, TVA 20%
        $this->assertSame(0.0, $facture->fresh()->montantRestant());
    }

    public function test_encaisser_une_vente_decremente_le_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 5]);

        app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 3]],
            ModePaiement::CarteBancaire,
        );

        $this->assertSame('2.000', $produit->fresh()->stock_quantite);
    }

    public function test_encaisser_une_vente_avec_une_ligne_libre_sans_produit(): void
    {
        $client = Client::factory()->create();

        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['description' => 'Retouche', 'quantite' => 1, 'prix_unitaire_ht' => 15, 'taux_tva' => 20]],
            ModePaiement::Especes,
        );

        $this->assertSame('18.00', $facture->total_ttc);
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_impossible_dencaisser_une_vente_sans_lignes(): void
    {
        $client = Client::factory()->create();

        $this->expectException(LogicException::class);
        app(EnregistrerVenteCaisseAction::class)->handle($client, [], ModePaiement::Especes);
    }

    public function test_la_reference_de_paiement_est_enregistree(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);

        app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 1]],
            ModePaiement::CarteBancaire,
            'TRX-0042',
        );

        $this->assertDatabaseHas('paiements', [
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::CarteBancaire->value,
            'reference' => 'TRX-0042',
        ]);
    }
}
