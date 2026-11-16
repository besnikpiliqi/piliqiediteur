<?php

namespace Tests;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\ConstruireUnModele;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use App\Providers\SiteDePiliqiServiceProvider;
use Carbon\CarbonImmutable;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\Fixtures\GereSesPropresBases;

/**
 * Un test du site de démonstration (lot DEMO-COPIE, G.2) : l'application
 * est refaite en mode démonstration (PILIQI_SITE=demonstration, posé dans
 * putenv, $_ENV et $_SERVER avant sa création, puisque config/database.php,
 * config/cache.php et config/queue.php ne déclarent les connexions du
 * registre et de demo_admin que dans ce mode), avec les fournisseurs
 * factices, la base sentinelle vide (demo_aucune_copie) pour base par
 * défaut, le rôle des pages (demo_app) et une clé de caisse de
 * démonstration tirée par le test, jamais celle du site réel. À la fin du
 * test, chaque variable changée revient à sa valeur : avec --parallel, le
 * test suivant du même processus démarre en mode réel.
 *
 * Le registre de test est une base par processus (piliqi_test_registre,
 * suivi du jeton du processus), refaite par ses migrations au premier test
 * du processus, puis vidée avant chaque test. Les rôles demo_admin et
 * demo_app ont le mot de passe du rôle des tests (DB_PASSWORD), comme le
 * README les fait créer.
 *
 * Le modèle de test de chaque type (voir semerLeModeleDeTest()) se
 * construit par la vraie construction, une fois par processus, et reste un
 * modèle (IS_TEMPLATE) pour tous les tests du processus, qui le retrouvent
 * au registre par modeleDeTest() ; il est supprimé à la fin du processus.
 * Toute autre base créée par un test (copie, modèle d'un test de la
 * construction) est supprimée à la fin du test, même après un échec.
 */
abstract class DemonstrationTestCase extends TestCase implements GereSesPropresBases
{
    /**
     * La base de service de PostgreSQL, où demo_admin se connecte pour
     * créer la base sentinelle et le registre de test.
     */
    public const BASE_DE_SERVICE = 'postgres';

    /**
     * Les variables d'un vrai service que le site de démonstration refuse
     * (voir SiteDePiliqiServiceProvider) : posées à « (null) », que Laravel
     * lit comme nulle et que le chargement du .env du poste ne remplace pas
     * (une variable vide ou absente, il la remplirait).
     */
    private const VARIABLES_VIDEES = [
        'MAIL_HOST', 'INSEE_SIRENE_CLE', 'INPI_RNE_IDENTIFIANT', 'INPI_RNE_MOT_DE_PASSE', 'RECOMMANDE_CLE', 'RECOMMANDE_SECRET_RAPPEL',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'MAILTRAP_API_TOKEN', 'SUPERPDP_CLIENT_ID', 'SUPERPDP_CLIENT_SECRET', 'POSTMARK_API_KEY',
        'RESEND_API_KEY', 'SLACK_BOT_USER_OAUTH_TOKEN', 'CAISSE_SECURISEE_CLE_PRIVEE', 'CAISSE_SECURISEE_PHRASE_SECRETE', 'JOURNAL_AUDIT_CLE',
        'DEMONSTRATION_MESSAGES_CLE_PRIVEE',
    ];

    /**
     * La valeur d'avant le test de chaque variable changée (null : absente).
     *
     * @var array<string, string|null>
     */
    private array $environnementAvant = [];

    /**
     * Les modèles de test du processus, par type : les colonnes de leur
     * ligne au registre, remise à chaque test qui les demande.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $modelesDeTest = [];

    /**
     * @var array{cle_privee: string, cle_publique: string}|null
     */
    private static ?array $paireDeDemonstration = null;

    private static bool $registreMigre = false;

    /**
     * La paire Ed25519 des messages de test : la clé privée signe comme le
     * site réel, la clé publique est celle que le site de démonstration
     * connaît, sous l'identifiant « tests ».
     *
     * @var array{privee: string, publique: string}
     */
    protected static array $paireDesMessages;

    /**
     * @return Application
     */
    public function createApplication()
    {
        $this->poserLEnvironnementDeLaDemonstration();
        $app = parent::createApplication();
        self::$paireDeDemonstration ??= SignataireFiscal::genererUnePaire();
        if (! isset(self::$paireDesMessages)) {
            $paire = sodium_crypto_sign_keypair();
            self::$paireDesMessages = ['privee' => sodium_crypto_sign_secretkey($paire), 'publique' => sodium_crypto_sign_publickey($paire)];
        }

        $app['config']->set([
            'caisse_securisee.cle_privee' => base64_encode(self::$paireDeDemonstration['cle_privee']),
            'caisse_securisee.cles_publiques' => ['demonstration-tests.pem' => self::$paireDeDemonstration['cle_publique']],
            'demonstration.dossier' => storage_path('framework/testing/demonstration/'.self::jeton()),
            'demonstration.messages.cles_publiques' => ['tests' => base64_encode(self::$paireDesMessages['publique'])],
            'demonstration.origine_du_site_reel' => 'https://piliqi.test',
            'queue.connections.demonstration' => ['driver' => 'sync'],
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLeRegistre();
    }

    protected function tearDown(): void
    {
        try {
            $this->effacerCeQueLeTestACree();
        } finally {
            parent::tearDown();
            $this->rendreLEnvironnement();
        }
    }

    /**
     * Le modèle de test de ce type, en service au registre : construit au
     * premier appel du processus par la vraie construction, puis gardé.
     */
    protected function modeleDeTest(TypeDActivite $type): Modele
    {
        $bases = app(BasesDeLaDemonstration::class);
        $connu = self::$modelesDeTest[$type->value] ?? null;
        // Une base du même nom laissée par un processus de test arrêté net
        // est supprimée avant la construction (voir plus bas).

        if ($connu !== null && $bases->existe((string) $connu['nom_de_la_base'])) {
            if (! Modele::on('demo_admin')->where('nom_de_la_base', $connu['nom_de_la_base'])->exists()) {
                DB::connection('demo_admin')->table('modeles')->insert($connu);
            }

            return Modele::on('demo_admin')->where('nom_de_la_base', $connu['nom_de_la_base'])->sole();
        }

        $this->app->instance(SemisDUnModele::class, $this->semisDuModeleDeTest());
        $instant = self::aParis('2026-11-16 01:10:00')->addMinutes(self::jeton() * 3 + array_search($type, TypeDActivite::cases(), true));
        $bases->supprimer($bases->nomDUneBaseDeTravail($type, $instant));
        $this->travelTo($instant);

        try {
            $modele = app(ConstruireUnModele::class)->construire($type);
        } finally {
            $this->travelBack();
        }

        self::$modelesDeTest[$type->value] = collect($modele->getAttributes())->except('id')->all();
        self::supprimerLesModelesALaFinDuProcessus();

        return $modele;
    }

    /**
     * Le semis du modèle de test : une société du type, sous un SIREN
     * fictif du semis (le seul que connaît le répertoire factice du site de
     * démonstration), par les vraies actions des tests (deux magasins pour une société « Commerce ou
     * atelier », un pour une société de services, aucun pour un cabinet),
     * chaque compte avec le mot de passe de démonstration du modèle.
     */
    protected function semerLeModeleDeTest(TypeDActivite $type, string $motDePasse): void
    {
        match ($type) {
            TypeDActivite::Commerce => $this->ouvrirUnAutreMagasin($this->ouvrirUneSociete(['nom' => 'Les Halles', 'siren' => '000000109']), ['nom' => 'La République']),
            TypeDActivite::Services => $this->ouvrirUneSociete(['nom' => 'Conseil Vasseur', 'type' => TypeDActivite::Services, 'siren' => '000000117']),
            TypeDActivite::Cabinet => $this->ouvrirUnCabinet(),
        };

        User::query()->withoutGlobalScopes()->update(['password' => bcrypt($motDePasse)]);
        app(TenantContext::class)->clear();
    }

    /**
     * Une copie prête du modèle de test de ce type, créée par le vrai
     * travail (CreerUneCopie), et le secret de son lien.
     *
     * @return array{copie: Copie, secret: string}
     */
    protected function copieDeTest(TypeDActivite $type = TypeDActivite::Commerce, ?string $reference = null, bool $validee = true): array
    {
        $this->modeleDeTest($type);
        $reference ??= bin2hex(random_bytes(16));
        $secret = MessagesSignes::enBase64Url(random_bytes(32));
        Bus::dispatchSync(new CreerUneCopie([
            'reference' => $reference,
            'repere' => 'K7M2QD',
            'type' => $type->value,
            'validee' => $validee,
            'empreinte_du_lien' => hash('sha256', $secret),
            'generation' => 1,
            'fin' => MessagesSignes::instant(CarbonImmutable::now()->addSeconds(30 * 86400)),
        ]));

        return ['copie' => Copie::on('demo_admin')->where('reference', $reference)->sole(), 'secret' => $secret];
    }

    /**
     * La valeur du témoin qu'une entrée par ce lien pose.
     */
    protected function entrerParLeLien(string $secret): string
    {
        $reponse = $this->withHeaders(['Origin' => rtrim((string) config('app.url'), '/')])->postJson('/entrer', ['secret' => $secret]);
        $reponse->assertOk();
        $temoin = $reponse->getCookie(TemoinsDesVisites::nom(), false);
        $this->assertNotNull($temoin);

        return (string) $temoin->getValue();
    }

    /**
     * Un message signé par la clé des messages de test, que le site de
     * démonstration connaît (demonstration.messages.cles_publiques), avec
     * les champs que le site réel y met ; ceux donnés les remplacent, et un
     * champ à null est retiré.
     *
     * @param  array<string, mixed>  $champs
     * @return array{message: string, signature: string}
     */
    protected function messageSigne(array $champs, ?string $clePrivee = null): array
    {
        $complet = array_filter([
            'v' => MessagesSignes::VERSION,
            'kid' => 'tests',
            'aud' => MessagesSignes::AUDIENCE_DES_MESSAGES,
            'emis_le' => MessagesSignes::instant(CarbonImmutable::now()),
            'nonce' => bin2hex(random_bytes(16)),
            'destinataire' => (string) config('demonstration.nom_du_site'),
            ...$champs,
        ], static fn (mixed $valeur): bool => $valeur !== null);
        $message = MessagesSignes::canonique($complet);

        return ['message' => $message, 'signature' => MessagesSignes::enBase64Url(sodium_crypto_sign_detached($message, $clePrivee ?? self::$paireDesMessages['privee']))];
    }

    /**
     * Les tables du registre de test, vidées avant chaque test.
     *
     * @return list<string>
     */
    protected static function tablesDuRegistre(): array
    {
        return ['temoins', 'liens', 'copies', 'modeles', 'messages_recus', 'archives', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'];
    }

    protected static function jeton(): int
    {
        $jeton = getenv('TEST_TOKEN');

        return is_string($jeton) && ctype_digit($jeton) ? (int) $jeton : 0;
    }

    protected static function nomDuRegistreDeTest(): string
    {
        return 'piliqi_test_registre'.(self::jeton() > 0 ? '_'.self::jeton() : '');
    }

    private function semisDuModeleDeTest(): SemisDUnModele
    {
        $semer = Closure::fromCallable([$this, 'semerLeModeleDeTest']);

        return new class($semer) implements SemisDUnModele
        {
            /**
             * @param  Closure(TypeDActivite, string): void  $semer
             */
            public function __construct(private readonly Closure $semer) {}

            public function semer(TypeDActivite $type, string $motDePasse): void
            {
                ($this->semer)($type, $motDePasse);
            }
        };
    }

    private function poserLEnvironnementDeLaDemonstration(): void
    {
        $motDePasse = getenv('DB_PASSWORD');
        $motDePasse = is_string($motDePasse) ? $motDePasse : (Dotenv::parse((string) file_get_contents(dirname(__DIR__).'/.env'))['DB_PASSWORD'] ?? '');

        $variables = [
            'PILIQI_SITE' => 'demonstration',
            'DB_DATABASE' => SiteDePiliqiServiceProvider::BASE_SENTINELLE,
            'DB_USERNAME' => 'demo_app',
            'DB_REGISTRE_DATABASE' => self::nomDuRegistreDeTest(),
            'DB_REGISTRE_USERNAME' => 'demo_app',
            'DB_REGISTRE_PASSWORD' => $motDePasse,
            'DB_DEMO_ADMIN_USERNAME' => 'demo_admin',
            'DB_DEMO_ADMIN_PASSWORD' => $motDePasse,
            'MAIL_MAILER' => 'demonstration',
            'CACHE_STORE' => 'registre',
            'SESSION_DRIVER' => 'database',
            'VIES_PILOTE' => 'faux',
            'SESSION_COOKIE' => null,
            ...array_fill_keys(self::VARIABLES_VIDEES, '(null)'),
        ];

        foreach ($variables as $nom => $valeur) {
            if (! array_key_exists($nom, $this->environnementAvant)) {
                $avant = getenv($nom);
                $this->environnementAvant[$nom] = is_string($avant) ? $avant : null;
            }

            self::ecrire($nom, $valeur);
        }

        Env::enablePutenv();
    }

    private function rendreLEnvironnement(): void
    {
        foreach ($this->environnementAvant as $nom => $valeur) {
            self::ecrire($nom, $valeur);
        }

        $this->environnementAvant = [];
        Env::enablePutenv();
    }

    /**
     * La variable écrite partout où Laravel la lit. Le dépôt des variables
     * de Laravel est refait ensuite (Env::enablePutenv()) : celui d'avant
     * se souvient des variables qu'il a lues du .env et les relirait par
     * dessus celles du test.
     */
    private static function ecrire(string $nom, ?string $valeur): void
    {
        if ($valeur === null) {
            putenv($nom);
            unset($_ENV[$nom], $_SERVER[$nom]);

            return;
        }

        putenv($nom.'='.$valeur);
        $_ENV[$nom] = $valeur;
        $_SERVER[$nom] = $valeur;
    }

    private function preparerLeRegistre(): void
    {
        config(['database.connections.demo_admin_tests' => [...config('database.connections.demo_admin'), 'database' => self::BASE_DE_SERVICE]]);
        $service = DB::connection('demo_admin_tests');

        foreach ([SiteDePiliqiServiceProvider::BASE_SENTINELLE, self::nomDuRegistreDeTest()] as $base) {
            if (! $service->table('pg_database')->where('datname', $base)->exists()) {
                try {
                    $service->unprepared('create database "'.$base."\" template template0 encoding 'UTF8' locale 'C.UTF-8'");
                } catch (QueryException $erreur) {
                    if (($erreur->errorInfo[0] ?? null) !== '42P04') {
                        throw $erreur;
                    }
                }
            }
        }

        DB::purge('demo_admin_tests');

        if (! self::$registreMigre) {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => 'demo_admin', '--path' => SiteDePiliqiServiceProvider::CHEMIN_DES_MIGRATIONS_DU_REGISTRE, '--force' => true]), Artisan::output());
            self::$registreMigre = true;

            return;
        }

        DB::connection('demo_admin')->unprepared('truncate '.implode(', ', self::tablesDuRegistre()).' restart identity cascade');
    }

    private function effacerCeQueLeTestACree(): void
    {
        $bases = app(BasesDeLaDemonstration::class);
        $gardes = array_column(self::$modelesDeTest, 'nom_de_la_base');
        DB::connection('demo_admin')->select('select pg_advisory_unlock_all()');

        foreach (DB::connection('demo_admin')->table('copies')->whereNotNull('nom_de_la_base')->pluck('nom_de_la_base') as $copie) {
            $bases->supprimer((string) $copie);
        }

        foreach (Modele::on('demo_admin')->where('etat', '!=', EtatDUnModele::Supprime)->pluck('nom_de_la_base') as $modele) {
            if (! in_array($modele, $gardes, true)) {
                $bases->supprimer((string) $modele);
                DossiersDeLaDemonstration::effacerUnModele((string) $modele);
            }
        }

        File::deleteDirectory(DossiersDeLaDemonstration::racine().'/copies');
        File::deleteDirectory(DossiersDeLaDemonstration::racine().'/archives');
    }

    /**
     * Les modèles de test du processus, supprimés à sa fin, par une
     * connexion à elle (l'application du dernier test n'existe plus).
     */
    private static function supprimerLesModelesALaFinDuProcessus(): void
    {
        static $inscrit = false;

        if ($inscrit) {
            return;
        }

        $inscrit = true;
        $acces = config('database.connections.demo_admin');
        register_shutdown_function(static function () use ($acces): void {
            $pdo = new PDO('pgsql:host='.$acces['host'].';port='.$acces['port'].';dbname='.self::BASE_DE_SERVICE, $acces['username'], $acces['password']);

            $existe = $pdo->prepare('select 1 from pg_database where datname = ?');

            foreach (self::$modelesDeTest as $modele) {
                $existe->execute([(string) $modele['nom_de_la_base']]);

                if ($existe->fetchColumn() === false) {
                    continue;
                }

                $nom = str_replace('"', '""', (string) $modele['nom_de_la_base']);
                $pdo->exec('alter database "'.$nom.'" with is_template false');
                $pdo->exec('drop database if exists "'.$nom.'" with (force)');
            }
        });
    }
}
