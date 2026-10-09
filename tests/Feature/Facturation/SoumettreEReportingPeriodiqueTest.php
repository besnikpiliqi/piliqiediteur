<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoumettreEReportingPeriodiqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_une_soumission_pour_un_magasin_avec_un_regime_configure(): void
    {
        $magasin = Magasin::factory()->create([
            'statut' => StatutMagasin::Active,
            'regime_tva' => RegimeTva::ReelNormalMensuel,
        ]);

        app(TenantContext::class)->runAs($magasin, function () use ($magasin) {
            $client = Client::factory()->create();
            $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
            $facture = app(CreerFactureAction::class)->handle($client, [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ]);
            // Une vente d'il y a vingt jours s'émet il y a vingt jours (voir
            // EmettreFactureAction).
            $this->travel(-20)->days();
            app(EmettreFactureAction::class)->handle($facture);
            $this->travelBack();
            app(CloturerCaisseAction::class)->handle($magasin, now()->subDays(20));
        });

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(1, $this->compterSoumissions($magasin));
    }

    public function test_ignore_un_magasin_sans_regime_de_tva_configure(): void
    {
        $magasin = Magasin::factory()->create([
            'statut' => StatutMagasin::Active,
            'regime_tva' => null,
        ]);

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(0, $this->compterSoumissions($magasin));
    }

    public function test_ignore_un_magasin_inactif(): void
    {
        $magasin = Magasin::factory()->create([
            'statut' => StatutMagasin::Suspendu,
            'regime_tva' => RegimeTva::ReelNormalMensuel,
        ]);

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(0, $this->compterSoumissions($magasin));
    }

    public function test_rejouer_la_commande_le_meme_jour_ne_duplique_rien(): void
    {
        $magasin = Magasin::factory()->create([
            'statut' => StatutMagasin::Active,
            'regime_tva' => RegimeTva::ReelNormalMensuel,
        ]);

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();
        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(1, $this->compterSoumissions($magasin));
    }

    /**
     * MagasinScope est fail-closed (voir TenantContextMissingException) :
     * interroger SoumissionEReporting depuis le corps du test exige un
     * contexte tenant explicitement positionné, la commande ayant déjà
     * restauré le sien à la fin de son exécution.
     */
    private function compterSoumissions(Magasin $magasin): int
    {
        return app(TenantContext::class)->runAs(
            $magasin,
            fn () => SoumissionEReporting::where('magasin_id', $magasin->id)->count(),
        );
    }
}
