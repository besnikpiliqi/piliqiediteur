<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Paiement;
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

        $this->assertSame(2, $produit->fresh()->stock_quantite);
    }

    public function test_encaisser_une_vente_avec_une_ligne_libre_sans_produit(): void
    {
        $client = Client::factory()->create();

        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['description' => 'Retouche', 'nature' => 'service', 'quantite' => 1, 'prix_unitaire_ht' => 15, 'taux_tva' => 20]],
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

    /**
     * Le crédit disponible du client (voir AppliquerAvoirsDisponiblesAction)
     * peut déjà avoir couvert tout ou partie de la vente à l'émission :
     * l'encaissement en espèces/CB ne doit jamais porter sur le total brut
     * de la facture, seulement sur ce qui reste réellement dû -- sans quoi
     * le client paierait deux fois la part déjà créditée.
     */
    public function test_le_credit_disponible_du_client_reduit_le_montant_reellement_encaisse(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 1]],
            ModePaiement::Especes,
        );
        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(60.0, $client->soldeCrediteur());

        // Nouvel achat comptoir du même montant : intégralement couvert par
        // le crédit, sans aucun encaissement en espèces.
        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 1]],
            ModePaiement::Especes,
        );

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame(60.0, $facture->fresh()->montantCrediteParAvoir());
        $this->assertSame(0.0, $facture->fresh()->montantPaye());
        $this->assertSame(1, Paiement::query()->where('client_id', $client->id)->count(), 'Seul le premier achat a été encaissé.');
        $this->assertSame(0.0, $client->soldeCrediteur());
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
