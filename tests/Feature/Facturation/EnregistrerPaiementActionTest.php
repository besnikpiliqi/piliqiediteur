<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
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

    /**
     * B3 : un trop-perçu ne devient jamais un crédit, le montant reçu est
     * exactement le total imputé sur les factures.
     */
    public function test_un_montant_recu_superieur_au_total_impute_est_refuse(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120
        $registreAvant = EnregistrementFiscal::query()->count();

        $this->assertThrows(
            fn () => app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => '200.00',
                'recu_le' => now()->toDateString(),
            ], [['facture_id' => $facture->id, 'montant' => '120.00']]),
            LogicException::class,
            'Le montant reçu (200,00 €) doit être égal au total imputé sur les factures (120,00 €).',
        );

        $this->assertSame([0, 0, $registreAvant], [Paiement::query()->count(), PaiementAllocation::query()->count(), EnregistrementFiscal::query()->count()]);
        $this->assertSame([StatutFacture::Emise, 120.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
    }

    public function test_un_montant_recu_egal_au_total_impute_sur_deux_factures_les_regle_toutes_les_deux(): void
    {
        $client = Client::factory()->create();
        $premiere = $this->emettreFactureDe($client, 100); // total TTC 120
        $seconde = $this->emettreFactureDe($client, 50 / 1.2); // total TTC 50

        $paiement = app(EnregistrerPaiementAction::class)->handle($client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => '170.00',
            'recu_le' => now()->toDateString(),
        ], [
            ['facture_id' => $premiere->id, 'montant' => '120.00'],
            ['facture_id' => $seconde->id, 'montant' => '50.00'],
        ]);

        $this->assertSame('170.00', $paiement->montant);
        $this->assertSame([StatutFacture::Payee, 0.0], [$premiere->fresh()->statut, $premiere->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::Payee, 0.0], [$seconde->fresh()->statut, $seconde->fresh()->montantRestant()]);
    }

    public function test_un_paiement_sans_aucune_facture_est_refuse(): void
    {
        $client = Client::factory()->create();

        $this->assertThrows(
            fn () => app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => '50.00',
                'recu_le' => now()->toDateString(),
            ], []),
            LogicException::class,
            'Un paiement règle au moins une facture.',
        );

        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_un_montant_recu_negatif_est_refuse(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        $this->assertThrows(
            fn () => app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => '-20.00',
                'recu_le' => now()->toDateString(),
            ], [['facture_id' => $facture->id, 'montant' => '20.00']]),
            LogicException::class,
            'Le montant reçu doit être strictement positif.',
        );

        $this->assertSame([0, StatutFacture::Emise], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    public function test_une_facture_imputee_deux_fois_dans_le_meme_paiement_est_refusee(): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDe($client, 100); // total TTC 120

        $this->assertThrows(
            fn () => app(EnregistrerPaiementAction::class)->handle($client, [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => '100.00',
                'recu_le' => now()->toDateString(),
            ], [['facture_id' => $facture->id, 'montant' => '60.00'], ['facture_id' => $facture->id, 'montant' => '40.00']]),
            LogicException::class,
            'Une facture ne s\'impute qu\'une fois par paiement.',
        );

        $this->assertSame([0, StatutFacture::Emise], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    /**
     * Le client comptoir n'a jamais de crédit (voir Client::soldeCrediteur()) :
     * l'avoir d'un ticket anonyme ne s'impute pas sur le paiement d'un autre
     * ticket anonyme.
     */
    public function test_le_paiement_d_un_ticket_du_client_comptoir_n_impute_aucun_credit(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticketRetourne = $this->emettreFactureDe($comptoir, 100); // total TTC 120
        app(EnregistrerPaiementAction::class)->handle($comptoir, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '120.00', 'recu_le' => now()->toDateString()], [['facture_id' => $ticketRetourne->id, 'montant' => '120.00']]);
        $avoir = app(EnregistrerAvoirAction::class)->handle($comptoir, [['ligne_facture_id' => $ticketRetourne->lignes()->sole()->id, 'quantite' => 1]]);
        $ticket = $this->emettreFactureDe($comptoir, 100); // total TTC 120

        $paiement = app(EnregistrerPaiementAction::class)->handle($comptoir, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '10.00', 'recu_le' => now()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '10.00']]);

        $this->assertSame(0, $paiement->creditsImputes()->count());
        $this->assertSame([StatutFacture::PartiellementPayee, 110.0], [$ticket->fresh()->statut, $ticket->fresh()->montantRestant()]);
        $this->assertSame(120.0, $avoir->fresh()->montantNonAlloue());
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
