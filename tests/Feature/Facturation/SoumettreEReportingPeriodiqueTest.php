<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoumettreEReportingPeriodiqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_une_soumission_pour_un_magasin_avec_un_regime_configure(): void
    {
        $magasin = $this->magasinAyantVenduLe5Octobre(RegimeTva::ReelNormalMensuel);
        $this->travelTo('2026-10-11 01:00:00');

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(1, $this->compterSoumissions($magasin));
    }

    public function test_ignore_un_magasin_sans_regime_de_tva_configure(): void
    {
        $magasin = $this->magasinAyantVenduLe5Octobre(null);
        $this->travelTo('2026-10-11 01:00:00');

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(0, $this->compterSoumissions($magasin));
    }

    /**
     * Suspendu, le magasin ne vend plus, mais ses déclarations continuent
     * jusqu'au bout : la décade de ses dernières ventes est déclarée.
     */
    public function test_un_magasin_suspendu_est_declare(): void
    {
        $magasin = $this->magasinAyantVenduLe5Octobre(RegimeTva::ReelNormalMensuel);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->travelTo('2026-10-11 01:00:00');

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $soumission = app(TenantContext::class)->runAs($magasin, fn () => SoumissionEReporting::query()->sole());
        $this->assertSame(
            ['2026-10-01', '2026-10-10', 1, '10.00', '2.00', '12.00'],
            [$soumission->periode_debut->toDateString(), $soumission->periode_fin->toDateString(), $soumission->nombre_clotures, $soumission->total_ht, $soumission->total_tva, $soumission->total_ttc],
        );
    }

    public function test_rejouer_la_commande_le_meme_jour_ne_duplique_rien(): void
    {
        $magasin = $this->magasinAyantVenduLe5Octobre(RegimeTva::ReelNormalMensuel);
        $this->travelTo('2026-10-11 01:00:00');

        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();
        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->assertSame(1, $this->compterSoumissions($magasin));
    }

    /**
     * Un magasin ouvert le 05/10/2026, qui y vend un ticket de 12,00 € TTC
     * à 10:00, puis clôture cette journée à minuit : la décade du 1er au 10
     * octobre a une opération à déclarer.
     */
    private function magasinAyantVenduLe5Octobre(?RegimeTva $regimeTva): Magasin
    {
        $this->travelTo('2026-10-05 09:00:00');
        $magasin = Magasin::factory()->avecLaSociete(['regime_tva' => $regimeTva])->create();
        $this->travelTo('2026-10-05 10:00:00');
        app(TenantContext::class)->runAs($magasin, function () {
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            $ticket = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
            app(EmettreFactureAction::class)->handle($ticket);
        });
        $this->travelTo('2026-10-06 00:00:00');
        app(TenantContext::class)->runAs($magasin, fn () => app(CloturerCaisseAction::class)->handle($magasin, '2026-10-05'));

        return $magasin;
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
