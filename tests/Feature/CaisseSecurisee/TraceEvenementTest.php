<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La trace technique d'un événement (adresse IP et navigateur, S13) ne se
 * modifie jamais et ne s'efface qu'une fois expirée : la base le refuse
 * elle-même, sous SQLite comme sous PostgreSQL, à toute requête qui ne
 * passe pas par le modèle (voir
 * DeclencheursDeProtection::effacementApresExpiration()). L'heure de
 * référence est celle du serveur de base de données : les traces de ces
 * tests expirent par rapport à elle (voir maintenantSelonLaBase()), jamais
 * par rapport à l'heure de l'application, voyagée ou non.
 */
class TraceEvenementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un événement technique inscrit par une requête HTTP du magasin, avec
     * sa trace.
     */
    private function evenementTrace(Magasin $magasin): EnregistrementFiscal
    {
        Route::get('/test/journaliser', function () use ($magasin): int {
            app(TenantContext::class)->set($magasin);

            return DB::transaction(fn (): int => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees)->id);
        });

        $identifiant = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/test/journaliser')->getContent();

        return EnregistrementFiscal::query()->findOrFail($identifiant);
    }

    /**
     * Une trace de l'événement qui expire à cet instant.
     */
    private function trace(EnregistrementFiscal $evenement, CarbonImmutable $expireLe): TraceEvenement
    {
        $trace = (new TraceEvenement)->forceFill([
            'enregistrement_fiscal_id' => $evenement->getKey(),
            'ip' => '203.0.113.7',
            'agent_utilisateur' => 'Navigateur de test',
            'expire_le' => $expireLe,
        ]);
        $trace->save();

        return $trace;
    }

    private function magasinActif(): Magasin
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function journaliser(): EnregistrementFiscal
    {
        return DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
    }

    public function test_une_trace_ne_se_modifie_jamais(): void
    {
        $this->magasinActif();
        $active = $this->trace($this->journaliser(), $this->maintenantSelonLaBase()->addYear());
        $expiree = $this->trace($this->journaliser(), $this->maintenantSelonLaBase()->subDay());

        foreach ([$active, $expiree] as $trace) {
            $this->assertThrows(
                $this->dansUnPointDeSauvegarde(fn () => DB::table('traces_evenements')->where('enregistrement_fiscal_id', $trace->getKey())->update(['ip' => '198.51.100.1'])),
                QueryException::class,
                'traces_evenements : modification refusée',
            );
        }

        $this->assertSame(['203.0.113.7', '203.0.113.7'], DB::table('traces_evenements')->orderBy('enregistrement_fiscal_id')->pluck('ip')->all());
    }

    /**
     * Une insertion qui remplacerait la trace (INSERT OR REPLACE sous
     * SQLite, qui efface la ligne en conflit, INSERT ... ON CONFLICT DO
     * UPDATE sous PostgreSQL, qui la modifie) ne la falsifie pas pour la
     * rendre effaçable : la base la refuse comme une suppression avant
     * expiration ou comme une modification.
     */
    public function test_une_trace_ne_se_reecrit_pas_par_une_insertion_qui_la_remplace(): void
    {
        $this->magasinActif();
        $trace = $this->trace($this->journaliser(), $this->maintenantSelonLaBase()->addYear());
        $ligne = fn (): array => (array) DB::table('traces_evenements')->where('enregistrement_fiscal_id', $trace->getKey())->first();
        $avant = $ligne();
        $valeurs = '(?, ?, ?, ?, ?, ?)';
        $colonnes = '(enregistrement_fiscal_id, magasin_id, ip, agent_utilisateur, expire_le, created_at)';
        $remplacement = DB::connection()->getDriverName() === 'pgsql'
            ? "INSERT INTO traces_evenements {$colonnes} VALUES {$valeurs} ON CONFLICT (enregistrement_fiscal_id) DO UPDATE SET ip = excluded.ip, expire_le = excluded.expire_le"
            : "INSERT OR REPLACE INTO traces_evenements {$colonnes} VALUES {$valeurs}";

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => DB::statement($remplacement, [$trace->getKey(), $trace->magasin_id, '0.0.0.0', 'falsifié', '2000-01-01 00:00:00', '2000-01-01 00:00:00'])),
            QueryException::class,
            'traces_evenements : ',
        );

        $this->assertSame('203.0.113.7', $avant['ip']);
        $this->assertSame($avant, $ligne());
    }

    public function test_une_trace_ne_s_efface_pas_avant_son_expiration(): void
    {
        $this->magasinActif();
        $trace = $this->trace($this->journaliser(), $this->maintenantSelonLaBase()->addMinutes(5));

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => DB::table('traces_evenements')->where('enregistrement_fiscal_id', $trace->getKey())->delete()),
            QueryException::class,
            'traces_evenements : suppression refusée avant expiration',
        );
        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => DB::table('traces_evenements')->truncate()),
            QueryException::class,
            'traces_evenements : ',
        );

        $this->assertDatabaseHas('traces_evenements', ['enregistrement_fiscal_id' => $trace->getKey()]);
    }

    public function test_une_trace_expiree_s_efface(): void
    {
        $this->magasinActif();
        $evenement = $this->journaliser();
        $this->trace($evenement, $this->maintenantSelonLaBase()->subMinute());

        $effacees = DB::table('traces_evenements')->where('enregistrement_fiscal_id', $evenement->getKey())->delete();

        $this->assertSame(1, $effacees);
        $this->assertModelExists($evenement);
    }

    /**
     * La trace expire après la durée de config/conservation.php, réglable
     * (Q4) : 12 mois par défaut, ici 6.
     */
    public function test_une_trace_expire_apres_la_duree_de_conservation_reglee(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        config(['conservation.traces_en_mois' => 6]);
        $magasin = $this->magasinActif();

        $evenement = $this->evenementTrace($magasin);

        $this->assertSame('2027-04-09 08:00:00', $evenement->trace->expire_le->format('Y-m-d H:i:s'));
    }
}
