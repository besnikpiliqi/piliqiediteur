<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Tenancy\Services\JournalAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
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
 *   FuseauParDefautDeLaBase).
 */
#[Signature('caisse:diagnostiquer')]
#[Description('Vérifie que la caisse sécurisée peut signer et le journal d\'audit s\'inscrire : extensions, génération d\'une clé, clés épinglées, clé de signature, clé du journal d\'audit, version du module fiscal, fuseaux de l\'application et de la base')]
class DiagnostiquerCaisseSecurisee extends Command
{
    public function handle(SignataireFiscal $signataire): int
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
            throw new RuntimeException('Aucune clé publique épinglée dans '.config('caisse_securisee.dossier_cles_publiques').'.');
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
