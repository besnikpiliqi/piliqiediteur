<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarquerFacturesEnRetardTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFacture(Magasin $magasin, string $dateEcheance): Facture
    {
        app(TenantContext::class)->set($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ['date_echeance' => $dateEcheance]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_une_facture_emise_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(5)->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    public function test_une_facture_partiellement_payee_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDay()->toDateString());

        app(TenantContext::class)->set($magasin);
        $paiement = Paiement::create([
            'client_id' => $facture->client_id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ]);
        $facture->allocationsPaiement()->create([
            'paiement_id' => $paiement->id,
            'montant_alloue' => 50,
        ]);

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    public function test_une_facture_dont_lecheance_nest_pas_encore_passee_nest_pas_touchee(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->addDays(10)->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    public function test_une_facture_deja_payee_nest_pas_touchee_meme_echue(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(3)->toDateString());

        app(TenantContext::class)->set($magasin);
        $facture->forceFill(['statut' => StatutFacture::Payee])->save();

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_un_magasin_suspendu_nest_pas_traite(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(2)->toDateString());

        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    public function test_traite_plusieurs_magasins_independamment(): void
    {
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $factureA = $this->emettreFacture($magasinA, now()->subDay()->toDateString());
        $factureB = $this->emettreFacture($magasinB, now()->subDay()->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::EnRetard, $factureB->fresh()->statut);
    }
}
