<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImputerAvoirSurSesFacturesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function emettreFacture(Client $client, Produit $produit, int $quantite): Facture
    {
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    private function encaisser(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'recu_le' => now()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    /**
     * @param  list<array{0: Facture, 1: int}>  $retours  facture et nombre d'articles rendus de sa ligne
     */
    private function emettreAvoir(Client $client, array $retours): Avoir
    {
        $avoir = app(CreerAvoirAction::class)->handle($client, array_map(
            fn (array $retour): array => ['ligne_facture_id' => $retour[0]->lignes->firstOrFail()->id, 'quantite' => $retour[1]],
            $retours,
        ));

        return app(EmettreAvoirAction::class)->handle($avoir);
    }

    public function test_un_avoir_reduit_le_reste_a_payer_de_sa_facture_impayee(): void
    {
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
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
        $this->travelTo('2026-09-01 10:00:00');
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->travelTo('2026-10-15 10:00:00');
        $facture->forceFill(['statut' => StatutFacture::EnRetard])->save();

        $this->emettreAvoir($client, [[$facture, 1]]);

        $facture->refresh();
        $this->assertSame(StatutFacture::EnRetard, $facture->statut);
        $this->assertSame(120.0, $facture->montantRestant());
    }

    public function test_seul_l_excedent_sur_le_reste_a_payer_devient_un_credit_pour_le_client(): void
    {
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
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
     * Chaque partie de l'avoir corrige sa propre facture : la part d'une
     * facture déjà réglée ne vient jamais réduire une autre facture.
     */
    public function test_un_avoir_sur_deux_factures_ne_reduit_chacune_que_de_ses_propres_lignes(): void
    {
        $client = Client::factory()->professionnel()->create();
        $ecran = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $coque = Produit::factory()->create(['prix_vente_ht' => 25, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureImpayee = $this->emettreFacture($client, $ecran, 2);
        $factureReglee = $this->emettreFacture($client, $coque, 1);
        $this->encaisser($factureReglee, 30);

        $avoir = $this->emettreAvoir($client, [[$factureImpayee, 1], [$factureReglee, 1]]);

        $this->assertSame(60.0, $factureImpayee->fresh()->montantRestant());
        $this->assertSame(StatutFacture::Payee, $factureReglee->fresh()->statut);
        $this->assertSame(0.0, $factureReglee->fresh()->montantCrediteParAvoir());
        $this->assertSame(30.0, $avoir->fresh()->montantNonAlloue());
        $this->assertSame(30.0, $client->soldeCrediteur());
    }
}
