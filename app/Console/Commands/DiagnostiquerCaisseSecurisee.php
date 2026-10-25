<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Calendrier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Commande de l'éditeur : vérifie qu'un serveur peut faire fonctionner la
 * caisse sécurisée, contrôle par contrôle, et échoue au moindre défaut.
 *
 * - openssl et sa courbe P-256, qui portent les signatures ;
 * - zip, qui produit les archives fiscales ;
 * - les clés publiques épinglées, toutes lisibles ;
 * - la clé privée de l'environnement, lisible et épinglée, qui signe un
 *   contenu d'essai vérifié avec la clé épinglée ;
 * - la version du module fiscal ;
 * - le fuseau de l'application, UTC (voir Calendrier::exigerUnFuseauUtc()) ;
 * - sous PostgreSQL, le fuseau par défaut de la base, que reçoit toute
 *   session avant que l'application ne fixe le sien (ALTER DATABASE,
 *   ALTER ROLE, postgresql.conf ou options de connexion) : un nom de
 *   l'UTC, lu dans pg_settings.reset_val, jamais par SHOW TIMEZONE, qui
 *   rend l'UTC que l'application fixe à chaque connexion.
 */
#[Signature('caisse:diagnostiquer')]
#[Description('Vérifie que la caisse sécurisée peut signer : extensions, clés épinglées, clé de signature, version du module fiscal, fuseaux de l\'application et de la base')]
class DiagnostiquerCaisseSecurisee extends Command
{
    /**
     * Les noms sous lesquels PostgreSQL désigne l'UTC.
     */
    private const NOMS_DE_L_UTC = ['UTC', 'Etc/UTC'];

    public function handle(SignataireFiscal $signataire): int
    {
        $controles = [
            'Extension openssl' => fn (): string => $this->controlerOpenssl(),
            'Extension zip' => fn (): string => $this->controlerZip(),
            'Clés publiques épinglées' => fn (): string => $this->controlerClesEpinglees($signataire),
            'Clé de signature' => fn (): string => $this->controlerCleDeSignature($signataire),
            'Version du module fiscal' => fn (): string => ModuleFiscal::VERSION,
            'Fuseau de l\'application' => fn (): string => $this->controlerLeFuseauDeLApplication(),
            'Fuseau par défaut de la base' => fn (): string => $this->controlerLeFuseauParDefautDeLaBase(),
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

    private function controlerLeFuseauParDefautDeLaBase(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'SQLite, sans fuseau de session';
        }

        $fuseau = (string) DB::selectOne("select reset_val from pg_settings where name = 'TimeZone'")->reset_val;

        if (! in_array($fuseau, self::NOMS_DE_L_UTC, true)) {
            throw new RuntimeException("Le fuseau par défaut de la base est « {$fuseau} » : fixez-le à UTC (ALTER DATABASE … SET timezone TO 'UTC'), pour les sessions ouvertes hors de l'application.");
        }

        return $fuseau;
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
