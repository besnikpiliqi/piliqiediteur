<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Tenancy\Enums\Territoire;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * La première migration n'accepte que PostgreSQL, seul moteur de PILIQI.
 * Le fuseau par défaut de la base PostgreSQL, que reçoit toute session
 * ouverte hors de l'application, est fixé à UTC par les migrations, sans
 * commande manuelle. Un rôle qui n'a pas le droit de changer les réglages
 * de la base fait échouer la migration avec un message qui dit quoi faire,
 * sauf si la base est déjà à UTC. Le diagnostic d'un fuseau
 * qui n'est pas l'UTC en nomme la cause et donne la commande qui la
 * corrige, même une fois les migrations passées.
 *
 * Une nouvelle session ne voit un réglage de la base ou du rôle que s'il
 * est validé : les tests qui en changent un l'exécutent sur une autre
 * connexion que celle du test, dont la transaction n'est jamais validée,
 * puis rendent à la base son réglage UTC et retirent celui du rôle dans
 * la base. Une option de connexion (server_options) remplace le fuseau
 * de postgresql.conf, que ces tests ne peuvent pas changer.
 */
class FuseauParDefautDeLaBaseTest extends TestCase
{
    use RefreshDatabase;

    private function migrationDuFuseau(): Migration
    {
        return require database_path('migrations/0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base.php');
    }

    /**
     * Le nom de la base du test et celui de son rôle, propriétaire de la
     * base (voir le README).
     *
     * @return array{0: string, 1: string}
     */
    private function baseEtRoleDuTest(): array
    {
        $configuration = config('database.connections.'.DB::getDefaultConnection());

        return [$configuration['database'], $configuration['username']];
    }

    /**
     * Une connexion de plus à la base du test, ouverte à l'appel avec ces
     * options de connexion.
     *
     * @param  array<string, string>  $optionsDeConnexion
     */
    private function connexion(string $nom, array $optionsDeConnexion = []): Connection
    {
        DB::purge($nom);
        config(["database.connections.{$nom}" => [...config('database.connections.'.DB::getDefaultConnection()), 'server_options' => $optionsDeConnexion]]);

        return DB::connection($nom);
    }

    /**
     * Ce que rend $lire dans une nouvelle session de la base du test,
     * ouverte avec ces options de connexion : la connexion du test,
     * ouverte avant les migrations, garde le fuseau par défaut de son
     * ouverture.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $lire
     * @param  array<string, string>  $optionsDeConnexion
     * @return T
     */
    private function dansUneNouvelleSession(Closure $lire, array $optionsDeConnexion = []): mixed
    {
        try {
            return $lire($this->connexion('nouvelle_session', $optionsDeConnexion));
        } finally {
            DB::purge('nouvelle_session');
        }
    }

    /**
     * Le détail du diagnostic du fuseau, comme caisse:diagnostiquer
     * l'affiche, dans une nouvelle session : le nom de l'UTC qu'elle
     * reçoit, ou le message de l'échec.
     *
     * @param  array<string, string>  $optionsDeConnexion
     */
    private function detailDuDiagnostic(array $optionsDeConnexion = []): string
    {
        return $this->dansUneNouvelleSession(static function (Connection $session): string {
            try {
                return (string) FuseauParDefautDeLaBase::exigerUtc($session);
            } catch (RuntimeException $echec) {
                return $echec->getMessage();
            }
        }, $optionsDeConnexion);
    }

    /**
     * La commande SQL que donne un message, à exécuter telle quelle.
     */
    private function commandeDuMessage(string $message): string
    {
        $this->assertSame(1, preg_match('/ALTER (?:DATABASE|ROLE) "[^;]+;/u', $message, $commande), "Aucune commande dans : {$message}");

        return $commande[0];
    }

    /**
     * Rend à la base son fuseau UTC et retire le réglage du rôle dans la
     * base, sur la connexion qui les a changés.
     */
    private function restaurer(Connection $reglages): void
    {
        $reglages->unprepared("do \$\$ begin execute format('alter role %I in database %I reset timezone', session_user, current_database()); end \$\$");
        FuseauParDefautDeLaBase::fixerAUtc($reglages);
        DB::purge('reglages');
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
     * Une connexion simulée, dont la base, le rôle, le propriétaire et les
     * réglages du fuseau sont ceux-ci.
     *
     * @param  array<string, string|null>  $etat
     */
    private function connexionSimulee(array $etat): Connection&MockInterface
    {
        $connexion = Mockery::mock(Connection::class);
        $connexion->shouldReceive('selectOne')->once()->andReturn((object) [
            'base' => 'piliqi',
            'role' => 'piliqi_migrations',
            'proprietaire' => 'postgres',
            'fuseau' => 'UTC',
            'fuseau_du_role_dans_la_base' => null,
            'fuseau_du_role' => null,
            'fuseau_de_la_base' => null,
            ...$etat,
        ]);

        return $connexion;
    }

    /**
     * Deux autres moteurs, jamais joints : la migration s'arrête avant
     * d'ouvrir leur connexion.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function autresBases(): array
    {
        return [
            'SQLite' => [['driver' => 'sqlite', 'database' => ':memory:']],
            'MySQL' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'piliqi']],
        ];
    }

    /**
     * Les déclencheurs et les gardes de la base ne s'écrivent que pour
     * PostgreSQL : sur une autre base, la première migration s'arrête
     * avant toute table, en disant quoi régler, au lieu de laisser une
     * table sans sa protection. La connexion par défaut est rendue avant
     * que RefreshDatabase n'annule la transaction du test.
     *
     * @param  array<string, string>  $configuration
     */
    #[DataProvider('autresBases')]
    public function test_les_migrations_s_arretent_avant_toute_table_sur_une_autre_base_que_postgresql(array $configuration): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.autre_base' => $configuration]);
        DB::setDefaultConnection('autre_base');

        try {
            $this->assertThrows(
                fn () => $this->migrationDuFuseau()->up(),
                RuntimeException::class,
                "PILIQI ne fonctionne qu'avec PostgreSQL (17 ou 18) : la connexion « autre_base » utilise le pilote « {$configuration['driver']} ». Réglez DB_CONNECTION=pgsql et les autres variables DB_ de .env (voir le README), puis relancez php artisan migrate.",
            );
        } finally {
            DB::setDefaultConnection($connexionDuTest);
            DB::purge('autre_base');
        }
    }

    /**
     * Après les migrations, une nouvelle session reçoit « UTC », le nom que
     * fixe la migration, et non le fuseau de postgresql.conf (souvent
     * « Etc/UTC ») ; c'est bien la migration qui le fixe pour la base :
     * son réglage retiré, elle le rétablit.
     */
    public function test_les_migrations_fixent_a_utc_le_fuseau_par_defaut_de_la_base(): void
    {
        $fuseauApresLesMigrations = $this->dansUneNouvelleSession(static fn (Connection $session): string => (string) $session->selectOne("select reset_val from pg_settings where name = 'TimeZone'")->reset_val);
        FuseauParDefautDeLaBase::retirer(DB::connection());
        $reglagesRetires = $this->reglagesDeLaBase();

        $this->migrationDuFuseau()->up();

        $this->assertSame('UTC', $fuseauApresLesMigrations);
        $this->assertSame([], $reglagesRetires);
        $this->assertSame(['TimeZone=UTC'], $this->reglagesDeLaBase());
    }

    /**
     * Le refus de PostgreSQL (SQLSTATE 42501, insufficient_privilege),
     * simulé : le message nomme la base, le rôle et le propriétaire, et
     * donne les commandes qui marchent, ALTER DATABASE … SET timezone par
     * le propriétaire ou un superutilisateur, puis une nouvelle migration
     * qui trouve ce réglage en place, ou la base donnée au rôle des
     * migrations par un superutilisateur (un propriétaire ordinaire ne le
     * peut pas). Toute autre erreur remonte telle quelle.
     */
    public function test_sans_le_droit_de_changer_les_reglages_de_la_base_la_migration_echoue_en_disant_quoi_faire(): void
    {
        $erreurDeLaBase = static function (string $sqlstate, string $message): QueryException {
            $erreurPdo = new PDOException("SQLSTATE[{$sqlstate}]: {$message}");
            $erreurPdo->errorInfo = [$sqlstate, 7, $message];

            return new QueryException('pgsql', 'do $$ … $$', [], $erreurPdo);
        };
        $refusePar = function (QueryException $refus): Connection {
            $connexion = $this->connexionSimulee([]);
            $connexion->shouldReceive('unprepared')->once()->andThrow($refus);

            return $connexion;
        };
        $droitInsuffisant = $refusePar($erreurDeLaBase('42501', 'ERROR:  must be owner of database piliqi'));
        $autreErreur = $erreurDeLaBase('57014', 'ERROR:  canceling statement due to user request');

        $this->assertThrows(
            fn () => FuseauParDefautDeLaBase::fixerAUtc($droitInsuffisant),
            RuntimeException::class,
            'Le fuseau par défaut de la base « piliqi » n\'a pas pu être fixé à UTC : le rôle « piliqi_migrations », qui lance les migrations, n\'est pas propriétaire de cette base, qui appartient à « postgres ». '
            .'Faites exécuter par « postgres » ou un superutilisateur ALTER DATABASE "piliqi" SET timezone TO \'UTC\'; puis relancez php artisan migrate, qui trouvera ce réglage en place ; '
            .'ou faites donner la base au rôle « piliqi_migrations » par un superutilisateur (ALTER DATABASE "piliqi" OWNER TO "piliqi_migrations";), puis relancez php artisan migrate. '
            .'Ce fuseau protège les sessions ouvertes hors de l\'application (psql, sauvegardes, scripts), qui liraient sinon les horodatages dans un autre fuseau.',
        );
        $this->assertThrows(fn () => FuseauParDefautDeLaBase::fixerAUtc($refusePar($autreErreur)), fn (QueryException $exception): bool => $exception === $autreErreur);
    }

    /**
     * Une base dont le propriétaire a déjà fixé le fuseau à UTC, sous l'un
     * ou l'autre nom, laisse passer la migration d'un rôle qui n'a pas le
     * droit de changer ses réglages : elle ne tente rien.
     */
    public function test_une_base_deja_a_utc_laisse_passer_la_migration_d_un_role_sans_le_droit_de_changer_ses_reglages(): void
    {
        $dejaAUtc = $this->connexionSimulee(['fuseau_de_la_base' => 'UTC']);
        $dejaAEtcUtc = $this->connexionSimulee(['fuseau_de_la_base' => 'Etc/UTC']);
        $dejaAUtc->shouldNotReceive('unprepared');
        $dejaAEtcUtc->shouldNotReceive('unprepared');

        FuseauParDefautDeLaBase::fixerAUtc($dejaAUtc);
        FuseauParDefautDeLaBase::fixerAUtc($dejaAEtcUtc);
    }

    /**
     * Une base restaurée d'une sauvegarde logique sans --create n'a plus de
     * réglage de fuseau, et ses migrations sont déjà passées : le
     * diagnostic dit que le fuseau vient du serveur et donne la commande
     * du propriétaire, qui le corrige.
     */
    public function test_sans_reglage_de_la_base_le_diagnostic_nomme_le_fuseau_du_serveur_et_la_commande_qui_le_corrige(): void
    {
        [$base, $role] = $this->baseEtRoleDuTest();
        $paris = Calendrier::deParis()->fuseau();
        $reglages = $this->connexion('reglages');
        FuseauParDefautDeLaBase::retirer($reglages);

        try {
            $reglagesRetires = $this->reglagesDeLaBase();
            $detail = $this->detailDuDiagnostic(['TimeZone' => $paris]);
            $reglages->unprepared($this->commandeDuMessage($detail));
            $reglagesApresLaCommande = $this->reglagesDeLaBase();
            $detailApresLaCommande = $this->detailDuDiagnostic();
        } finally {
            $this->restaurer($reglages);
        }

        $this->assertSame(
            "Le fuseau par défaut que reçoit une session de la base « {$base} » est « {$paris} », et non UTC : la base n'a aucun réglage de fuseau, et une session reçoit celui de la configuration du serveur (postgresql.conf) ou des options de la connexion. "
            .'php artisan migrate fixe ce réglage dans une base neuve, mais ne le refait pas une fois ses migrations passées, et une sauvegarde logique restaurée sans --create le perd. '
            ."Faites exécuter par le propriétaire de la base (« {$role} ») ou un superutilisateur ALTER DATABASE \"{$base}\" SET timezone TO 'UTC'; Relancez ensuite php artisan caisse:diagnostiquer.",
            $detail,
        );
        $this->assertSame([], $reglagesRetires);
        $this->assertSame(['TimeZone=UTC'], $reglagesApresLaCommande);
        $this->assertSame('UTC', $detailApresLaCommande);
    }

    public function test_un_reglage_de_la_base_qui_n_est_pas_utc_est_nomme_avec_la_commande_qui_le_corrige(): void
    {
        [$base, $role] = $this->baseEtRoleDuTest();
        $paris = Calendrier::deParis()->fuseau();
        $reglages = $this->connexion('reglages');
        $reglages->unprepared("do \$\$ begin execute format('alter database %I set timezone to %L', current_database(), '{$paris}'); end \$\$");

        try {
            $detail = $this->detailDuDiagnostic();
            $reglages->unprepared($this->commandeDuMessage($detail));
            $detailApresLaCommande = $this->detailDuDiagnostic();
        } finally {
            $this->restaurer($reglages);
        }

        $this->assertSame(
            "Le fuseau par défaut que reçoit une session de la base « {$base} » est « {$paris} », et non UTC : le réglage de la base (ALTER DATABASE … SET timezone) le fixe. "
            ."Faites exécuter par le propriétaire de la base (« {$role} ») ou un superutilisateur ALTER DATABASE \"{$base}\" SET timezone TO 'UTC'; Relancez ensuite php artisan caisse:diagnostiquer.",
            $detail,
        );
        $this->assertSame('UTC', $detailApresLaCommande);
    }

    public function test_un_reglage_du_role_dans_la_base_est_nomme_avec_la_commande_qui_le_retire(): void
    {
        [$base, $role] = $this->baseEtRoleDuTest();
        $paris = Calendrier::deParis()->fuseau();
        $reglages = $this->connexion('reglages');
        $reglages->unprepared("do \$\$ begin execute format('alter role %I in database %I set timezone to %L', session_user, current_database(), '{$paris}'); end \$\$");

        try {
            $detail = $this->detailDuDiagnostic();
            $reglages->unprepared($this->commandeDuMessage($detail));
            $detailApresLaCommande = $this->detailDuDiagnostic();
        } finally {
            $this->restaurer($reglages);
        }

        $this->assertSame(
            "Le fuseau par défaut que reçoit une session de la base « {$base} » est « {$paris} », et non UTC : le réglage du rôle « {$role} » dans cette base (ALTER ROLE … IN DATABASE … SET timezone) le fixe, avant celui de la base. "
            ."Faites exécuter par ce rôle ou un superutilisateur ALTER ROLE \"{$role}\" IN DATABASE \"{$base}\" RESET timezone; Relancez ensuite php artisan caisse:diagnostiquer.",
            $detail,
        );
        $this->assertSame('UTC', $detailApresLaCommande);
    }

    /**
     * Le réglage du rôle pour toutes les bases, simulé : le changer pour de
     * bon toucherait les bases des autres processus de tests parallèles.
     */
    public function test_un_reglage_du_role_est_nomme_avec_la_commande_qui_le_retire(): void
    {
        $paris = Calendrier::deParis()->fuseau();
        $connexion = $this->connexionSimulee(['role' => 'piliqi', 'fuseau' => $paris, 'fuseau_du_role' => $paris, 'fuseau_de_la_base' => 'UTC']);

        $this->assertThrows(
            fn () => FuseauParDefautDeLaBase::exigerUtc($connexion),
            RuntimeException::class,
            "Le fuseau par défaut que reçoit une session de la base « piliqi » est « {$paris} », et non UTC : le réglage du rôle « piliqi » (ALTER ROLE … SET timezone) le fixe, avant celui de la base. "
            .'Faites exécuter par ce rôle ou un superutilisateur ALTER ROLE "piliqi" RESET timezone; Relancez ensuite php artisan caisse:diagnostiquer.',
        );
    }

    public function test_des_options_de_connexion_qui_remplacent_l_utc_de_la_base_sont_nommees(): void
    {
        [$base] = $this->baseEtRoleDuTest();
        $martinique = Calendrier::duTerritoire(Territoire::Martinique)->fuseau();

        $detail = $this->detailDuDiagnostic(['TimeZone' => $martinique]);

        $this->assertSame(
            "Le fuseau par défaut que reçoit une session de la base « {$base} » est « {$martinique} », et non UTC : les options de la connexion le fixent (variable d'environnement PGOPTIONS, server_options de config/database.php ou options d'un répartiteur de connexions), avant les réglages du rôle et de la base, qui le fixent à UTC. "
            .'Retirez-en l\'option timezone. Relancez ensuite php artisan caisse:diagnostiquer.',
            $detail,
        );
    }
}
