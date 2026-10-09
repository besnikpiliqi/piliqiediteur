<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    public function test_encaisser_une_vente_avec_une_ligne_de_service(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Retouche']);

        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['service_id' => $service->id, 'quantite' => 1, 'prix_unitaire_ttc' => 18, 'taux_tva' => 20]],
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

    /**
     * Une vente offerte (remise de 100 %) est soldée sans rien encaisser :
     * elle n'attend aucun règlement.
     */
    public function test_une_vente_a_zero_euro_est_payee_sans_encaissement(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            [['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 100]],
            ModePaiement::Especes,
        );

        $this->assertSame('0.00', $facture->total_ttc);
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame(0, Paiement::query()->count());
    }

    /**
     * Une vente ne rembourse jamais rien : c'est le rôle d'un avoir.
     */
    public function test_un_pu_ttc_negatif_est_refuse_sans_rien_enregistrer(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        try {
            app(EnregistrerVenteCaisseAction::class)->handle(
                $client,
                [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ttc' => -50]],
                ModePaiement::Especes,
            );
            $this->fail('Un PU TTC négatif aurait dû être refusé.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Le PU TTC d\'une ligne ne peut pas être négatif.', $exception->getMessage());
        }

        $this->assertSame(0, Facture::query()->count());
        $this->assertSame(0, Paiement::query()->count());
    }
}
