<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Le fuseau par défaut de la base PostgreSQL, que reçoit toute session
 * ouverte hors de l'application, est fixé à UTC par les migrations, sans
 * commande manuelle ; sous SQLite, qui n'a pas de fuseau de session, la
 * migration ne fait rien. Un rôle qui n'a pas le droit de changer les
 * réglages de la base fait échouer la migration avec un message qui dit
 * quoi faire.
 */
class FuseauParDefautDeLaBaseTest extends TestCase
{
    use RefreshDatabase;

    private function migrationDuFuseau(): Migration
    {
        return require database_path('migrations/0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base.php');
    }

    /**
     * Les réglages propres à la base courante, pour tous les rôles
     * (pg_db_role_setting), tels que ce test les voit.
     *
     * @return list<string>
     */
    private function reglagesDeLaBase(): array
    {
        $reglages = DB::selectOne('select array_to_json(s.setconfig) as reglages from pg_db_role_setting s join pg_database d on d.oid = s.setdatabase where d.datname = current_database() and s.setrole = 0');

        return $reglages === null ? [] : json_decode($reglages->reglages, true);
    }

    /**
     * Le fuseau par défaut que reçoit une nouvelle session de la base du
     * test, avant que l'application ne fixe le sien : la connexion du test,
     * ouverte avant les migrations, garde celui de son ouverture.
     */
    private function fuseauDUneNouvelleSession(): string
    {
        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.nouvelle_session' => config("database.connections.{$connexionDuTest}")]);

        try {
            return (string) DB::connection('nouvelle_session')->selectOne("select reset_val from pg_settings where name = 'TimeZone'")->reset_val;
        } finally {
            DB::purge('nouvelle_session');
        }
    }

    /**
     * Après les migrations, une nouvelle session reçoit « UTC », le nom que
     * fixe la migration, et non le fuseau de postgresql.conf (souvent
     * « Etc/UTC ») ; c'est bien la migration qui le fixe pour la base :
     * son réglage retiré, elle le rétablit.
     */
    public function test_sous_postgresql_les_migrations_fixent_a_utc_le_fuseau_par_defaut_de_la_base(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Le fuseau par défaut d\'une base n\'existe que sous PostgreSQL.');
        }

        $fuseauApresLesMigrations = $this->fuseauDUneNouvelleSession();
        FuseauParDefautDeLaBase::retirer(DB::connection());
        $reglagesRetires = $this->reglagesDeLaBase();

        $this->migrationDuFuseau()->up();

        $this->assertSame('UTC', $fuseauApresLesMigrations);
        $this->assertSame([], $reglagesRetires);
        $this->assertSame(['TimeZone=UTC'], $this->reglagesDeLaBase());
    }

    public function test_sous_sqlite_la_migration_du_fuseau_ne_fait_rien(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Ce test vérifie la migration sous SQLite.');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->migrationDuFuseau()->up();
        $this->migrationDuFuseau()->down();

        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * Le refus de PostgreSQL (SQLSTATE 42501, insufficient_privilege),
     * simulé : le message nomme la base et le rôle, et dit de lancer les
     * migrations avec le propriétaire de la base ou de la lui donner, puis
     * de les relancer. Toute autre erreur remonte telle quelle.
     */
    public function test_sans_le_droit_de_changer_les_reglages_de_la_base_la_migration_echoue_en_disant_quoi_faire(): void
    {
        $erreurDeLaBase = static function (string $sqlstate, string $message): QueryException {
            $erreurPdo = new PDOException("SQLSTATE[{$sqlstate}]: {$message}");
            $erreurPdo->errorInfo = [$sqlstate, 7, $message];

            return new QueryException('pgsql', 'do $$ … $$', [], $erreurPdo);
        };
        $connexion = static function (QueryException $refus): Connection {
            $connexion = Mockery::mock(Connection::class);
            $connexion->shouldReceive('getDriverName')->andReturn('pgsql');
            $connexion->shouldReceive('selectOne')->with('select current_database() as base, current_user as role')->andReturn((object) ['base' => 'piliqi', 'role' => 'piliqi_migrations']);
            $connexion->shouldReceive('unprepared')->andThrow($refus);

            return $connexion;
        };
        $droitInsuffisant = $connexion($erreurDeLaBase('42501', 'ERROR:  must be owner of database piliqi'));
        $autreErreur = $erreurDeLaBase('57014', 'ERROR:  canceling statement due to user request');

        $this->assertThrows(
            fn () => FuseauParDefautDeLaBase::fixerAUtc($droitInsuffisant),
            RuntimeException::class,
            'Le fuseau par défaut de la base « piliqi » n\'a pas pu être fixé à UTC : le rôle « piliqi_migrations », qui lance les migrations, n\'est pas propriétaire de cette base. '
            .'Lancez les migrations avec le rôle propriétaire de la base, ou faites-la lui donner par son propriétaire actuel ou un superutilisateur (ALTER DATABASE "piliqi" OWNER TO "piliqi_migrations";), puis relancez php artisan migrate. '
            .'Ce fuseau protège les sessions ouvertes hors de l\'application (psql, sauvegardes, scripts), qui liraient sinon les horodatages dans un autre fuseau.',
        );
        $this->assertThrows(fn () => FuseauParDefautDeLaBase::fixerAUtc($connexion($autreErreur)), fn (QueryException $exception): bool => $exception === $autreErreur);
    }
}
