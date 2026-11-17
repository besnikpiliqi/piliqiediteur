<?php

namespace App\Providers;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\ValeurSimple;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as Configuration;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Le premier fournisseur de services chargé (voir bootstrap/providers.php) :
 * il lit le site que sert ce serveur (PILIQI_SITE, voir SiteDePiliqi) et
 * refuse de démarrer, avec la cause en français et ce qu'il faut écrire,
 * quand la configuration ne convient pas à ce site (lot DEMO-COPIE, A.3 et
 * A.4). Il ne remplace jamais un réglage en silence. Seules les commandes
 * que Composer lance en construisant le paquet, sans .env, démarrent sans
 * cette garde (voir COMMANDES_DE_LA_CONSTRUCTION_DU_PAQUET).
 *
 * Sur le site de démonstration, il refuse :
 * - un vrai fournisseur extérieur (Sirene, registre national des
 *   entreprises, courrier recommandé, annuaire de l'Ordre, plateforme
 *   agréée, VIES), et un antivirus autre que ClamAV, local, ou le factice ;
 * - un transport d'e-mail autre que « demonstration », et la clé d'un vrai
 *   service (Mailtrap, serveur SMTP, SUPER PDP, INSEE, INPI, La Poste…) ;
 * - une clé privée de la caisse dont la clé publique n'est pas épinglée
 *   dans resources/caisse-securisee/cles-demonstration, une clé du journal
 *   d'audit, courante ou précédente, dont l'empreinte n'est pas épinglée
 *   « démonstration », et la clé privée des messages, qui n'appartient
 *   qu'au site réel ;
 * - Laravel Octane, ou tout serveur qui garde l'application en mémoire
 *   d'une requête à l'autre (une connexion ne doit jamais passer d'une
 *   copie à l'autre) ;
 * - une base par défaut autre que la base sentinelle vide
 *   demo_aucune_copie (chaque requête rejoint sa copie, voir OuvrirLaCopie ;
 *   une requête qui y échapperait ne lit rien) ;
 * - un témoin de session autre que piliqi-demo-session (__Host- devant en
 *   production).
 * Il y coupe aussi toute requête HTTP vers l'extérieur, et y refuse les
 *   commandes qui écrivent le schéma hors de la base d'une copie ou d'un
 *   modèle en construction (voir refusDUneCommandeDeSchema()).
 *
 * Sur le site réel, il refuse :
 * - en production, chaque fournisseur factice, VIES et l'antivirus compris ;
 * - le transport « demonstration », une clé de caisse ou du journal
 *   d'audit épinglée « démonstration », les clés publiques des messages, la
 *   connexion du registre des copies et celle du rôle demo_admin, qui
 *   n'appartiennent qu'au site de démonstration ;
 * - une base dont le nom est celui d'une base du site de démonstration
 *   (demo_…) : le site réel ne sert jamais une base marquée
 *   « démonstration » (voir MarqueDeLaBase) ;
 * - un domaine de session qui couvrirait l'hôte du site de démonstration ;
 * - en production, un témoin de session sans le préfixe __Host- (Secure,
 *   sans domaine, sur /), et une liste d'hôtes de confiance qui ne serait
 *   pas faite de noms d'hôte exacts ;
 * - une route du site de démonstration (demonstration.*), qu'un cache des
 *   routes construit en mode démonstration y ferait exister.
 *
 * Il pose enfin, dans les trois panneaux, le pied de page qui dit la
 * version servie et le site (voir VersionServie).
 */
final class SiteDePiliqiServiceProvider extends ServiceProvider
{
    /**
     * La base vide du site de démonstration, sans aucune table, sur
     * laquelle pointe sa connexion par défaut tant qu'une requête n'a pas
     * rejoint sa copie.
     */
    public const BASE_SENTINELLE = 'demo_aucune_copie';

    public const TEMOIN_DE_SESSION_DE_LA_DEMONSTRATION = 'piliqi-demo-session';

    public const PREFIXE_DES_TEMOINS_DE_PRODUCTION = '__Host-';

    /**
     * Le nom d'une base que le site de démonstration crée : la copie d'une
     * société ou un modèle en construction.
     */
    public const MOTIF_DES_BASES_DE_LA_DEMONSTRATION = '/^demo_(travail|copie)_[a-z0-9_]+$/';

    public const CHEMIN_DES_MIGRATIONS_DU_REGISTRE = 'database/migrations-registre';

    /**
     * Les commandes que Composer lance en construisant le paquet (scripts
     * post-autoload-dump et post-update-cmd de composer.json), avant
     * qu'aucun .env ne soit posé : le paquet se construit une fois, pour
     * les deux sites, et chaque serveur refait ses caches après la pose de
     * son .env (lot DEMO-COPIE, E.1). Elles ne servent aucune page, ne
     * lisent aucune base et ne signent rien : elles démarrent sans la
     * garde, qui ne pourrait lire qu'une configuration absente.
     */
    public const COMMANDES_DE_LA_CONSTRUCTION_DU_PAQUET = ['package:discover', 'filament:upgrade', 'vendor:publish'];

    /**
     * Les commandes qui écrivent ou effacent le schéma d'une base.
     */
    public const COMMANDES_DU_SCHEMA = ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'migrate:install', 'db:wipe'];

    public const MESSAGE_REFUS = 'Ce serveur ne peut pas servir le %s : %s';

    public const MESSAGE_FOURNISSEUR_REEL = 'le fournisseur « %1$s » doit être le faux sur le site de démonstration : écrivez %2$s=%3$s.';

    public const MESSAGE_ANTIVIRUS = 'l\'antivirus du site de démonstration est ClamAV, local, ou le factice : écrivez ANTIVIRUS_PILOTE=clamav (ou factice hors production).';

    public const MESSAGE_TRANSPORT_DE_LA_DEMONSTRATION = 'aucun e-mail ne part du site de démonstration : écrivez MAIL_MAILER=demonstration.';

    public const MESSAGE_SECRET_D_UN_VRAI_SERVICE = 'la variable %s appartient à un vrai service, que le site de démonstration ne joint jamais : retirez-la de son .env.';

    public const MESSAGE_CLE_DE_CAISSE_DE_LA_DEMONSTRATION = 'la clé privée de la caisse (CAISSE_SECURISEE_CLE_PRIVEE) n\'est pas une clé de démonstration épinglée dans resources/caisse-securisee/cles-demonstration : tirez-en une par php artisan caisse:generer-cle sur ce serveur, jamais celle du site réel.';

    /**
     * Le cache du site de démonstration hors d'une copie est celui du
     * registre (le planificateur, le travailleur et les commandes, qui ne
     * lisent jamais la base sentinelle) ; dans une copie, le cache (ses
     * verrous et le limiteur de débit) et la session vivent dans la base de
     * la copie (voir OuvertureDUneCopie), jamais dans un magasin partagé
     * entre les copies (redis, file, memcached), où la limite ou le verrou
     * d'une copie vaudrait pour une autre. La mémoire du processus (array)
     * reste permise aux tests.
     */
    public const CACHE_HORS_D_UNE_COPIE = ['registre', 'array'];

    public const SESSION_DANS_UNE_COPIE = ['database', 'array'];

    public const MESSAGE_CACHE_ET_SESSION_DE_LA_DEMONSTRATION = 'le cache (CACHE_STORE) du site de démonstration est « registre » et sa session (SESSION_DRIVER) « database », sans DB_CACHE_CONNECTION, DB_CACHE_LOCK_CONNECTION ni SESSION_CONNECTION : chaque copie garde son cache et ses sessions dans sa base, jamais dans un magasin partagé entre les copies.';

    public const COMMANDE_QUI_EPINGLE_LES_CLES_D_AUDIT = 'journal-audit:epingler-la-cle';

    public const MESSAGE_CLE_D_AUDIT_DE_LA_DEMONSTRATION = 'la clé du journal d\'audit %s n\'est pas épinglée « démonstration » dans resources/journal-audit/cles-demonstration.txt : tirez une clé propre à ce site, puis php artisan journal-audit:epingler-la-cle, jamais celle du site réel.';

    public const MESSAGE_CLE_PRIVEE_DES_MESSAGES = 'la clé privée des messages (DEMONSTRATION_MESSAGES_CLE_PRIVEE) n\'appartient qu\'au site réel : retirez-la du .env du site de démonstration, qui ne porte que les clés publiques (DEMONSTRATION_MESSAGES_CLES_PUBLIQUES).';

    public const MESSAGE_SERVEUR_EN_MEMOIRE = 'le site de démonstration ne tourne pas sous Laravel Octane ni sous un serveur qui garde l\'application en mémoire d\'une requête à l\'autre : servez-le par PHP-FPM, une requête par processus.';

    public const MESSAGE_SENTINELLE_REMPLIE = 'la base sentinelle « %s » du site de démonstration doit rester sans aucune table : elle en a %d. Supprimez-la, recréez-la vide, et vérifiez qu\'aucune migration ne vise la base par défaut de ce serveur.';

    public const MESSAGE_BASE_SENTINELLE = 'la base par défaut du site de démonstration est la base sentinelle vide : écrivez DB_DATABASE=demo_aucune_copie (elle vaut « %s »).';

    public const MESSAGE_TEMOIN_DE_SESSION = 'le témoin de session de ce site s\'appelle %1$s : écrivez SESSION_COOKIE=%1$s (il vaut « %2$s »).';

    public const MESSAGE_FOURNISSEUR_FACTICE_EN_PRODUCTION = 'Le fournisseur factice de « %1$s » (pilote %2$s) ne sert jamais en production.';

    public const MESSAGE_TRANSPORT_DU_SITE_REEL = 'le transport « demonstration », qui garde les e-mails au lieu de les envoyer, n\'existe que sur le site de démonstration : choisissez le vrai transport dans MAIL_MAILER.';

    public const MESSAGE_CLE_DE_CAISSE_DU_SITE_REEL = 'la clé privée de la caisse (CAISSE_SECURISEE_CLE_PRIVEE) est une clé de démonstration, épinglée dans resources/caisse-securisee/cles-demonstration : le site réel signe avec sa propre clé, jamais celle de la démonstration.';

    public const MESSAGE_CLE_D_AUDIT_DU_SITE_REEL = 'la clé du journal d\'audit %s est épinglée « démonstration » dans resources/journal-audit/cles-demonstration.txt : le site réel chaîne son journal avec sa propre clé.';

    public const MESSAGE_REGLAGE_DE_LA_DEMONSTRATION = 'la variable %s n\'appartient qu\'au site de démonstration : retirez-la du .env du site réel.';

    public const MESSAGE_BASE_DE_LA_DEMONSTRATION = 'la base « %s » est une base du site de démonstration : le site réel ne sert que sa propre base (DB_DATABASE).';

    public const MESSAGE_DOMAINE_DE_SESSION = 'le domaine de session (SESSION_DOMAIN « %s ») couvre l\'hôte du site de démonstration (%s) : le site de démonstration a son propre domaine, jamais un sous-domaine du site réel ; laissez SESSION_DOMAIN vide.';

    public const MESSAGE_TEMOIN_DE_PRODUCTION = 'en production, le témoin de session porte le préfixe __Host- (SESSION_COOKIE=__Host-piliqi-session), avec SESSION_SECURE_COOKIE=true, sans SESSION_DOMAIN, sur le chemin /.';

    public const MESSAGE_HOTE_DE_CONFIANCE = 'HOTES_DE_CONFIANCE ne prend que des noms d\'hôte exacts, sans motif ni sous-domaine ouvert : « %s » est refusé.';

    public const MESSAGE_ROUTE_DE_LA_DEMONSTRATION = 'la route « %s » n\'existe que sur le site de démonstration : un cache des routes construit pour lui ne sert jamais au site réel ; refaites-le sur ce serveur (php artisan route:clear, puis route:cache).';

    public const MESSAGE_COMMANDE_DU_SCHEMA = 'Sur le site de démonstration, php artisan %s ne touche que la base d\'une copie ou d\'un modèle en construction (demo_copie_… ou demo_travail_…), et les migrations du registre que sur la connexion demo_admin (ou registre) avec --path=database/migrations-registre : la base « %s » est refusée, rien n\'a été fait.';

    /**
     * Les clés des vrais services, par réglage, avec leur variable
     * d'environnement : aucune n'est jamais sur le site de démonstration.
     *
     * @var array<string, string>
     */
    private const SECRETS_DES_VRAIS_SERVICES = [
        'services.mailtrap.jeton' => 'MAILTRAP_API_TOKEN',
        'services.superpdp.client_id' => 'SUPERPDP_CLIENT_ID',
        'services.superpdp.client_secret' => 'SUPERPDP_CLIENT_SECRET',
        'services.sirene.cle' => 'INSEE_SIRENE_CLE',
        'services.rne.identifiant' => 'INPI_RNE_IDENTIFIANT',
        'services.rne.mot_de_passe' => 'INPI_RNE_MOT_DE_PASSE',
        'services.recommande.cle' => 'RECOMMANDE_CLE',
        'services.recommande.secret_rappel' => 'RECOMMANDE_SECRET_RAPPEL',
        'services.postmark.key' => 'POSTMARK_API_KEY',
        'services.resend.key' => 'RESEND_API_KEY',
        'services.ses.key' => 'AWS_ACCESS_KEY_ID',
        'services.ses.secret' => 'AWS_SECRET_ACCESS_KEY',
        'services.slack.notifications.bot_user_oauth_token' => 'SLACK_BOT_USER_OAUTH_TOKEN',
    ];

    /**
     * Les variables d'un vrai service sans réglage à elles : l'hôte SMTP a
     * une valeur par défaut dans config/mail.php, seule sa présence dans
     * l'environnement se voit.
     */
    private const VARIABLES_DES_VRAIS_SERVICES = ['MAIL_HOST'];

    /**
     * Les réglages qui n'appartiennent qu'au site de démonstration, avec
     * leur variable d'environnement.
     *
     * @var array<string, string>
     */
    private const REGLAGES_DE_LA_DEMONSTRATION = [
        'demonstration.messages.cles_publiques' => 'DEMONSTRATION_MESSAGES_CLES_PUBLIQUES',
        'database.connections.registre.database' => 'DB_REGISTRE_DATABASE',
        'database.connections.demo_admin.username' => 'DB_DEMO_ADMIN_USERNAME',
        'demonstration.etat.cle_privee' => 'DEMONSTRATION_ETAT_CLE_PRIVEE',
    ];

    /**
     * @throws SiteMalConfigureException quand la configuration ne convient pas au site servi
     */
    public function boot(): void
    {
        if ($this->app->runningConsoleCommand(self::COMMANDES_DE_LA_CONSTRUCTION_DU_PAQUET)) {
            return;
        }

        $site = SiteDePiliqi::courant();
        $refus = $site === SiteDePiliqi::Demonstration ? $this->refusDeLaDemonstration() : $this->refusDuSiteReel();

        if ($refus !== []) {
            throw new SiteMalConfigureException(sprintf(self::MESSAGE_REFUS, $site->libelle(), implode(' ', $refus)));
        }

        if ($site === SiteDePiliqi::Demonstration) {
            Http::preventStrayRequests();
            Event::listen(CommandStarting::class, static function (CommandStarting $commande): void {
                $refus = self::refusDUneCommandeDeSchema($commande->command, $commande->input);

                if ($refus !== null) {
                    throw new SiteMalConfigureException($refus);
                }

                $connexion = DB::connection();
                $refus = $connexion->getDatabaseName() === self::BASE_SENTINELLE ? self::refusDeLaBaseSentinelle($connexion) : null;

                if ($refus !== null) {
                    throw new SiteMalConfigureException(sprintf(self::MESSAGE_REFUS, SiteDePiliqi::Demonstration->libelle(), $refus));
                }
            });
        } else {
            $this->app->booted(fn (): null => $this->refuserLesRoutesDeLaDemonstration());
        }

        FilamentView::registerRenderHook(PanelsRenderHook::FOOTER, static fn (): View => view('pied-de-page-du-site'));
    }

    /**
     * Le refus d'une commande qui écrit ou efface le schéma, sur le site de
     * démonstration (A.9) : elle ne touche que la base d'une copie ou d'un
     * modèle en construction, et les migrations du registre que sur sa
     * connexion ou sur celle de demo_admin, son propriétaire, avec leur
     * chemin. Ainsi une fausse manœuvre ne donne
     * jamais le schéma à la base sentinelle. Lu sur les arguments bruts de
     * la commande, avant son exécution.
     */
    public static function refusDUneCommandeDeSchema(?string $commande, InputInterface $arguments): ?string
    {
        if (! in_array($commande, self::COMMANDES_DU_SCHEMA, true)) {
            return null;
        }

        $connexion = $arguments->getParameterOption('--database', null);
        $connexion = is_string($connexion) && $connexion !== '' ? $connexion : ValeurSimple::enTexte(config('database.default'));
        $base = ValeurSimple::enTexte(config("database.connections.{$connexion}.database"));
        $chemin = $arguments->getParameterOption('--path', null);

        if (in_array($connexion, ['registre', 'demo_admin'], true)) {
            return is_string($chemin) && trim($chemin, '/') === self::CHEMIN_DES_MIGRATIONS_DU_REGISTRE
                ? null
                : sprintf(self::MESSAGE_COMMANDE_DU_SCHEMA, $commande, $base);
        }

        return preg_match(self::MOTIF_DES_BASES_DE_LA_DEMONSTRATION, $base) === 1 ? null : sprintf(self::MESSAGE_COMMANDE_DU_SCHEMA, $commande, $base);
    }

    /**
     * Le refus d'une base sentinelle qui a une table (A.3, C.6.7) : la base
     * par défaut du site de démonstration ne sert qu'à ce qu'une requête ou
     * un travail qui n'aurait pas rejoint sa copie ne lise et n'écrive rien.
     * Contrôlé au départ de chaque commande du site de démonstration (ses
     * travailleurs et son planificateur compris, une commande de plus ne
     * coûtant qu'une requête), et par caisse:diagnostiquer ; jamais à chaque
     * page, qui ne lit sa base qu'une fois rejointe sa copie.
     */
    public static function refusDeLaBaseSentinelle(ConnectionInterface $connexion): ?string
    {
        $tables = ValeurSimple::enEntier($connexion->scalar('select count(*) from pg_catalog.pg_tables where schemaname = current_schema()'));

        return $tables === 0 ? null : sprintf(self::MESSAGE_SENTINELLE_REMPLIE, $connexion->getDatabaseName(), $tables);
    }

    /**
     * L'empreinte épinglée d'une clé du journal d'audit : le SHA-256
     * hexadécimal du texte de la clé, tel qu'il est écrit dans
     * JOURNAL_AUDIT_CLE (voir journal-audit:epingler-la-cle).
     */
    public static function empreinteDUneCleDAudit(string $cle): string
    {
        return hash('sha256', trim($cle));
    }

    /**
     * @return list<string>
     */
    private function refusDeLaDemonstration(): array
    {
        $refus = [];

        foreach (FournisseursExternesServiceProvider::PILOTES_FACTICES as $reglage => $pilote) {
            if ($reglage === 'services.antivirus.pilote') {
                $permis = $this->app->isProduction() ? [AntivirusServiceProvider::CLAMAV] : [AntivirusServiceProvider::CLAMAV, AntivirusServiceProvider::FACTICE];

                if (! in_array(config($reglage), $permis, true)) {
                    $refus[] = self::MESSAGE_ANTIVIRUS;
                }

                continue;
            }

            if (config($reglage) !== $pilote) {
                $service = explode('.', $reglage)[1];
                $refus[] = sprintf(self::MESSAGE_FOURNISSEUR_REEL, $service, strtoupper($service).'_PILOTE', $pilote);
            }
        }

        if (config('mail.default') !== 'demonstration') {
            $refus[] = self::MESSAGE_TRANSPORT_DE_LA_DEMONSTRATION;
        }

        if (! in_array(config('cache.default'), self::CACHE_HORS_D_UNE_COPIE, true) || ! in_array(config('session.driver'), self::SESSION_DANS_UNE_COPIE, true)
            || filled(config('cache.stores.database.connection')) || filled(config('cache.stores.database.lock_connection')) || filled(config('session.connection'))) {
            $refus[] = self::MESSAGE_CACHE_ET_SESSION_DE_LA_DEMONSTRATION;
        }

        foreach (self::SECRETS_DES_VRAIS_SERVICES as $reglage => $variable) {
            if (filled(config($reglage))) {
                $refus[] = sprintf(self::MESSAGE_SECRET_D_UN_VRAI_SERVICE, $variable);
            }
        }

        foreach (self::VARIABLES_DES_VRAIS_SERVICES as $variable) {
            if (filled(Env::get($variable))) {
                $refus[] = sprintf(self::MESSAGE_SECRET_D_UN_VRAI_SERVICE, $variable);
            }
        }

        $cleDeCaisse = $this->empreinteDeLaCleDeCaisse();

        if ($cleDeCaisse !== null && ! in_array($cleDeCaisse, $this->clesDeCaisseDeLaDemonstration(), true)) {
            $refus[] = self::MESSAGE_CLE_DE_CAISSE_DE_LA_DEMONSTRATION;
        }

        // Seule la commande qui épingle ces clés démarre avant qu'elles le
        // soient ; elle n'écrit rien d'autre que leurs empreintes.
        foreach ($this->app->runningConsoleCommand(self::COMMANDE_QUI_EPINGLE_LES_CLES_D_AUDIT) ? [] : self::clesDAudit() as $nom => $cle) {
            if (! in_array(self::empreinteDUneCleDAudit($cle), $this->empreintesDAuditDeLaDemonstration(), true)) {
                $refus[] = sprintf(self::MESSAGE_CLE_D_AUDIT_DE_LA_DEMONSTRATION, $nom);
            }
        }

        if (filled(config('demonstration.messages.cle_privee'))) {
            $refus[] = self::MESSAGE_CLE_PRIVEE_DES_MESSAGES;
        }

        if ($this->serveurQuiGardeLApplicationEnMemoire()) {
            $refus[] = self::MESSAGE_SERVEUR_EN_MEMOIRE;
        }

        $base = ValeurSimple::enTexte(config('database.connections.'.ValeurSimple::enTexte(config('database.default')).'.database'));

        if ($base !== self::BASE_SENTINELLE) {
            $refus[] = sprintf(self::MESSAGE_BASE_SENTINELLE, $base);
        }

        $temoin = ($this->app->isProduction() ? self::PREFIXE_DES_TEMOINS_DE_PRODUCTION : '').self::TEMOIN_DE_SESSION_DE_LA_DEMONSTRATION;

        if (config('session.cookie') !== $temoin) {
            $refus[] = sprintf(self::MESSAGE_TEMOIN_DE_SESSION, $temoin, ValeurSimple::enTexte(config('session.cookie')));
        }

        if ($this->app->isProduction() && ! $this->temoinDeProductionConforme()) {
            $refus[] = self::MESSAGE_TEMOIN_DE_PRODUCTION;
        }

        return $refus;
    }

    /**
     * @return list<string>
     */
    private function refusDuSiteReel(): array
    {
        $refus = [];

        if ($this->app->isProduction()) {
            foreach (FournisseursExternesServiceProvider::PILOTES_FACTICES as $reglage => $pilote) {
                if (config($reglage) === $pilote) {
                    $refus[] = sprintf(self::MESSAGE_FOURNISSEUR_FACTICE_EN_PRODUCTION, explode('.', $reglage)[1], $pilote);
                }
            }
        }

        if (config('mail.default') === 'demonstration') {
            $refus[] = self::MESSAGE_TRANSPORT_DU_SITE_REEL;
        }

        $cleDeCaisse = $this->empreinteDeLaCleDeCaisse();

        if ($cleDeCaisse !== null && in_array($cleDeCaisse, $this->clesDeCaisseDeLaDemonstration(), true)) {
            $refus[] = self::MESSAGE_CLE_DE_CAISSE_DU_SITE_REEL;
        }

        foreach (self::clesDAudit() as $nom => $cle) {
            if (in_array(self::empreinteDUneCleDAudit($cle), $this->empreintesDAuditDeLaDemonstration(), true)) {
                $refus[] = sprintf(self::MESSAGE_CLE_D_AUDIT_DU_SITE_REEL, $nom);
            }
        }

        foreach (self::REGLAGES_DE_LA_DEMONSTRATION as $reglage => $variable) {
            if (filled(config($reglage))) {
                $refus[] = sprintf(self::MESSAGE_REGLAGE_DE_LA_DEMONSTRATION, $variable);
            }
        }

        $base = ValeurSimple::enTexte(config('database.connections.'.ValeurSimple::enTexte(config('database.default')).'.database'));

        if (str_starts_with($base, 'demo_') || $base === 'piliqi_demo_registre') {
            $refus[] = sprintf(self::MESSAGE_BASE_DE_LA_DEMONSTRATION, $base);
        }

        $domaine = config('session.domain');
        $hoteDeLaDemonstration = parse_url(ValeurSimple::enTexte(config('demonstration.url')), PHP_URL_HOST);

        if (is_string($domaine) && $domaine !== '' && is_string($hoteDeLaDemonstration) && self::domaineCouvre($domaine, $hoteDeLaDemonstration)) {
            $refus[] = sprintf(self::MESSAGE_DOMAINE_DE_SESSION, $domaine, $hoteDeLaDemonstration);
        }

        if ($this->app->isProduction()) {
            if (! $this->temoinDeProductionConforme()) {
                $refus[] = self::MESSAGE_TEMOIN_DE_PRODUCTION;
            }

            foreach ((array) config('piliqi.hotes_de_confiance', []) as $hote) {
                $hote = ValeurSimple::enTexte($hote);

                if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $hote) !== 1) {
                    $refus[] = sprintf(self::MESSAGE_HOTE_DE_CONFIANCE, $hote);
                }
            }
        }

        return $refus;
    }

    /**
     * @throws SiteMalConfigureException quand la table des routes du site réel porte une route du site de démonstration
     */
    private function refuserLesRoutesDeLaDemonstration(): null
    {
        $router = $this->app->make(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $nom = $route->getName();

            if (is_string($nom) && str_starts_with($nom, 'demonstration.')) {
                throw new SiteMalConfigureException(sprintf(self::MESSAGE_REFUS, SiteDePiliqi::Reel->libelle(), sprintf(self::MESSAGE_ROUTE_DE_LA_DEMONSTRATION, $nom)));
            }
        }

        return null;
    }

    private function temoinDeProductionConforme(): bool
    {
        return str_starts_with(ValeurSimple::enTexte(config('session.cookie')), self::PREFIXE_DES_TEMOINS_DE_PRODUCTION)
            && config('session.secure') === true
            && blank(config('session.domain'))
            && config('session.path') === '/';
    }

    /**
     * Un domaine de témoin couvre un hôte quand l'hôte est ce domaine ou
     * l'un de ses sous-domaines.
     */
    private static function domaineCouvre(string $domaine, string $hote): bool
    {
        $domaine = strtolower(ltrim($domaine, '.'));
        $hote = strtolower($hote);

        return $hote === $domaine || str_ends_with($hote, '.'.$domaine);
    }

    /**
     * Laravel Octane (Swoole, RoadRunner, FrankenPHP), ou FrankenPHP en
     * mode travailleur : l'application y reste en mémoire d'une requête à
     * l'autre.
     */
    private function serveurQuiGardeLApplicationEnMemoire(): bool
    {
        return $this->app->bound('octane')
            || filled($_SERVER['LARAVEL_OCTANE'] ?? null)
            || filled(Env::get('LARAVEL_OCTANE'))
            || filled(Env::get('RR_MODE'))
            || function_exists('frankenphp_handle_request');
    }

    /**
     * Le cle_id de la clé privée de la caisse, ou null sans clé ; une clé
     * illisible laisse SignataireFiscal la refuser à la première
     * signature, comme caisse:diagnostiquer.
     */
    private function empreinteDeLaCleDeCaisse(): ?string
    {
        return rescue(fn (): ?string => (new SignataireFiscal($this->configuration()))->empreinteDeLaClePrivee(), null, false);
    }

    /**
     * Les cle_id des clés de caisse épinglées pour la démonstration.
     *
     * @return list<string>
     */
    private function clesDeCaisseDeLaDemonstration(): array
    {
        $configuration = new Repository(['caisse_securisee' => ['cles_publiques' => (array) config('caisse_securisee.cles_publiques_de_demonstration', [])]]);

        return array_keys((new SignataireFiscal($configuration))->clesEpinglees());
    }

    /**
     * Les clés du journal d'audit configurées, courante et précédentes,
     * par leur nom dans le message de refus.
     *
     * @return array<string, string>
     */
    public static function clesDAudit(): array
    {
        $cles = [];
        $courante = config('journal_audit.cle');

        if (is_string($courante) && trim($courante) !== '') {
            $cles['courante (JOURNAL_AUDIT_CLE)'] = $courante;
        }

        foreach ((array) config('journal_audit.cles_precedentes', []) as $identifiant => $cle) {
            if (is_string($cle) && trim($cle) !== '') {
                $cles['précédente « '.ValeurSimple::enTexte($identifiant).' » (JOURNAL_AUDIT_CLES_PRECEDENTES)'] = $cle;
            }
        }

        return $cles;
    }

    /**
     * @return list<string>
     */
    private function empreintesDAuditDeLaDemonstration(): array
    {
        return array_values(array_filter((array) config('journal_audit.empreintes_de_demonstration', []), is_string(...)));
    }

    private function configuration(): Configuration
    {
        return $this->app->make(Configuration::class);
    }
}
