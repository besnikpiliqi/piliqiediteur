<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
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
 * événements qu'elles accompagnaient. Les dates des tests sont passées :
 * la base n'efface une trace qu'une fois expirée à son heure réelle (voir
 * TraceEvenementTest).
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
     * y compris dans un magasin supprimé depuis.
     */
    public function test_les_traces_de_plus_d_un_an_sont_effacees(): void
    {
        Log::spy();
        $lyon = Magasin::factory()->create();
        $nantes = Magasin::factory()->create();
        $ancienneDeLyon = $this->evenementTraceLe($lyon, '2024-03-01 10:00:00');
        $ancienneDeNantes = $this->evenementTraceLe($nantes, '2024-03-01 11:00:00');
        $recente = $this->evenementTraceLe($lyon, '2024-06-01 10:00:00');
        $nantes->delete();
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
}
