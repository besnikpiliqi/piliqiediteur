<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * app:purger-traces efface chaque nuit les traces techniques (adresse IP et
 * navigateur) de plus d'un an, dans tous les magasins, sans toucher aux
 * événements qu'elles accompagnaient. La base n'efface une trace qu'une
 * fois expirée à sa propre heure (voir TraceEvenementTest) : les dates des
 * tests sont passées, ou construites depuis cette heure (voir
 * maintenantSelonLaBase()).
 */
class PurgerTracesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un événement technique que le magasin inscrit par une requête HTTP à
     * cet instant (heure de Paris), avec sa trace.
     */
    private function evenementTraceLe(Magasin $magasin, string $instant): EnregistrementFiscal
    {
        $this->travelTo(CarbonImmutable::parse($instant, 'Europe/Paris'));
        Route::get("/test/journaliser/{$magasin->id}", function () use ($magasin): int {
            app(TenantContext::class)->set($magasin);

            return DB::transaction(fn (): int => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees)->id);
        });

        $identifiant = $this->get("/test/journaliser/{$magasin->id}")->getContent();

        return EnregistrementFiscal::query()->withoutGlobalScope(MagasinScope::class)->findOrFail($identifiant);
    }

    /**
     * @return list<int> les événements dont la trace existe encore, tous magasins confondus
     */
    private function evenementsTraces(): array
    {
        return TraceEvenement::query()->withoutGlobalScope(MagasinScope::class)->orderBy('enregistrement_fiscal_id')->pluck('enregistrement_fiscal_id')->all();
    }

    /**
     * Le premier événement de chaque magasin est précédé de l'initialisation
     * de son registre (JET 260), inscrite par la même requête et tracée
     * elle aussi : quatre traces ont plus d'un an, deux dans chaque magasin,
     * y compris dans un magasin fermé depuis.
     */
    public function test_les_traces_de_plus_d_un_an_sont_effacees(): void
    {
        Log::spy();
        $lyon = Magasin::factory()->create();
        $nantes = Magasin::factory()->create();
        $ancienneDeLyon = $this->evenementTraceLe($lyon, '2024-03-01 10:00:00');
        $ancienneDeNantes = $this->evenementTraceLe($nantes, '2024-03-01 11:00:00');
        $recente = $this->evenementTraceLe($lyon, '2024-06-01 10:00:00');
        $nantes->forceFill(['statut' => StatutMagasin::Ferme])->save();
        $this->travelTo(CarbonImmutable::parse('2025-03-02 04:00:00', 'Europe/Paris'));

        $this->artisan('app:purger-traces')
            ->expectsOutput('4 trace(s) technique(s) effacée(s).')
            ->assertSuccessful();

        $this->assertSame([$recente->id], $this->evenementsTraces());
        $this->assertSame(
            [$ancienneDeLyon->id, $ancienneDeNantes->id, $recente->id],
            EnregistrementFiscal::query()->withoutGlobalScope(MagasinScope::class)->whereKey([$ancienneDeLyon->id, $ancienneDeNantes->id, $recente->id])->orderBy('id')->pluck('id')->all(),
        );
        Log::shouldHaveReceived('info')->with('Traces techniques expirées effacées.', ['nombre' => 4])->once();
    }

    /**
     * L'horloge de l'application devance d'une heure celle du serveur de
     * base : la trace qui n'expire que dans une demi-heure pour la base
     * (dont l'heure seule compte pour le déclencheur) attend le passage
     * suivant, sans faire refuser le lot ni échouer la commande, et celle
     * d'il y a un an s'efface.
     */
    public function test_une_trace_qui_n_a_expire_qu_a_l_heure_de_l_application_attend_sans_faire_echouer_la_purge(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $ancienne = $this->traceQuiExpireLe($this->maintenantSelonLaBase()->subYear());
        $presqueExpiree = $this->traceQuiExpireLe($this->maintenantSelonLaBase()->addMinutes(30));
        $this->travelTo($this->maintenantSelonLaBase()->addHour());

        $this->artisan('app:purger-traces')
            ->expectsOutput('1 trace(s) technique(s) effacée(s).')
            ->assertSuccessful();

        $this->assertSame([$presqueExpiree->getKey()], $this->evenementsTraces());
        $this->assertModelMissing($ancienne);
    }

    /**
     * La trace d'un nouvel événement technique du magasin actif, qui expire
     * à cet instant.
     */
    private function traceQuiExpireLe(CarbonImmutable $expireLe): TraceEvenement
    {
        $evenement = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $trace = (new TraceEvenement)->forceFill([
            'enregistrement_fiscal_id' => $evenement->getKey(),
            'ip' => '203.0.113.7',
            'agent_utilisateur' => 'Navigateur de test',
            'expire_le' => $expireLe,
        ]);
        $trace->save();

        return $trace;
    }
}
