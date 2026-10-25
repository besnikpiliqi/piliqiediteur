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
 *   expire_le (voir Instant) ;
 * - suppressionApresConservation() : table dont une ligne se modifie (son
 *   suivi), mais ne s'efface qu'une fois passée la fin de sa conservation,
 *   une colonne nulle tant qu'elle n'est pas fixée (les codes de courrier,
 *   voir CodeConfirmation) ; elle ne se vide pas. Même heure de
 *   comparaison.
 *
 * Seuls PostgreSQL (production) et SQLite (développement et tests) sont
 * pris en charge : sur tout autre pilote, la migration échoue au lieu de
 * laisser la table sans protection.
 * - PostgreSQL : une fonction plpgsql de refus propre à la table
 *   (refus_<table>), appelée avec son message par des déclencheurs BEFORE
 *   UPDATE et BEFORE DELETE pour chaque ligne et BEFORE TRUNCATE pour
 *   l'instruction ; celle d'une table à effacement après expiration ou
 *   après conservation laisse passer la suppression d'une ligne expirée.
 *   Le TRUNCATE ... CASCADE de Laravel déclenche aussi le refus des tables
 *   protégées qu'il viderait par cascade.
 * - SQLite : des déclencheurs BEFORE UPDATE et BEFORE DELETE, ce dernier
 *   limité aux lignes non expirées pour une table à effacement après
 *   expiration ou après conservation ; SQLite n'a pas de TRUNCATE, et le truncate() de Laravel y
 *   devient un DELETE, que le déclencheur refuse. Un INSERT OR REPLACE,
 *   qui efface la ligne en conflit pour écrire la sienne, ne lance les
 *   déclencheurs DELETE que si recursive_triggers est actif : la
 *   connexion SQLite de l'application l'active (voir config/database.php),
 *   et le déclencheur refuse alors le remplacement comme une suppression.
 *   Sous PostgreSQL, un INSERT ... ON CONFLICT DO UPDATE est une
 *   modification, que BEFORE UPDATE refuse.
 *
 * Restent hors de leur portée, sous PostgreSQL, DROP TABLE et ALTER TABLE
 * ... DISABLE TRIGGER, permis au seul propriétaire de la table : en
 * production, le compte de l'application n'est donc pas celui qui lance
 * les migrations, et il n'a ni UPDATE, ni DELETE, ni TRUNCATE sur les
 * tables en ajout seul (voir CLAUDE.md, étape 15) ; sous SQLite (le
 * développement et les tests), une autre connexion au fichier qui
 * n'active pas recursive_triggers. Sur une table en ajout seul, la
 * signature chaînée des enregistrements révèle de toute façon ce qui
 * aurait été altéré.
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
        ], colonneDExpiration: 'expire_le');
    }

    /**
     * @param  string  $colonne  la fin de la conservation d'une ligne, nulle tant qu'elle n'est pas fixée
     */
    public static function suppressionApresConservation(string $table, string $colonne): void
    {
        self::poser($table, 'suppression_apres_conservation', 'refuser tout vidage et toute suppression avant la fin de la conservation', [
            'DELETE' => "{$table} : suppression refusée avant la fin de la conservation",
            'TRUNCATE' => "{$table} : vidage refusé",
        ], colonneDExpiration: $colonne);
    }

    /**
     * La condition SQL, au pilote de la connexion, à laquelle le
     * déclencheur d'une table à effacement après expiration laisse effacer
     * une ligne : expire_le passée à l'heure UTC du serveur de base. Qui
     * efface ces lignes (voir app:purger-traces) les choisit par elle :
     * l'heure de l'application, que peut devancer celle du serveur de base,
     * ne ferait refuser tout un lot pour une seule ligne.
     */
    public static function conditionDExpiration(): string
    {
        $pilote = DB::connection()->getDriverName();

        if (! in_array($pilote, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite).");
        }

        return self::ligneExpiree($pilote, 'expire_le');
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
     * @param  string|null  $colonneDExpiration  la colonne au-delà de laquelle la suppression d'une ligne passe, null pour la refuser toujours
     */
    private static function poser(string $table, string $protection, string $exigence, array $refus, ?string $colonneDExpiration = null): void
    {
        self::verifierLeNom($table);

        if ($colonneDExpiration !== null) {
            self::verifierLeNom($colonneDExpiration);
        }

        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($table, $protection, $refus, $colonneDExpiration),
            'sqlite' => self::instructionsSqlite($table, $protection, $refus, $colonneDExpiration),
            default => throw new RuntimeException("La table {$table} doit {$exigence} : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * La fonction de refus laisse passer, pour une table à effacement après
     * expiration ou après conservation, la suppression d'une ligne dont la
     * colonne d'expiration, en UTC, est passée à l'heure UTC du serveur.
     *
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsPostgres(string $table, string $protection, array $refus, ?string $colonneDExpiration): array
    {
        $ligneExpireeEffacee = $colonneDExpiration !== null
            ? "IF TG_OP = 'DELETE' THEN IF ".self::ligneExpiree('pgsql', "OLD.{$colonneDExpiration}").' THEN RETURN OLD; END IF; END IF; '
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
     * Pour une table à effacement après expiration ou après conservation,
     * le déclencheur BEFORE DELETE ne refuse que la suppression d'une
     * ligne qui n'est pas expirée (voir ligneExpiree()).
     *
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', string>  $refus
     * @return list<string>
     */
    private static function instructionsSqlite(string $table, string $protection, array $refus, ?string $colonneDExpiration): array
    {
        $instructions = [];

        foreach (array_diff_key($refus, ['TRUNCATE' => true]) as $operation => $message) {
            $condition = $colonneDExpiration !== null && $operation === 'DELETE'
                ? ' WHEN NOT ('.self::ligneExpiree('sqlite', "OLD.{$colonneDExpiration}").')'
                : '';
            $instructions[] = "CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table}{$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        }

        return $instructions;
    }

    /**
     * Une ligne expirée : sa colonne d'expiration, en UTC, renseignée et
     * passée à l'heure UTC du serveur de base, now() AT TIME ZONE 'UTC'
     * sous PostgreSQL, CURRENT_TIMESTAMP sous SQLite (« AAAA-MM-JJ
     * HH:MM:SS », le format de ces colonnes en base, voir Instant).
     *
     * @param  'pgsql'|'sqlite'  $pilote
     * @param  string  $colonne  la colonne (expire_le), ou celle de la ligne qu'un déclencheur efface (OLD.expire_le)
     */
    private static function ligneExpiree(string $pilote, string $colonne): string
    {
        $maintenant = $pilote === 'pgsql' ? "(now() AT TIME ZONE 'UTC')" : 'CURRENT_TIMESTAMP';

        return "{$colonne} IS NOT NULL AND {$colonne} <= {$maintenant}";
    }

    private static function verifierLeNom(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Nom de table invalide pour un déclencheur : {$table}.");
        }
    }
}
