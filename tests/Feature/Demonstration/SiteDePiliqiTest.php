<?php

namespace Tests\Feature\Demonstration;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;
use App\Domain\Demonstration\HotesDeConfiance;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Providers\FournisseursExternesServiceProvider;
use App\Providers\SiteDePiliqiServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Le mode du site (lot DEMO-COPIE, partie A, N1) : PILIQI_SITE choisit le
 * site réel ou le site de démonstration, une valeur inconnue empêche de
 * démarrer, et chaque site refuse de démarrer avec une configuration qui
 * appartient à l'autre (voir SiteDePiliqiServiceProvider), au lieu de la
 * remplacer en silence. La base porte la marque du site qui l'a créée, et
 * chaque site refuse celle de l'autre (voir MarqueDeLaBase).
 */
class SiteDePiliqiTest extends TestCase
{
    use RefreshDatabase;
    use WithConsoleEvents;

    private const CLE_D_AUDIT_DE_LA_DEMONSTRATION = 'Y2zDqWQnYXVkaXQgZGUgbGEgZMOpbW9uc3RyYXRpb24hIQ==';

    /**
     * Les variables d'environnement retirées le temps d'un test, rendues à
     * sa fin.
     *
     * @var array<string, string>
     */
    private array $variablesRetirees = [];

    protected function tearDown(): void
    {
        foreach ($this->variablesRetirees as $variable => $valeur) {
            putenv("{$variable}={$valeur}");
            $_ENV[$variable] = $valeur;
            $_SERVER[$variable] = $valeur;
        }

        unset($_SERVER['LARAVEL_OCTANE']);

        parent::tearDown();
    }

    public function test_le_site_reel_est_le_site_par_defaut_et_une_valeur_inconnue_empeche_de_demarrer(): void
    {
        $this->assertSame('reel', (require base_path('config/piliqi.php'))['site']);
        $this->assertSame(SiteDePiliqi::Reel, SiteDePiliqi::courant());
        $this->demarrer();

        config(['piliqi.site' => 'démo']);

        $this->assertSame(
            'PILIQI_SITE vaut « démo » : écrivez PILIQI_SITE=reel pour le site réel, ou PILIQI_SITE=demonstration pour le site de démonstration, dans le fichier .env de ce serveur.',
            $this->refusDuDemarrage(),
        );
    }

    public function test_une_operation_propre_a_un_site_s_arrete_sur_l_autre(): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);

        $this->assertThrows(
            fn () => SiteDePiliqi::exiger(SiteDePiliqi::Demonstration),
            SiteMalConfigureException::class,
            'Cette opération n\'existe que sur le site de démonstration ; ce serveur est le site réel.',
        );
    }

    public function test_le_site_de_demonstration_demarre_avec_ses_seuls_fournisseurs_factices_meme_en_production(): void
    {
        $this->configurerLeSiteDeDemonstration();
        $this->demarrer();
        $this->assertSame(SiteDePiliqi::Demonstration, SiteDePiliqi::courant());

        $this->app->detectEnvironment(fn (): string => 'production');
        config(['session.cookie' => '__Host-piliqi-demo-session', 'session.secure' => true, 'session.domain' => null, 'session.path' => '/', 'services.antivirus.pilote' => 'clamav']);
        $this->demarrer();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function vraisFournisseurs(): array
    {
        return [
            'répertoire Sirene' => ['services.sirene.pilote', 'insee'],
            'registre national des entreprises' => ['services.rne.pilote', 'inpi'],
            'courrier recommandé' => ['services.recommande.pilote', 'laposte'],
            'annuaire de l\'Ordre' => ['services.ordre.pilote', 'editeur'],
            'plateforme agréée' => ['services.pdp.pilote', 'superpdp'],
            'VIES' => ['services.vies.pilote', 'reel'],
        ];
    }

    #[DataProvider('vraisFournisseurs')]
    public function test_le_site_de_demonstration_refuse_chaque_vrai_fournisseur_sans_le_remplacer(string $reglage, string $pilote): void
    {
        $this->configurerLeSiteDeDemonstration();
        config([$reglage => $pilote]);
        $service = explode('.', $reglage)[1];

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', sprintf(SiteDePiliqiServiceProvider::MESSAGE_FOURNISSEUR_REEL, $service, strtoupper($service).'_PILOTE', 'faux')),
            $this->refusDuDemarrage(),
        );
        $this->assertSame($pilote, config($reglage));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function configurationsRefuseesParLaDemonstration(): array
    {
        return [
            'antivirus inconnu' => [['services.antivirus.pilote' => 'autre'], SiteDePiliqiServiceProvider::MESSAGE_ANTIVIRUS],
            'transport qui envoie' => [['mail.default' => 'smtp'], SiteDePiliqiServiceProvider::MESSAGE_TRANSPORT_DE_LA_DEMONSTRATION],
            'cache partagé entre les copies' => [['cache.default' => 'redis'], SiteDePiliqiServiceProvider::MESSAGE_CACHE_ET_SESSION_DE_LA_DEMONSTRATION],
            'cache dans une autre base' => [['cache.stores.database.connection' => 'pgsql'], SiteDePiliqiServiceProvider::MESSAGE_CACHE_ET_SESSION_DE_LA_DEMONSTRATION],
            'sessions dans des fichiers partagés' => [['session.driver' => 'file'], SiteDePiliqiServiceProvider::MESSAGE_CACHE_ET_SESSION_DE_LA_DEMONSTRATION],
            'jeton de Mailtrap' => [['services.mailtrap.jeton' => 'jeton'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'MAILTRAP_API_TOKEN')],
            'client de SUPER PDP' => [['services.superpdp.client_id' => 'client'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'SUPERPDP_CLIENT_ID')],
            'secret de SUPER PDP' => [['services.superpdp.client_secret' => 'secret'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'SUPERPDP_CLIENT_SECRET')],
            'clé de l\'INSEE' => [['services.sirene.cle' => 'cle'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'INSEE_SIRENE_CLE')],
            'identifiant de l\'INPI' => [['services.rne.identifiant' => 'moi'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'INPI_RNE_IDENTIFIANT')],
            'clé de La Poste' => [['services.recommande.cle' => 'cle'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'RECOMMANDE_CLE')],
            'clé privée des messages' => [['demonstration.messages.cle_privee' => 'cle'], SiteDePiliqiServiceProvider::MESSAGE_CLE_PRIVEE_DES_MESSAGES],
            'base qui n\'est pas la sentinelle' => [['database.connections.pgsql.database' => 'piliqi'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_BASE_SENTINELLE, 'piliqi')],
            'témoin du site réel' => [['session.cookie' => 'piliqi-session'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_TEMOIN_DE_SESSION, 'piliqi-demo-session', 'piliqi-session')],
        ];
    }

    /**
     * @param  array<string, mixed>  $reglages
     */
    #[DataProvider('configurationsRefuseesParLaDemonstration')]
    public function test_le_site_de_demonstration_refuse_de_demarrer_avec_une_configuration_du_reel(array $reglages, string $refus): void
    {
        $this->configurerLeSiteDeDemonstration();
        config($reglages);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', $refus), $this->refusDuDemarrage());
    }

    public function test_le_site_de_demonstration_refuse_l_hote_smtp_ecrit_dans_l_environnement(): void
    {
        $this->configurerLeSiteDeDemonstration();
        putenv('MAIL_HOST=smtp.exemple.test');

        try {
            $this->assertSame(
                sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', sprintf(SiteDePiliqiServiceProvider::MESSAGE_SECRET_D_UN_VRAI_SERVICE, 'MAIL_HOST')),
                $this->refusDuDemarrage(),
            );
        } finally {
            putenv('MAIL_HOST');
        }
    }

    public function test_le_site_de_demonstration_refuse_une_cle_de_caisse_qui_n_est_pas_epinglee_pour_lui(): void
    {
        $this->configurerLeSiteDeDemonstration();
        $paire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($paire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['reel.pem' => $paire['cle_publique']],
        ]);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', SiteDePiliqiServiceProvider::MESSAGE_CLE_DE_CAISSE_DE_LA_DEMONSTRATION), $this->refusDuDemarrage());

        config(['caisse_securisee.cles_publiques_de_demonstration' => ['demonstration.pem' => $paire['cle_publique']]]);
        $this->demarrer();
    }

    /**
     * L'identifiant de la clé n'est qu'une étiquette : seule son empreinte
     * épinglée fait d'elle une clé de démonstration, courante ou
     * précédente.
     */
    public function test_le_site_de_demonstration_refuse_une_cle_d_audit_dont_l_empreinte_n_est_pas_epinglee(): void
    {
        $this->configurerLeSiteDeDemonstration();
        config(['journal_audit.cle' => base64_encode(random_bytes(32)), 'journal_audit.cle_id' => 'demonstration']);

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', sprintf(SiteDePiliqiServiceProvider::MESSAGE_CLE_D_AUDIT_DE_LA_DEMONSTRATION, 'courante (JOURNAL_AUDIT_CLE)')),
            $this->refusDuDemarrage(),
        );

        $this->configurerLeSiteDeDemonstration();
        config(['journal_audit.cles_precedentes' => ['demonstration-2025' => base64_encode(random_bytes(32))]]);

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', sprintf(SiteDePiliqiServiceProvider::MESSAGE_CLE_D_AUDIT_DE_LA_DEMONSTRATION, 'précédente « demonstration-2025 » (JOURNAL_AUDIT_CLES_PRECEDENTES)')),
            $this->refusDuDemarrage(),
        );
    }

    public function test_le_site_de_demonstration_refuse_un_serveur_qui_garde_l_application_en_memoire(): void
    {
        $this->configurerLeSiteDeDemonstration();
        $_SERVER['LARAVEL_OCTANE'] = '1';

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', SiteDePiliqiServiceProvider::MESSAGE_SERVEUR_EN_MEMOIRE), $this->refusDuDemarrage());
    }

    public function test_en_production_le_site_de_demonstration_exige_le_temoin_host_securise(): void
    {
        $this->configurerLeSiteDeDemonstration();
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site de démonstration', sprintf(SiteDePiliqiServiceProvider::MESSAGE_TEMOIN_DE_SESSION, '__Host-piliqi-demo-session', 'piliqi-demo-session').' '.SiteDePiliqiServiceProvider::MESSAGE_TEMOIN_DE_PRODUCTION),
            $this->refusDuDemarrage(),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function configurationsRefuseesParLeSiteReel(): array
    {
        return [
            'transport de la démonstration' => [['mail.default' => 'demonstration'], SiteDePiliqiServiceProvider::MESSAGE_TRANSPORT_DU_SITE_REEL],
            'clés publiques des messages' => [['demonstration.messages.cles_publiques' => ['m1' => 'cle']], sprintf(SiteDePiliqiServiceProvider::MESSAGE_REGLAGE_DE_LA_DEMONSTRATION, 'DEMONSTRATION_MESSAGES_CLES_PUBLIQUES')],
            'registre des copies' => [['database.connections.registre.database' => 'piliqi_demo_registre'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_REGLAGE_DE_LA_DEMONSTRATION, 'DB_REGISTRE_DATABASE')],
            'rôle qui crée les bases' => [['database.connections.demo_admin.username' => 'demo_admin'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_REGLAGE_DE_LA_DEMONSTRATION, 'DB_DEMO_ADMIN_USERNAME')],
            'base d\'une copie' => [['database.connections.pgsql.database' => 'demo_copie_01hzz'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_BASE_DE_LA_DEMONSTRATION, 'demo_copie_01hzz')],
            'domaine de session qui couvre la démonstration' => [['session.domain' => '.piliqi.fr', 'demonstration.url' => 'https://demo.piliqi.fr'], sprintf(SiteDePiliqiServiceProvider::MESSAGE_DOMAINE_DE_SESSION, '.piliqi.fr', 'demo.piliqi.fr')],
        ];
    }

    /**
     * @param  array<string, mixed>  $reglages
     */
    #[DataProvider('configurationsRefuseesParLeSiteReel')]
    public function test_le_site_reel_refuse_de_demarrer_avec_une_configuration_de_la_demonstration(array $reglages, string $refus): void
    {
        config($reglages);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', $refus), $this->refusDuDemarrage());
    }

    public function test_le_site_reel_refuse_une_cle_de_caisse_ou_d_audit_epinglee_pour_la_demonstration(): void
    {
        $paire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($paire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['reel.pem' => $paire['cle_publique']],
            'caisse_securisee.cles_publiques_de_demonstration' => ['demonstration.pem' => $paire['cle_publique']],
        ]);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', SiteDePiliqiServiceProvider::MESSAGE_CLE_DE_CAISSE_DU_SITE_REEL), $this->refusDuDemarrage());

        config([
            'caisse_securisee.cles_publiques_de_demonstration' => [],
            'journal_audit.empreintes_de_demonstration' => [SiteDePiliqiServiceProvider::empreinteDUneCleDAudit((string) config('journal_audit.cle'))],
        ]);

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', sprintf(SiteDePiliqiServiceProvider::MESSAGE_CLE_D_AUDIT_DU_SITE_REEL, 'courante (JOURNAL_AUDIT_CLE)')),
            $this->refusDuDemarrage(),
        );
    }

    /**
     * Un cache des routes construit sur le site de démonstration ferait
     * exister ses routes sur le site réel : il s'arrête.
     */
    public function test_le_site_reel_s_arrete_devant_une_route_de_la_demonstration(): void
    {
        Route::get('/choisir', fn (): string => 'choisir')->name('demonstration.choisir');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame(
            sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', sprintf(SiteDePiliqiServiceProvider::MESSAGE_ROUTE_DE_LA_DEMONSTRATION, 'demonstration.choisir')),
            $this->refusDuDemarrage(),
        );
    }

    public function test_en_production_le_site_reel_exige_le_temoin_host_et_des_hotes_de_confiance_exacts(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi', 'services.recommande.pilote' => null, 'services.ordre.pilote' => 'editeur', 'services.pdp.pilote' => null, 'services.vies.pilote' => 'reel', 'services.antivirus.pilote' => 'clamav']);
        config(['session.cookie' => 'piliqi-session', 'session.secure' => true]);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', SiteDePiliqiServiceProvider::MESSAGE_TEMOIN_DE_PRODUCTION), $this->refusDuDemarrage());

        config(['session.cookie' => '__Host-piliqi-session', 'session.domain' => null, 'session.path' => '/', 'piliqi.hotes_de_confiance' => ['piliqi.fr', '*.piliqi.fr']]);

        $this->assertSame(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, 'site réel', sprintf(SiteDePiliqiServiceProvider::MESSAGE_HOTE_DE_CONFIANCE, '*.piliqi.fr')), $this->refusDuDemarrage());
    }

    /**
     * Hors du développement et des tests, la liste des hôtes est fermée :
     * l'hôte du site de démonstration et un sous-domaine du site réel
     * répondent 400.
     */
    public function test_les_hotes_de_confiance_sont_exacts_sans_sous_domaine(): void
    {
        config(['piliqi.hotes_de_confiance' => ['piliqi.fr'], 'app.url' => 'https://piliqi.fr']);
        $this->app->detectEnvironment(fn (): string => 'production');
        $garde = new class($this->app) extends TrustHosts
        {
            protected function shouldSpecifyTrustedHosts(): bool
            {
                return true;
            }
        };
        TrustHosts::at(HotesDeConfiance::motifs(...), false);

        try {
            foreach (['piliqi.fr' => true, 'demo.piliqi.fr' => false, 'piliqi-demo.fr' => false, 'piliqi.fr.attaque.test' => false] as $hote => $servi) {
                $requete = Request::create("https://{$hote}/");

                try {
                    $garde->handle($requete, fn () => response('servi'));
                    $requete->getHost();
                    $this->assertTrue($servi, "L'hôte {$hote} ne doit pas être servi.");
                } catch (SuspiciousOperationException) {
                    $this->assertFalse($servi, "L'hôte {$hote} doit être servi.");
                }
            }
        } finally {
            TrustHosts::flushState();
            Request::setTrustedHosts([]);
        }

        $this->assertSame(['^piliqi\.fr$'], HotesDeConfiance::motifs());
        config(['piliqi.hotes_de_confiance' => []]);
        $this->assertSame(['^piliqi\.fr$'], HotesDeConfiance::motifs());
    }

    /**
     * La marque de la base se pose à sa création, avec le site servi, et
     * ne change plus ; chaque site refuse la base marquée par l'autre.
     */
    public function test_la_base_porte_la_marque_du_site_qui_l_a_creee_et_chaque_site_refuse_celle_de_l_autre(): void
    {
        $this->assertSame(SiteDePiliqi::Reel, MarqueDeLaBase::lire(DB::connection()));
        MarqueDeLaBase::exigerPour(SiteDePiliqi::Reel, DB::connection());

        $this->assertThrows(
            fn () => MarqueDeLaBase::exigerPour(SiteDePiliqi::Demonstration, DB::connection()),
            SiteMalConfigureException::class,
            sprintf(MarqueDeLaBase::MESSAGE_AUTRE_MARQUE, DB::connection()->getDatabaseName(), 'reel', 'site de démonstration'),
        );

        DB::statement('ALTER TABLE site_de_la_base DISABLE TRIGGER garde_site_de_la_base');
        DB::table('site_de_la_base')->update(['site' => 'demonstration']);
        DB::statement('ALTER TABLE site_de_la_base ENABLE TRIGGER garde_site_de_la_base');

        $this->assertThrows(
            fn () => MarqueDeLaBase::exigerPour(SiteDePiliqi::Reel, DB::connection()),
            SiteMalConfigureException::class,
            sprintf(MarqueDeLaBase::MESSAGE_AUTRE_MARQUE, DB::connection()->getDatabaseName(), 'demonstration', 'site réel'),
        );
        $this->artisan('caisse:diagnostiquer')->expectsOutputToContain('le site réel ne la sert pas')->assertFailed();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ecrituresDeLaMarque(): array
    {
        return [
            'modification' => ["UPDATE site_de_la_base SET site = 'demonstration'"],
            'seconde ligne' => ["INSERT INTO site_de_la_base (site, une_seule_ligne) VALUES ('reel', false)"],
            'suppression' => ['DELETE FROM site_de_la_base'],
            'vidage' => ['TRUNCATE site_de_la_base'],
        ];
    }

    #[DataProvider('ecrituresDeLaMarque')]
    public function test_la_base_refuse_toute_ecriture_de_sa_marque(string $ecriture): void
    {
        $this->assertThrows(fn () => DB::transaction(fn () => DB::statement($ecriture)), QueryException::class, MarqueDeLaBase::MESSAGE_GARDE);
        $this->assertSame(['reel'], DB::table('site_de_la_base')->pluck('site')->all());
    }

    /**
     * Le pied de chaque page dit la version servie et le site, sur une
     * page publique comme dans les panneaux.
     */
    public function test_le_pied_de_page_dit_la_version_et_le_site_servi(): void
    {
        $this->get(route('code.afficher'))->assertOk()->assertSee('PILIQI · version de développement · site réel');

        config(['piliqi.version' => '2026-11-16 7f9e16c']);

        $this->assertSame('PILIQI · version 2026-11-16 7f9e16c · site réel', VersionServie::texte());
        $this->get(route('code.afficher'))->assertSee('PILIQI · version 2026-11-16 7f9e16c · site réel');

        config(['piliqi.site' => 'demonstration']);
        $this->assertSame('PILIQI · version 2026-11-16 7f9e16c · site de démonstration', VersionServie::texte());
    }

    /**
     * Le site de démonstration ne s'indexe pas et n'envoie aucune adresse
     * vers un autre site ; le site réel garde ses en-têtes.
     */
    public function test_le_site_de_demonstration_ne_s_indexe_pas(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertContent("User-agent: *\nDisallow:\n");

        config(['piliqi.site' => 'demonstration']);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertContent("User-agent: *\nDisallow: /\n");
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: bool}>
     */
    public static function commandesDuSchema(): array
    {
        return [
            'migrate sur la base sentinelle' => ['migrate', [], false],
            'migrate:fresh sur la base sentinelle' => ['migrate:fresh', [], false],
            'db:wipe sur la base sentinelle' => ['db:wipe', [], false],
            'migrate sur une base hors du motif' => ['migrate', ['--database' => 'autre'], false],
            'migrate:fresh sur une copie' => ['migrate:fresh', ['--database' => 'copie'], true],
            'migrate sur un modèle en construction' => ['migrate', ['--database' => 'travail'], true],
            'migrations du registre avec leur chemin' => ['migrate', ['--database' => 'registre', '--path' => 'database/migrations-registre'], true],
            'registre recréé avec son chemin' => ['migrate:fresh', ['--database' => 'registre', '--path' => 'database/migrations-registre'], true],
            'migrations du logiciel sur le registre' => ['migrate', ['--database' => 'registre'], false],
            'effacement du registre' => ['db:wipe', ['--database' => 'registre'], false],
            'commande sans schéma' => ['migrate:status', [], true],
        ];
    }

    /**
     * La porte la plus dangereuse du site de démonstration (A.9) : une
     * fausse manœuvre ne donne jamais le schéma à la base sentinelle ni à
     * une base qui n'est ni une copie ni un modèle en construction.
     *
     * @param  array<string, string>  $arguments
     */
    #[DataProvider('commandesDuSchema')]
    public function test_le_site_de_demonstration_ne_migre_que_les_copies_les_modeles_et_son_registre(string $commande, array $arguments, bool $permise): void
    {
        $this->configurerLeSiteDeDemonstration();
        config([
            'database.connections.autre' => ['driver' => 'pgsql', 'database' => 'piliqi'],
            'database.connections.copie' => ['driver' => 'pgsql', 'database' => 'demo_copie_01hzzw8d6k3t2y1x0v9u8s7r6q'],
            'database.connections.travail' => ['driver' => 'pgsql', 'database' => 'demo_travail_commerce_202611160110'],
            'database.connections.registre.database' => 'piliqi_demo_registre',
        ]);

        $refus = SiteDePiliqiServiceProvider::refusDUneCommandeDeSchema($commande, new ArrayInput($arguments));

        $this->assertSame($permise, $refus === null, (string) $refus);
    }

    /**
     * Le refus arrête vraiment la commande, avant qu'elle ne touche la
     * base : ici, une base qui n'existe pas, dont la commande, si elle
     * partait, dirait qu'elle ne s'ouvre pas.
     */
    public function test_le_site_de_demonstration_arrete_une_commande_du_schema_avant_qu_elle_ne_touche_la_base(): void
    {
        $this->configurerLeSiteDeDemonstration();
        $this->demarrer();
        config(['database.connections.sentinelle_d_essai' => array_merge((array) config('database.connections.pgsql'), ['database' => SiteDePiliqiServiceProvider::BASE_SENTINELLE.'_absente'])]);

        foreach (['db:wipe', 'migrate:fresh'] as $commande) {
            $this->assertThrows(
                fn () => Artisan::call($commande, ['--database' => 'sentinelle_d_essai', '--force' => true]),
                SiteMalConfigureException::class,
                sprintf(SiteDePiliqiServiceProvider::MESSAGE_COMMANDE_DU_SCHEMA, $commande, SiteDePiliqiServiceProvider::BASE_SENTINELLE.'_absente'),
            );
        }
    }

    /**
     * Une configuration du site de démonstration qui démarre : ses
     * fournisseurs factices, son transport, sa base sentinelle, son témoin
     * de session, sa clé d'audit épinglée, sans clé de caisse ni aucun
     * secret d'un vrai service.
     */
    private function configurerLeSiteDeDemonstration(): void
    {
        config(FournisseursExternesServiceProvider::PILOTES_FACTICES);
        config([
            'piliqi.site' => 'demonstration',
            'mail.default' => 'demonstration',
            'caisse_securisee.cle_privee' => null,
            'journal_audit.cle' => self::CLE_D_AUDIT_DE_LA_DEMONSTRATION,
            'journal_audit.cle_id' => 'demonstration',
            'journal_audit.cles_precedentes' => [],
            'journal_audit.empreintes_de_demonstration' => [SiteDePiliqiServiceProvider::empreinteDUneCleDAudit(self::CLE_D_AUDIT_DE_LA_DEMONSTRATION)],
            'database.connections.pgsql.database' => SiteDePiliqiServiceProvider::BASE_SENTINELLE,
            'session.cookie' => SiteDePiliqiServiceProvider::TEMOIN_DE_SESSION_DE_LA_DEMONSTRATION,
            'demonstration.messages.cle_privee' => null,
            'services.mailtrap.jeton' => null,
            'services.superpdp.client_id' => null,
            'services.superpdp.client_secret' => null,
            'services.sirene.cle' => null,
            'services.rne.identifiant' => null,
            'services.rne.mot_de_passe' => null,
            'services.recommande.cle' => null,
            'services.recommande.secret_rappel' => null,
            'services.postmark.key' => null,
            'services.resend.key' => null,
            'services.ses.key' => null,
            'services.ses.secret' => null,
            'services.slack.notifications.bot_user_oauth_token' => null,
        ]);
        $this->retirerDeLEnvironnement('MAIL_HOST');
    }

    private function retirerDeLEnvironnement(string $variable): void
    {
        $valeur = getenv($variable);

        if (is_string($valeur) && ! array_key_exists($variable, $this->variablesRetirees)) {
            $this->variablesRetirees[$variable] = $valeur;
        }

        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
    }

    private function demarrer(): void
    {
        (new SiteDePiliqiServiceProvider($this->app))->boot();
    }

    private function refusDuDemarrage(): string
    {
        try {
            $this->demarrer();
        } catch (SiteMalConfigureException $refus) {
            return $refus->getMessage();
        }

        $this->fail('Le démarrage devait être refusé.');
    }
}
