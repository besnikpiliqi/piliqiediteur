<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * À son émission, un avoir réduit d'abord ce qui reste dû des factures
 * qu'il corrige, puis son reste règle les autres factures impayées du
 * client, de la plus ancienne à la plus récente (B6) ; le reliquat reste
 * un crédit. Jamais pour le client comptoir, dont l'avoir vaut bon
 * d'avoir.
 */
class ImputerAvoirSurSesFacturesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
    }

    private function emettreFacture(Client $client, Produit $produit, int $quantite): Facture
    {
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    /**
     * Une facture de 1 article au prix TTC donné, à 20 %, émise ce jour-là
     * à 10 h, à échéance 30 jours plus tard.
     */
    private function factureDu(string $jour, Client $client, float $prixTtc): Facture
    {
        $this->travelTo(self::aParis("{$jour} 10:00:00"));

        return $this->emettreFacture($client, Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]), 1);
    }

    private function encaisser(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    /**
     * @param  list<array{0: Facture, 1: int}>  $retours  facture et nombre d'articles rendus de sa ligne
     */
    private function emettreAvoir(Client $client, array $retours): Avoir
    {
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, array_map(
            fn (array $retour): array => ['ligne_facture_id' => $retour[0]->lignes->firstOrFail()->id, 'quantite' => $retour[1]],
            $retours,
        ));

        return app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);
    }

    /**
     * L'avoir total d'une facture de 1 article, émis ce jour-là à 10 h.
     */
    private function avoirTotalDu(string $jour, Facture $facture): Avoir
    {
        $this->travelTo(self::aParis("{$jour} 10:00:00"));

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => 1]], 'Retour');
    }

    /**
     * Les imputations que l'avoir a signées à son émission.
     *
     * @return list<array{facture_id: int, numero: string, montant: int}>
     */
    private function imputationsSignees(Avoir $avoir): array
    {
        return $avoir->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['imputations'];
    }

    /**
     * Ce qui est crédité par avoir, ce qui reste dû et le statut d'une
     * facture.
     *
     * @return array{0: float, 1: float, 2: StatutFacture}
     */
    private function reglementDe(Facture $facture): array
    {
        $facture->refresh();

        return [$facture->montantCrediteParAvoir(), $facture->montantRestant(), $facture->statut];
    }

    public function test_un_avoir_reduit_le_reste_a_payer_de_sa_facture_impayee(): void
    {
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = $this->emettreAvoir($client, [[$facture, 1]]);

        $facture->refresh();
        $this->assertSame(StatutFacture::PartiellementPayee, $facture->statut);
        $this->assertSame(60.0, $facture->montantCrediteParAvoir());
        $this->assertSame(120.0, $facture->montantRestant());
        $this->assertSame(0.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * Comme un acompte (voir MarquerFacturesEnRetardTest), un avoir partiel
     * laisse en retard la facture échue qui n'est pas soldée (G1).
     */
    public function test_un_avoir_partiel_laisse_en_retard_sa_facture_echue(): void
    {
        $this->travelTo(self::aParis('2026-09-01 10:00:00'));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $facture->forceFill(['statut' => StatutFacture::EnRetard])->save();

        $this->emettreAvoir($client, [[$facture, 1]]);

        $facture->refresh();
        $this->assertSame(StatutFacture::EnRetard, $facture->statut);
        $this->assertSame(120.0, $facture->montantRestant());
    }

    public function test_seul_l_excedent_sur_le_reste_a_payer_devient_un_credit_pour_le_client(): void
    {
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->encaisser($facture, 150);

        $avoir = $this->emettreAvoir($client, [[$facture, 1]]);

        $facture->refresh();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame(30.0, $facture->montantCrediteParAvoir());
        $this->assertSame(0.0, $facture->montantRestant());
        $this->assertSame(30.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(30.0, $client->soldeCrediteur());
    }

    /**
     * Chaque partie de l'avoir réduit d'abord sa propre facture ; la part
     * d'une facture déjà réglée règle ensuite les factures impayées du
     * client (B6), dont celle que l'avoir corrige aussi, en une seule
     * imputation. La facture réglée n'est jamais touchée.
     */
    public function test_la_part_d_une_facture_deja_reglee_regle_ensuite_les_factures_impayees_du_client(): void
    {
        $client = Client::factory()->professionnel()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $coque = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureImpayee = $this->emettreFacture($client, $ecran, 2);
        $factureReglee = $this->emettreFacture($client, $coque, 1);
        $this->encaisser($factureReglee, 30);

        $avoir = $this->emettreAvoir($client, [[$factureImpayee, 1], [$factureReglee, 1]]);

        $this->assertSame(['90.00'], AvoirAllocation::query()->where('facture_id', $factureImpayee->id)->pluck('montant_alloue')->all());
        $this->assertSame([90.0, 30.0, StatutFacture::PartiellementPayee], $this->reglementDe($factureImpayee));
        $this->assertSame([0.0, 0.0, StatutFacture::Payee], $this->reglementDe($factureReglee));
        $this->assertSame(0.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * B6 : l'avoir total d'une facture déjà payée règle la plus ancienne
     * facture impayée du client, et l'avoir signe cette imputation.
     */
    public function test_l_avoir_d_une_facture_payee_regle_la_plus_ancienne_facture_impayee_du_client(): void
    {
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $f0 = $this->factureDu('2026-10-01', $dupont, 100);
        $this->encaisser($f0, 100);
        $f1 = $this->factureDu('2026-10-02', $dupont, 120);
        $f2 = $this->factureDu('2026-10-05', $dupont, 50);

        $avoir = $this->avoirTotalDu('2026-10-09', $f0);

        $this->assertSame(['83.33', '16.67', '100.00'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame([100.0, 20.0, StatutFacture::PartiellementPayee], $this->reglementDe($f1));
        $this->assertSame([0.0, 50.0, StatutFacture::Emise], $this->reglementDe($f2));
        $this->assertSame(0.0, $dupont->soldeCrediteur());
        $this->assertSame([['facture_id' => $f1->id, 'numero' => 'FAC-LYON1-2026-000002', 'montant' => 10000]], $this->imputationsSignees($avoir));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    public function test_un_credit_plus_grand_solde_la_plus_ancienne_facture_avant_de_regler_la_suivante(): void
    {
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $f0 = $this->factureDu('2026-10-01', $dupont, 150);
        $this->encaisser($f0, 150);
        $f1 = $this->factureDu('2026-10-02', $dupont, 120);
        $f2 = $this->factureDu('2026-10-05', $dupont, 50);

        $avoir = $this->avoirTotalDu('2026-10-09', $f0);

        $this->assertSame([120.0, 0.0, StatutFacture::Payee], $this->reglementDe($f1));
        $this->assertSame([30.0, 20.0, StatutFacture::PartiellementPayee], $this->reglementDe($f2));
        $this->assertSame(0.0, $dupont->soldeCrediteur());
        $this->assertSame([
            ['facture_id' => $f1->id, 'numero' => 'FAC-LYON1-2026-000002', 'montant' => 12000],
            ['facture_id' => $f2->id, 'numero' => 'FAC-LYON1-2026-000003', 'montant' => 3000],
        ], $this->imputationsSignees($avoir));
    }

    public function test_l_avoir_solde_sa_facture_puis_les_suivantes_et_garde_le_reste_en_credit(): void
    {
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $f1 = $this->factureDu('2026-10-02', $dupont, 120);
        $this->encaisser($f1, 100);
        $f2 = $this->factureDu('2026-10-05', $dupont, 50);

        $avoir = $this->avoirTotalDu('2026-10-09', $f1);

        $this->assertSame([20.0, 0.0, StatutFacture::Payee], $this->reglementDe($f1));
        $this->assertSame([50.0, 0.0, StatutFacture::Payee], $this->reglementDe($f2));
        $this->assertSame(50.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(50.0, $dupont->soldeCrediteur());
        $this->assertSame([
            ['facture_id' => $f1->id, 'numero' => 'FAC-LYON1-2026-000001', 'montant' => 2000],
            ['facture_id' => $f2->id, 'numero' => 'FAC-LYON1-2026-000002', 'montant' => 5000],
        ], $this->imputationsSignees($avoir));
    }

    /**
     * G1 : une facture en retard que le crédit ne solde pas reste en
     * retard pour ce qui en reste dû.
     */
    public function test_une_facture_en_retard_encore_due_apres_l_imputation_reste_en_retard(): void
    {
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $f0 = $this->factureDu('2026-09-01', $dupont, 100);
        $this->encaisser($f0, 100);
        $f1 = $this->factureDu('2026-09-01', $dupont, 120);
        $this->travelTo(self::aParis('2026-10-09 09:00:00'));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::EnRetard, $f1->fresh()->statut);

        $this->avoirTotalDu('2026-10-09', $f0);

        $this->assertSame([100.0, 20.0, StatutFacture::EnRetard], $this->reglementDe($f1));
    }

    /**
     * Le client comptoir est partagé par toutes les ventes anonymes : le
     * crédit de l'avoir d'un ticket ne règle jamais un autre ticket
     * comptoir impayé ; il reste son bon d'avoir.
     */
    public function test_l_avoir_d_un_ticket_du_client_comptoir_ne_regle_aucun_autre_ticket_comptoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        app(TenantContext::class)->set($magasin);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $ticketPaye = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $ticketImpaye = $this->emettreFacture($comptoir, Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]), 1);

        $avoir = $this->avoirTotalDu('2026-10-09', $ticketPaye);

        $this->assertSame([0.0, 20.0, StatutFacture::Emise], $this->reglementDe($ticketImpaye));
        $this->assertSame(12.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame([], $this->imputationsSignees($avoir));
    }

    public function test_le_credit_ne_regle_jamais_un_brouillon_ni_la_facture_d_un_autre_client(): void
    {
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $martin = Client::factory()->professionnel()->create(['nom' => 'Martin']);
        $f0 = $this->factureDu('2026-10-01', $dupont, 100);
        $this->encaisser($f0, 100);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $dupont, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $factureDeMartin = $this->factureDu('2026-10-02', $martin, 50);

        $avoir = $this->avoirTotalDu('2026-10-09', $f0);

        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);
        $this->assertSame([0.0, 50.0, StatutFacture::Emise], $this->reglementDe($factureDeMartin));
        $this->assertSame(0, AvoirAllocation::query()->count());
        $this->assertSame(100.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(100.0, $dupont->soldeCrediteur());
    }

    /**
     * Une facture d'un autre magasin n'est jamais imputée, même si son
     * client_id, forcé en base, désigne le client de l'avoir.
     */
    public function test_le_credit_ne_regle_jamais_une_facture_d_un_autre_magasin_meme_avec_un_client_id_force(): void
    {
        $magasinA = app(TenantContext::class)->magasin();
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $f0 = $this->factureDu('2026-10-01', $dupont, 100);
        $this->encaisser($f0, 100);
        $magasinB = Magasin::factory()->create(['code' => 'PARIS1']);
        app(TenantContext::class)->set($magasinB);
        $factureDeB = $this->factureDu('2026-10-02', Client::factory()->professionnel()->create(), 50);
        DB::table('factures')->where('id', $factureDeB->id)->update(['client_id' => $dupont->id]);
        app(TenantContext::class)->set($magasinA);

        $avoir = $this->avoirTotalDu('2026-10-09', $f0);

        $this->assertFalse(DB::table('avoir_allocations')->where('facture_id', $factureDeB->id)->exists());
        $this->assertSame(100.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(100.0, $dupont->soldeCrediteur());
    }
}
