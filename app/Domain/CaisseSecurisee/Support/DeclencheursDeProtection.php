<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les déclencheurs SQL par lesquels la base refuse elle-même de modifier,
 * de supprimer ou de vider les lignes d'une table, même par une requête
 * qui ne passe pas par le modèle (DB::table(), update(), delete(),
 * truncate() ou forceDelete() du constructeur de requêtes, requête
 * brute). Appelé dans le up() de la migration qui crée la table :
 * - ajoutSeul() : table où l'on ne fait qu'ajouter (art. 286 I-3° bis du
 *   CGI), ni modification, ni suppression, ni vidage ;
 * - sansSuppression() : table dont aucune ligne ne s'efface ; elle se
 *   modifie, et se marque supprimée (deleted_at) quand son modèle le
 *   prévoit, mais ne se vide pas ;
 * - effacementApresExpiration() : table dont une ligne ne se modifie
 *   jamais et ne s'efface qu'une fois sa colonne expire_le passée (les
 *   traces techniques, voir TraceEvenement) ; elle ne se vide pas. L'heure
 *   de comparaison est celle du serveur de base de données, en UTC, comme
 *   expire_le (voir HorodatageUtc).
 *
 * Seuls PostgreSQL (production) et SQLite (développement et tests) sont
 * pris en charge : sur tout autre pilote, la migration échoue au lieu de
 * laisser la table sans protection.
 * - PostgreSQL : une fonction plpgsql de refus propre à la table
 *   (refus_<table>), appelée avec son message par des déclencheurs BEFORE
 *   UPDATE et BEFORE DELETE pour chaque ligne et BEFORE TRUNCATE pour
 *   l'instruction ; celle d'une table à effacement après expiration laisse
 *   passer la suppression d'une ligne expirée. Le TRUNCATE ... CASCADE de
 *   Laravel déclenche aussi le refus des tables protégées qu'il viderait
 *   par cascade.
 * - SQLite : des déclencheurs BEFORE UPDATE et BEFORE DELETE, ce dernier
 *   limité aux lignes non expirées pour une table à effacement après
 *   expiration ; SQLite n'a pas de TRUNCATE, et le truncate() de Laravel y
 *   devient un DELETE, que le déclencheur refuse.
 *
 * Restent hors de leur portée, sous PostgreSQL, DROP TABLE et ALTER TABLE
 * ... DISABLE TRIGGER, permis au seul propriétaire de la table : en
 * production, le compte de l'application n'est donc pas celui qui lance
 * les migrations, et il n'a ni UPDATE, ni DELETE, ni TRUNCATE sur les
 * tables en ajout seul (voir CLAUDE.md, étape 15). Sur une table en ajout
 * seul, la signature chaînée des enregistrements révèle de toute façon
 * ce qui aurait été altéré.
 *
 * Les déclencheurs disparaissent avec leur table ; la fonction de refus,
 * propre à la table sous PostgreSQL, se retire par retirer() dans le
 * down() de la migration, après la table.
 */
final class DeclencheursDeProtection
{
    public static function ajoutSeul(string $table): void
    {
        self::poser($table, 'ajout_seul', 'refuser toute modification, toute suppression et tout vidage', [
            'UPDATE' => "{$table} : table en ajout seul, modification refusée",
            'DELETE' => "{$table} : table en ajout seul, suppression refusée",
            'TRUNCATE' => "{$table} : table en ajout seul, vidage refusé",
        ]);
    }

    public static function sansSuppression(string $table): void
    {
        self::poser($table, 'sans_suppression', 'refuser toute suppression définitive et tout vidage', [
            'DELETE' => "{$table} : suppression définitive refusée",
            'TRUNCATE' => "{$table} : vidage refusé",
        ]);
    }

    public static function effacementApresExpiration(string $table): void
    {
        self::poser($table, 'effacement_apres_expiration', 'refuser toute modification, tout vidage et toute suppression avant expiration', [
            'UPDATE' => "{$table} : modification refusée",
            'DELETE' => "{$table} : suppression refusée avant expiration",
            'TRUNCATE' => "{$table} : vidage refusé",
        ], effacementApresExpiration: true);
    }

    /**
     * Retire ce qui survit à la table protégée, une fois celle-ci
     * supprimée : sa fonction de refus sous PostgreSQL, rien sous SQLite.
     * Sans CASCADE : tant que la table existe, ses déclencheurs dépendent
     * de la fonction et PostgreSQL refuse de la retirer.
     */
    public static function retirer(string $table): void
    {
        self::verifierLeNom($table);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::connection()->unprepared("DROP FUNCTION IF EXISTS refus_{$table}()");
        }
    }

    /**
     * @param  string  $protection  le suffixe du nom des déclencheurs
     * @param  string  $exigence  ce que la table doit refuser, pour le message d'un pilote non pris en charge
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus  le message de refus de chaque opération
     * @param  bool  $effacementApresExpiration  vrai pour laisser passer la suppression d'une ligne dont expire_le est passée
     */
    private static function poser(string $table, string $protection, string $exigence, array $refus, bool $effacementApresExpiration = false): void
    {
        self::verifierLeNom($table);

        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($table, $protection, $refus, $effacementApresExpiration),
            'sqlite' => self::instructionsSqlite($table, $protection, $refus, $effacementApresExpiration),
            default => throw new RuntimeException("La table {$table} doit {$exigence} : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * La fonction de refus laisse passer, pour une table à effacement après
     * expiration, la suppression d'une ligne dont expire_le, en UTC, est
     * passée à l'heure UTC du serveur.
     *
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsPostgres(string $table, string $protection, array $refus, bool $effacementApresExpiration): array
    {
        $ligneExpireeEffacee = $effacementApresExpiration
            ? "IF TG_OP = 'DELETE' THEN IF OLD.expire_le IS NOT NULL AND OLD.expire_le <= (now() AT TIME ZONE 'UTC') THEN RETURN OLD; END IF; END IF; "
            : '';
        $instructions = [
            "CREATE OR REPLACE FUNCTION refus_{$table}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$ligneExpireeEffacee}RAISE EXCEPTION '%', TG_ARGV[0]; END \$\$",
        ];

        foreach ($refus as $operation => $message) {
            $portee = $operation === 'TRUNCATE' ? 'STATEMENT' : 'ROW';

            $instructions[] = "CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table} FOR EACH {$portee} EXECUTE FUNCTION refus_{$table}('{$message}')";
        }

        return $instructions;
    }

    /**
     * Pour une table à effacement après expiration, le déclencheur BEFORE
     * DELETE ne refuse que la suppression d'une ligne sans expire_le ou
     * dont expire_le, en UTC (« AAAA-MM-JJ HH:MM:SS »), n'est pas encore
     * passée à CURRENT_TIMESTAMP, l'heure UTC de SQLite au même format.
     *
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsSqlite(string $table, string $protection, array $refus, bool $effacementApresExpiration): array
    {
        $instructions = [];

        foreach (array_diff_key($refus, ['TRUNCATE' => true]) as $operation => $message) {
            $condition = $effacementApresExpiration && $operation === 'DELETE'
                ? ' WHEN OLD.expire_le IS NULL OR OLD.expire_le > CURRENT_TIMESTAMP'
                : '';
            $instructions[] = "CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table}{$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        }

        return $instructions;
    }

    private static function verifierLeNom(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Nom de table invalide pour un déclencheur : {$table}.");
        }
    }
}
