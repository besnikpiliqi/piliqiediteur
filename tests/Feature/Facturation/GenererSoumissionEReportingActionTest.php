<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\GenererSoumissionEReportingAction;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\SoumissionEReportingGeneree;
use App\Domain\Facturation\Jobs\SubmitSoumissionEReportingToPdpJob;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Contracts\PeriodeEReporting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenererSoumissionEReportingActionTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function cloturerJournee(string $date, float $prixUnitaireTtc = 120): ClotureCaisse
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixUnitaireTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        // La vente s'émet le jour clôturé : elle se date à l'instant du
        // registre fiscal (voir EmettreFactureAction), sans retour possible.
        // Les journées se clôturent dans l'ordre, chaque jour depuis la
        // première vente : celles d'avant, sans vente, le sont à zéro.
        $this->travelTo("{$date} 10:00:00");
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        return app(CloturerLesJourneesAction::class)->parLeSysteme($this->magasin, CarbonImmutable::parse($date))->last();
    }

    public function test_agrege_toutes_les_clotures_de_la_periode(): void
    {
        $this->cloturerJournee('2026-10-03', 120); // 120 TTC
        $this->cloturerJournee('2026-10-07', 60); // 60 TTC

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertNotNull($soumission);
        $this->assertNotNull($soumission->numero);
        $this->assertSame(2, $soumission->nombre_clotures);
        $this->assertSame('180.00', $soumission->total_ttc);
        $this->assertSame(2, ClotureCaisse::whereNotNull('soumission_ereporting_id')->count());
    }

    public function test_declare_les_ventes_de_la_periode_moins_ses_avoirs(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->travelTo('2026-10-03 10:00:00');
        $vente = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]));
        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-03');
        $this->travelTo('2026-10-07 10:00:00');
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $vente->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        app(CloturerLesJourneesAction::class)->parLeSysteme($this->magasin, CarbonImmutable::parse('2026-10-07'));
        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertSame(['100.00', '20.00', '120.00'], [$soumission->total_ht, $soumission->total_tva, $soumission->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 100.0, 'montant_tva' => 20.0, 'montant_ttc' => 120.0],
        ], $soumission->ventilation_tva);
    }

    /**
     * Rien n'est déclaré pour une période sans opération : la clôture d'une
     * autre période n'y compte pas, et aucun numéro n'est consommé.
     */
    public function test_une_periode_sans_cloture_n_est_ni_declaree_ni_numerotee(): void
    {
        $clotureHorsPeriode = $this->cloturerJournee('2026-10-15', 120);

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertNull($soumission);
        $this->assertSame(0, SoumissionEReporting::count());
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $this->magasin->id)->where('type_document', TypeDocument::SoumissionEReporting->value)->exists());
        $this->assertNull($clotureHorsPeriode->fresh()->soumission_ereporting_id);
    }

    /**
     * Une journée clôturée à zéro n'est pas déclarée : seules les clôtures
     * qui comptent une vente ou un avoir le sont.
     */
    public function test_seules_les_clotures_ayant_des_operations_sont_declarees(): void
    {
        $clotureDesVentes = $this->cloturerJournee('2026-10-03', 120); // 120 TTC
        $this->travelTo('2026-10-05 00:00:00');
        $clotureAZero = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-04');

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertSame([1, '120.00'], [$soumission->nombre_clotures, $soumission->total_ttc]);
        $this->assertSame($soumission->id, $clotureDesVentes->fresh()->soumission_ereporting_id);
        $this->assertNull($clotureAZero->fresh()->soumission_ereporting_id);
    }

    /**
     * Une période dont toutes les journées sont clôturées à zéro n'est pas
     * déclarée davantage.
     */
    public function test_une_periode_cloturee_a_zero_n_est_ni_declaree_ni_numerotee(): void
    {
        $this->travelTo('2026-10-05 00:00:00');
        $clotureAZero = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-04');

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertNull($soumission);
        $this->assertSame(0, SoumissionEReporting::count());
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $this->magasin->id)->where('type_document', TypeDocument::SoumissionEReporting->value)->exists());
        $this->assertNull($clotureAZero->fresh()->soumission_ereporting_id);
    }

    public function test_generer_deux_fois_la_meme_periode_est_un_no_op(): void
    {
        $this->cloturerJournee('2026-10-03', 120);

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        $premiere = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);
        $seconde = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        $this->assertNotNull($premiere);
        $this->assertNull($seconde);
        $this->assertSame(1, SoumissionEReporting::count());
    }

    public function test_une_cloture_deja_agregee_nest_jamais_reprise_dans_une_periode_suivante(): void
    {
        $cloture = $this->cloturerJournee('2026-10-03', 120);

        $premierePeriode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );
        $premiereSoumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $premierePeriode);

        // Une période plus large qui engloberait aussi le 3 octobre ne doit
        // pas la re-agréger : elle est déjà marquée, et il ne reste rien à
        // déclarer.
        $periodeElargie = new PeriodeEReporting(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-31'),
            CarbonImmutable::parse('2026-11-10'),
        );
        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periodeElargie);

        $this->assertNull($soumission);
        $this->assertSame([$premiereSoumission->id], SoumissionEReporting::query()->pluck('id')->all());
        $this->assertSame($premiereSoumission->id, $cloture->fresh()->soumission_ereporting_id);
    }

    public function test_generer_une_soumission_avec_mandat_pdp_actif_declenche_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $this->cloturerJournee('2026-10-03', 120);

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );
        $soumission = app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        Queue::assertPushed(SubmitSoumissionEReportingToPdpJob::class, fn ($job) => $job->soumissionEReportingId === $soumission->id);
    }

    public function test_generer_une_soumission_sans_mandat_pdp_ne_declenche_rien(): void
    {
        Queue::fake();
        $this->cloturerJournee('2026-10-03', 120);

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );
        app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        Queue::assertNotPushed(SubmitSoumissionEReportingToPdpJob::class);
    }

    public function test_dispatch_levenement_soumission_ereporting_generee(): void
    {
        // Event::fake() scopé à cet event précis : un fake global
        // intercepterait aussi les events internes d'Eloquent (creating,
        // ...) dont dépend BelongsToMagasin pour positionner magasin_id.
        Event::fake([SoumissionEReportingGeneree::class]);
        $this->cloturerJournee('2026-10-03', 120);

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );
        app(GenererSoumissionEReportingAction::class)->handle($this->magasin, $periode);

        Event::assertDispatched(SoumissionEReportingGeneree::class);
    }
}
