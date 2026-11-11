<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\Reglage;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Providers\AntivirusServiceProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
 * - les contrôles des fichiers reçus (lot FI, voir ControleDesFichiers) :
 *   l'extension gd, qui réenregistre les photos ; qpdf, qui lit les PDF
 *   (QPDF_BINAIRE) ; l'antivirus, qui doit reconnaître le fichier de test
 *   EICAR, et ne jamais être l'antivirus factice en production.
 */
#[Signature('caisse:diagnostiquer')]
#[Description('Vérifie que la caisse sécurisée peut signer et le journal d\'audit s\'inscrire, et que les fichiers reçus se contrôlent : extensions, génération d\'une clé, clés épinglées, clé de signature, clé du journal d\'audit, version du module fiscal, fuseaux de l\'application et de la base, gd, qpdf, antivirus')]
class DiagnostiquerCaisseSecurisee extends Command
{
    public function handle(SignataireFiscal $signataire, Antivirus $antivirus): int
    {
        $controles = [
            'Extension openssl' => fn (): string => $this->controlerOpenssl(),
            'Génération d\'une clé P-256' => fn (): string => $this->controlerLaGenerationDUneCle(),
            'Extension zip' => fn (): string => $this->controlerZip(),
            'Clés publiques épinglées' => fn (): string => $this->controlerClesEpinglees($signataire),
            'Clé de signature' => fn (): string => $this->controlerCleDeSignature($signataire),
            'Clé du journal d\'audit' => fn (): string => $this->controlerLaCleDuJournalAudit(),
            'Version du module fiscal' => fn (): string => ModuleFiscal::VERSION,
            'Fuseau de l\'application' => fn (): string => $this->controlerLeFuseauDeLApplication(),
            'Fuseau par défaut de la base' => fn (): string => FuseauParDefautDeLaBase::exigerUtc(DB::connection()),
            'Extension gd' => fn (): string => $this->controlerGd(),
            'Analyseur des PDF (qpdf)' => fn (): string => $this->controlerQpdf(),
            'Antivirus' => fn (): string => $this->controlerLAntivirus($antivirus),
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
     * qpdf, qui lit en entier chaque PDF reçu (voir AnalyseurDePdf).
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

        return strtok(trim($processus->getOutput()), "\n") ?: $binaire;
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
