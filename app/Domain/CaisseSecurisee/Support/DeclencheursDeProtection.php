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
 *   prévoit, mais ne se vide pas.
 *
 * Seuls PostgreSQL (production) et SQLite (développement et tests) sont
 * pris en charge : sur tout autre pilote, la migration échoue au lieu de
 * laisser la table sans protection.
 * - PostgreSQL : une fonction plpgsql de refus propre à la table
 *   (refus_<table>), appelée avec son message par des déclencheurs BEFORE
 *   UPDATE et BEFORE DELETE pour chaque ligne et BEFORE TRUNCATE pour
 *   l'instruction. Le TRUNCATE ... CASCADE de Laravel déclenche aussi le
 *   refus des tables protégées qu'il viderait par cascade.
 * - SQLite : des déclencheurs BEFORE UPDATE et BEFORE DELETE ; SQLite n'a
 *   pas de TRUNCATE, et le truncate() de Laravel y devient un DELETE, que
 *   le déclencheur refuse.
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
     */
    private static function poser(string $table, string $protection, string $exigence, array $refus): void
    {
        self::verifierLeNom($table);

        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($table, $protection, $refus),
            'sqlite' => self::instructionsSqlite($table, $protection, $refus),
            default => throw new RuntimeException("La table {$table} doit {$exigence} : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsPostgres(string $table, string $protection, array $refus): array
    {
        $instructions = [
            "CREATE OR REPLACE FUNCTION refus_{$table}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '%', TG_ARGV[0]; END \$\$",
        ];

        foreach ($refus as $operation => $message) {
            $portee = $operation === 'TRUNCATE' ? 'STATEMENT' : 'ROW';

            $instructions[] = "CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table} FOR EACH {$portee} EXECUTE FUNCTION refus_{$table}('{$message}')";
        }

        return $instructions;
    }

    /**
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsSqlite(string $table, string $protection, array $refus): array
    {
        $instructions = [];

        foreach (array_diff_key($refus, ['TRUNCATE' => true]) as $operation => $message) {
            $instructions[] = "CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
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
