<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EnregistrerPaiementActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Émet une facture dont le total TTC est exactement déterminé par le
     * prix unitaire HT fourni (taux de TVA fixé à 20 %), pour pouvoir
     * raisonner sur des montants ronds dans les assertions.
     */
    private function emettreFactureDe(Client $client, float $prixUnitaireHt): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_un_paiement_integral_passe_la_facture_au_statut_payee(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        $paiement = app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 120],
        ]);

        $this->assertSame('120.00', (string) $paiement->montant);

        $facture->refresh();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame(0.0, $facture->montantRestant());
        $this->assertSame(120.0, $facture->montantPaye());
    }

    public function test_un_paiement_partiel_passe_la_facture_au_statut_partiellement_payee_puis_payee_au_complement(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 50],
        ]);

        $facture->refresh();
        $this->assertSame(StatutFacture::PartiellementPayee, $facture->statut);
        $this->assertSame(70.0, $facture->montantRestant());

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 70,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 70],
        ]);

        $facture->refresh();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame(0.0, $facture->montantRestant());
    }

    public function test_un_paiement_peut_regler_plusieurs_factures_du_meme_client_a_la_fois(): void
    {
        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDe($client, 100 / 1.2); // total TTC 100
        $factureB = $this->emettreFactureDe($client, 20 / 1.2); // total TTC 20

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $factureA->id, 'montant' => 100],
            ['facture_id' => $factureB->id, 'montant' => 20],
        ]);

        $this->assertSame(StatutFacture::Payee, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::Payee, $factureB->fresh()->statut);
    }

    public function test_impossible_dallouer_plus_que_le_solde_restant_dune_facture(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        $this->expectException(LogicException::class);

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 200,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 200],
        ]);
    }

    public function test_impossible_dallouer_plus_que_le_montant_du_paiement_sur_plusieurs_factures(): void
    {
        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDe($client, 100); // total TTC 120
        $factureB = $this->emettreFactureDe($client, 100); // total TTC 120

        try {
            app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 100,
                'recu_le' => now()->toDateString(),
            ], [
                ['facture_id' => $factureA->id, 'montant' => 60],
                ['facture_id' => $factureB->id, 'montant' => 60],
            ]);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException) {
            // La transaction doit avoir tout annulé : aucune allocation
            // persistée, aucun statut modifié.
            $this->assertSame(0, PaiementAllocation::query()->count());
            $this->assertSame(StatutFacture::Emise, $factureA->fresh()->statut);
            $this->assertSame(StatutFacture::Emise, $factureB->fresh()->statut);
        }
    }

    public function test_impossible_dallouer_un_paiement_a_la_facture_dun_autre_client(): void
    {
        $clientPayeur = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $facture = $this->emettreFactureDe($autreClient, 100);

        $this->expectException(LogicException::class);

        app(EnregistrerPaiementAction::class)->handle($clientPayeur, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 50],
        ]);
    }

    public function test_impossible_dencaisser_une_facture_encore_en_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->expectException(LogicException::class);

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $facture->id, 'montant' => 50],
        ]);
    }

    /**
     * Un paiement vu hier sur le relevé s'enregistre aujourd'hui avec sa
     * vraie date ; aucun ne se reçoit dans le futur, quel que soit l'écran
     * ou l'appel qui l'enregistre.
     */
    public function test_un_paiement_recu_a_une_date_future_est_refuse(): void
    {
        $this->travelTo('2026-10-09 23:30:00');
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        try {
            app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => '2026-10-10',
            ], [['facture_id' => $facture->id, 'montant' => 120]]);
            $this->fail('Un paiement reçu à une date future aurait dû être refusé.');
        } catch (LogicException $exception) {
            $this->assertSame('Un paiement ne peut pas être reçu à une date future.', $exception->getMessage());
        }

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    public function test_un_paiement_recu_a_une_date_passee_est_enregistre(): void
    {
        $this->travelTo('2026-10-09 00:30:00');
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        $paiement = app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 120,
            'recu_le' => '2026-10-08',
        ], [['facture_id' => $facture->id, 'montant' => 120]]);

        $this->assertSame('2026-10-08', $paiement->fresh()->recu_le->toDateString());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    /**
     * Le solde d'une facture en retard la règle entièrement : elle passe
     * payée ; un acompte la laisse en retard (voir
     * MarquerFacturesEnRetardTest).
     */
    public function test_le_solde_d_une_facture_en_retard_la_passe_payee(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120
        $facture->forceFill(['statut' => StatutFacture::EnRetard])->save();

        app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 120]]);

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }
}
