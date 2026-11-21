<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Support\HorlogeDuSysteme;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Fichiers\AnalyseurDePdf;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Providers\AntivirusServiceProvider;
use App\Providers\SiteDePiliqiServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Commande de l'éditeur : vérifie qu'un serveur peut faire fonctionner la
 * caisse sécurisée et le journal d'audit, contrôle par contrôle, et
 * échoue au moindre défaut, sans jamais afficher une clé.
 *
 * - openssl et sa courbe P-256, qui portent les signatures ;
 * - la génération et l'export d'une clé P-256 d'essai, aussitôt jetée,
 *   qu'OpenSSL refuse sous Windows tant que la variable OPENSSL_CONF ne
 *   désigne pas son fichier openssl.cnf (le message dit alors la marche à
 *   suivre, voir ErreursOpenSsl) ;
 * - zip, qui produit les archives fiscales ;
 * - les clés publiques épinglées, toutes lisibles ;
 * - la clé privée de l'environnement, lisible et épinglée, qui signe un
 *   contenu d'essai vérifié avec la clé épinglée ;
 * - la clé du journal d'audit et son identifiant (JOURNAL_AUDIT_CLE,
 *   JOURNAL_AUDIT_CLE_ID), sans lesquels aucune action auditée ne
 *   s'enregistre, confirmation d'une inscription comprise ;
 * - la version du module fiscal ;
 * - le fuseau de l'application, UTC (voir Calendrier::exigerUnFuseauUtc()) ;
 * - le fuseau par défaut de la base, que les migrations fixent à UTC ; en
 *   échec, sa cause et la commande qui la corrige (voir
 *   FuseauParDefautDeLaBase) ;
 * - l'horloge de la machine, comparée à celle du serveur de base, corrigée
 *   de la moitié de l'aller-retour : au-delà d'une demi-seconde d'écart,
 *   une opération horodatée sur une machine en retard après une autre
 *   serait refusée (voir HorlogeRegressiveException) ; le diagnostic se
 *   lance sur chaque machine qui écrit au registre fiscal (serveur web,
 *   travailleurs des files, planificateur), chacune synchronisée par NTP
 *   (décision de l'éditeur, Q-AUD5) ;
 * - les contrôles des fichiers reçus (lot FI, voir ControleDesFichiers) :
 *   l'extension gd, qui réenregistre les photos ; qpdf, qui lit les PDF
 *   (QPDF_BINAIRE), en version 11 au moins ; l'antivirus, qui doit
 *   reconnaître le fichier de test EICAR, et ne jamais être l'antivirus
 *   factice en production ;
 * - le site servi (PILIQI_SITE) et la marque de la base : « reel » sur le
 *   site réel, la base sentinelle vide sur le site de démonstration (voir
 *   MarqueDeLaBase) ; en production, le serveur PostgreSQL du site réel
 *   n'a aucune base du site de démonstration et son rôle ne crée pas de
 *   base (CREATEDB) : le site de démonstration a sa machine et son serveur
 *   à lui (lot DEMO-COPIE, choix 9) ;
 * - la vérification de l'isolation de toute la base (voir
 *   VerifierLIsolation), qui doit avoir réussi, sans aucun problème, depuis
 *   moins de 36 heures : la tâche de chaque nuit qui ne tourne plus, ou qui
 *   trouve des problèmes, ne passe jamais inaperçue.
 */
#[Signature('caisse:diagnostiquer')]
#[Description('Vérifie que la caisse sécurisée peut signer et le journal d\'audit s\'inscrire, et que les fichiers reçus se contrôlent : extensions, génération d\'une clé, clés épinglées, clé de signature, clé du journal d\'audit, version du module fiscal, fuseaux de l\'application et de la base, horloge du serveur, gd, qpdf, antivirus, vérification de l\'isolation depuis moins de 36 heures')]
class DiagnostiquerCaisseSecurisee extends Command
{
    /**
     * L'écart, en secondes, au-delà duquel l'horloge de la machine est en
     * échec.
     */
    public const ECART_MAXIMAL_DE_L_HORLOGE = 0.5;

    public const MESSAGE_BASES_DE_LA_DEMONSTRATION = 'Le serveur PostgreSQL du site réel porte %d base(s) du site de démonstration (demo_…) : en production, le site de démonstration a son serveur à lui.';

    public const MESSAGE_ROLE_QUI_CREE_DES_BASES = 'Le rôle PostgreSQL du site réel peut créer des bases (CREATEDB) : retirez-lui ce droit (ALTER ROLE … NOCREATEDB), qui n\'existe que sur le serveur du site de démonstration.';

    public function handle(SignataireFiscal $signataire, Antivirus $antivirus, HorlogeDuSysteme $horloge): int
    {
        $controles = [
            'Site servi' => fn (): string => SiteDePiliqi::courant()->libelle(),
            'Marque de la base' => fn (): string => self::controlerLaMarqueDeLaBase(),
            'Extension openssl' => fn (): string => $this->controlerOpenssl(),
            'Génération d\'une clé P-256' => fn (): string => $this->controlerLaGenerationDUneCle(),
            'Extension zip' => fn (): string => $this->controlerZip(),
            'Clés publiques épinglées' => fn (): string => $this->controlerClesEpinglees($signataire),
            'Clé de signature' => fn (): string => $this->controlerCleDeSignature($signataire),
            'Clé du journal d\'audit' => fn (): string => $this->controlerLaCleDuJournalAudit(),
            'Version du module fiscal' => fn (): string => ModuleFiscal::VERSION,
            'Fuseau de l\'application' => fn (): string => $this->controlerLeFuseauDeLApplication(),
            'Fuseau par défaut de la base' => fn (): string => FuseauParDefautDeLaBase::exigerUtc(DB::connection()),
            'Horloge du serveur' => fn (): string => $this->controlerLHorloge($horloge),
            'Extension gd' => fn (): string => $this->controlerGd(),
            'Analyseur des PDF (qpdf)' => fn (): string => $this->controlerQpdf(),
            'Antivirus' => fn (): string => $this->controlerLAntivirus($antivirus),
            'Vérification de l\'isolation' => fn (): string => self::controlerLaVerificationDeLIsolation(),
        ];

        $lignes = [];
        $echecs = 0;

        foreach ($controles as $controle => $controler) {
            try {
                $lignes[] = [$controle, 'OK', $controler()];
            } catch (RuntimeException $exception) {
                $echecs++;
                $lignes[] = [$controle, 'ÉCHEC', $exception->getMessage()];
            }
        }

        $this->table(['Contrôle', 'État', 'Détail'], $lignes);

        if ($echecs > 0) {
            $this->error("Caisse sécurisée non opérationnelle : {$echecs} contrôle(s) en échec.");

            return self::FAILURE;
        }

        $this->info('Caisse sécurisée opérationnelle.');

        return self::SUCCESS;
    }

    /**
     * Sur le site réel, la base porte la marque « reel » (voir
     * MarqueDeLaBase) : jamais une base du site de démonstration. Sur le
     * site de démonstration, la base par défaut est la base sentinelle,
     * qui reste sans aucune table (chaque copie porte sa propre marque, que
     * chaque requête contrôle).
     *
     * @throws SiteMalConfigureException
     */
    private static function controlerLaMarqueDeLaBase(): string
    {
        $connexion = DB::connection();

        if (SiteDePiliqi::estLaDemonstration()) {
            $refus = SiteDePiliqiServiceProvider::refusDeLaBaseSentinelle($connexion);

            if ($refus !== null) {
                throw new SiteMalConfigureException(sprintf(SiteDePiliqiServiceProvider::MESSAGE_REFUS, SiteDePiliqi::Demonstration->libelle(), $refus));
            }

            return 'base sentinelle '.$connexion->getDatabaseName().', sans table';
        }

        MarqueDeLaBase::exigerPour(SiteDePiliqi::Reel, $connexion);

        if (app()->isProduction()) {
            $bases = ValeurSimple::enEntier($connexion->scalar("SELECT count(*) FROM pg_database WHERE datname ~ '^demo_'"));
            $creeDesBases = $connexion->scalar('SELECT rolcreatedb FROM pg_roles WHERE rolname = current_user') === true;

            if ($bases > 0 || $creeDesBases) {
                throw new SiteMalConfigureException(trim(($bases > 0 ? sprintf(self::MESSAGE_BASES_DE_LA_DEMONSTRATION, $bases) : '').' '.($creeDesBases ? self::MESSAGE_ROLE_QUI_CREE_DES_BASES : '')));
            }
        }

        return 'reel';
    }

    /**
     * La dernière vérification de l'isolation sans aucun problème (voir
     * VerifierLIsolation) date de moins de 36 heures. Elle se retient dans
     * le cache de l'application : un cache vidé (php artisan cache:clear ou
     * optimize:clear) l'oublie jusqu'au passage suivant.
     */
    private static function controlerLaVerificationDeLIsolation(): string
    {
        $derniereReussite = Cache::get(VerifierLIsolation::CLE_DE_LA_DERNIERE_REUSSITE);

        if (! is_int($derniereReussite)) {
            throw new RuntimeException('Aucune vérification de l\'isolation n\'a réussi ici, ou le cache de l\'application a été vidé depuis : lancez php artisan app:verifier-isolation, et vérifiez que le planificateur tourne (php artisan schedule:work, ou la tâche cron de php artisan schedule:run), pour qu\'elle passe chaque nuit à 4 h 40.');
        }

        $instant = CarbonImmutable::createFromTimestamp($derniereReussite);
        $libelle = Calendrier::deParis()->afficher($instant, 'd/m/Y à H:i').' (heure de Paris)';

        if ($instant->lessThan(now()->subHours(VerifierLIsolation::HEURES_SANS_REUSSITE))) {
            throw new RuntimeException('La vérification de l\'isolation n\'a pas réussi depuis plus de '.VerifierLIsolation::HEURES_SANS_REUSSITE." heures (dernière vérification sans problème le {$libelle}) : lisez le journal de l'application et les alertes reçues, corrigez la cause, puis lancez php artisan app:verifier-isolation.");
        }

        return "dernière vérification sans problème le {$libelle}";
    }

    /**
     * L'extension gd, qui décode et réenregistre les photos reçues (voir
     * ReenregistrementDesImages), en JPEG et en PNG.
     */
    private function controlerGd(): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('imagepng')) {
            throw new RuntimeException('L\'extension gd (avec JPEG et PNG) est absente : les photos reçues ne peuvent pas être réenregistrées, et toute photo est refusée.');
        }

        return 'version '.phpversion('gd');
    }

    /**
     * qpdf, qui lit en entier chaque PDF reçu (voir AnalyseurDePdf), dans
     * une version qui donne la description qu'il lit.
     */
    private function controlerQpdf(): string
    {
        $binaire = Reglage::texte('piliqi.qpdf');

        try {
            $processus = new Process([$binaire, '--version']);
            $processus->setTimeout(10);
            $processus->run();
        } catch (ProcessExceptionInterface $erreur) {
            throw new RuntimeException("qpdf ne se lance pas ({$binaire}) : {$erreur->getMessage()}. Installez qpdf ou indiquez son chemin dans QPDF_BINAIRE : sans lui, tout PDF reçu est refusé.");
        }

        if (! $processus->isSuccessful()) {
            throw new RuntimeException("qpdf ne se lance pas ({$binaire}). Installez qpdf ou indiquez son chemin dans QPDF_BINAIRE : sans lui, tout PDF reçu est refusé.");
        }

        $version = AnalyseurDePdf::premiereLigne($processus->getOutput());

        if (! AnalyseurDePdf::versionAdmise($version)) {
            throw new RuntimeException("qpdf {$binaire} répond « {$version} » : l'analyse des PDF demande qpdf ".AnalyseurDePdf::VERSION_MINIMALE_DE_QPDF.' ou plus (sa description JSON version 2). Installez une version récente (README, point 4) : sans elle, tout PDF reçu est refusé.');
        }

        return $version;
    }

    /**
     * L'antivirus répond et reconnaît le fichier de test EICAR ; jamais
     * l'antivirus factice en production.
     */
    private function controlerLAntivirus(Antivirus $antivirus): string
    {
        if (app()->isProduction() && AntivirusServiceProvider::pilote() === AntivirusServiceProvider::FACTICE) {
            throw new RuntimeException('L\'antivirus factice (ANTIVIRUS_PILOTE=factice) ne sert jamais en production : installez ClamAV et réglez ANTIVIRUS_PILOTE=clamav.');
        }

        try {
            $description = $antivirus->description();
            $resultat = $antivirus->analyser(AntivirusFactice::fichierDeTest());
        } catch (AntivirusInjoignableException $injoignable) {
            throw new RuntimeException($injoignable->getMessage().' Sans antivirus, tout dépôt de fichier est refusé.');
        }

        if ($resultat->estSain()) {
            throw new RuntimeException("L'antivirus ({$description}) ne reconnaît pas le fichier de test EICAR : ses signatures ne sont pas chargées.");
        }

        return "{$description}, fichier de test EICAR reconnu ({$resultat->menace})";
    }

    private function controlerOpenssl(): string
    {
        if (! in_array(SignataireFiscal::COURBE, openssl_get_curve_names() ?: [], true)) {
            throw new RuntimeException('La courbe P-256 (prime256v1) est indisponible.');
        }

        return OPENSSL_VERSION_TEXT.', courbe P-256 disponible';
    }

    /**
     * Une paire de clés P-256 d'essai se génère et sa clé privée
     * s'exporte ; elle n'est ni affichée ni gardée.
     */
    private function controlerLaGenerationDUneCle(): string
    {
        SignataireFiscal::genererUnePaire();

        return 'clé d\'essai générée et exportée, puis jetée';
    }

    private function controlerZip(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException("L'extension zip est absente : les archives fiscales ne peuvent pas être produites.");
        }

        return 'version '.phpversion('zip');
    }

    private function controlerClesEpinglees(SignataireFiscal $signataire): string
    {
        $cleIds = array_keys($signataire->clesEpinglees());

        if ($cleIds === []) {
            throw new RuntimeException('Aucune clé publique épinglée dans '.Reglage::texte('caisse_securisee.dossier_cles_publiques').'.');
        }

        return implode(', ', $cleIds);
    }

    /**
     * L'écart entre l'horloge de la machine et celle du serveur de base,
     * lue au milieu de l'aller-retour de la requête.
     */
    private function controlerLHorloge(HorlogeDuSysteme $horloge): string
    {
        $avant = $horloge->secondes();
        $lue = DB::scalar("select date_part('epoch', clock_timestamp())");
        $base = is_numeric($lue) ? (float) $lue : throw new RuntimeException('Le serveur de base n\'a pas donné son heure.');
        $apres = $horloge->secondes();
        $ecart = ($avant + $apres) / 2 - $base;
        $ecartLu = number_format($ecart, 3, ',', ' ');

        if (abs($ecart) > self::ECART_MAXIMAL_DE_L_HORLOGE) {
            throw new RuntimeException("L'horloge de ce serveur s'écarte de {$ecartLu} s de celle du serveur de base (une demi-seconde au plus) : une opération horodatée sur une machine en retard, après une autre en avance, serait refusée par le registre fiscal. Synchronisez l'horloge de chaque machine qui écrit au registre fiscal (serveur web, travailleurs des files, planificateur) par NTP (timedatectl set-ntp true, ou chrony), puis relancez caisse:diagnostiquer sur chacune ; une horloge partie en avance ne se corrige jamais en réécrivant l'heure du registre : on attend que l'heure réelle la rattrape.");
        }

        return "écart de {$ecartLu} s avec le serveur de base (une demi-seconde au plus)";
    }

    private function controlerLeFuseauDeLApplication(): string
    {
        try {
            Calendrier::exigerUnFuseauUtc();
        } catch (LogicException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        return 'UTC';
    }

    /**
     * La clé du journal d'audit et son identifiant sont configurés ; ni
     * l'une ni l'autre ne s'affiche.
     */
    private function controlerLaCleDuJournalAudit(): string
    {
        if (! JournalAudit::estConfigure()) {
            throw new RuntimeException('La clé du journal d\'audit (JOURNAL_AUDIT_CLE, au moins 32 octets aléatoires en base64) ou son identifiant (JOURNAL_AUDIT_CLE_ID) est absent ou invalide : aucune action auditée ne s\'enregistre, confirmation d\'une inscription comprise. '
                .'Générez une clé par php -r "echo base64_encode(random_bytes(32)), PHP_EOL;", placez-la dans JOURNAL_AUDIT_CLE et donnez-lui un identifiant de votre choix dans JOURNAL_AUDIT_CLE_ID.');
        }

        return 'clé (JOURNAL_AUDIT_CLE) et identifiant (JOURNAL_AUDIT_CLE_ID) configurés, valeurs non affichées';
    }

    private function controlerCleDeSignature(SignataireFiscal $signataire): string
    {
        $contenu = 'caisse:diagnostiquer '.now()->toIso8601String();

        if (! $signataire->verifier($contenu, $signataire->signer($contenu), $signataire->cleId())) {
            throw new RuntimeException('La signature produite ne se vérifie pas avec la clé épinglée.');
        }

        return "cle_id {$signataire->cleId()}, signature ".SignataireFiscal::ALGORITHME.' vérifiée avec la clé épinglée';
    }
}
