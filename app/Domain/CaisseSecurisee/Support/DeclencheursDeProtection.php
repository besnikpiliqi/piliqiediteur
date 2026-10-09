<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les déclencheurs SQL par lesquels la base refuse elle-même de modifier
 * ou de supprimer les lignes d'une table, même par une requête qui ne
 * passe pas par le modèle (DB::table(), update(), delete() ou
 * forceDelete() du constructeur de requêtes, requête brute). Appelé dans
 * le up() de la migration qui crée la table :
 * - ajoutSeul() : table où l'on ne fait qu'ajouter (art. 286 I-3° bis du
 *   CGI), ni modification ni suppression ;
 * - sansSuppression() : table dont aucune ligne ne s'efface ; elle se
 *   modifie, et se marque supprimée (deleted_at) quand son modèle le
 *   prévoit.
 *
 * Seuls SQLite (développement et tests) et MySQL ou MariaDB (production)
 * sont pris en charge : sur tout autre pilote, la migration échoue au lieu
 * de laisser la table sans protection.
 *
 * Restent hors de leur portée TRUNCATE et DROP TABLE sous MySQL, réservés
 * aux droits d'administration de la base : sur une table en ajout seul, la
 * signature chaînée des enregistrements en révèle les effets. Les
 * déclencheurs disparaissent avec leur table : le down() de la migration
 * n'a rien d'autre à défaire.
 */
final class DeclencheursDeProtection
{
    public static function ajoutSeul(string $table): void
    {
        self::poser($table, 'ajout_seul', 'refuser toute modification et toute suppression', [
            'UPDATE' => "{$table} : table en ajout seul, modification refusée",
            'DELETE' => "{$table} : table en ajout seul, suppression refusée",
        ]);
    }

    public static function sansSuppression(string $table): void
    {
        self::poser($table, 'sans_suppression', 'refuser toute suppression définitive', [
            'DELETE' => "{$table} : suppression définitive refusée",
        ]);
    }

    /**
     * @param  string  $protection  le suffixe du nom des déclencheurs
     * @param  string  $exigence  ce que la table doit refuser, pour le message d'un pilote non pris en charge
     * @param  array<'UPDATE'|'DELETE', string>  $refus  le message de refus de chaque opération
     */
    private static function poser(string $table, string $protection, string $exigence, array $refus): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Nom de table invalide pour un déclencheur : {$table}.");
        }

        $connexion = DB::connection();
        $pilote = $connexion->getDriverName();

        if (! in_array($pilote, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("La table {$table} doit {$exigence} : le pilote de base de données « {$pilote} » n'est pas pris en charge (SQLite, MySQL ou MariaDB).");
        }

        foreach ($refus as $operation => $message) {
            $declencheur = "{$table}_{$protection}_".strtolower($operation);

            $connexion->unprepared($pilote === 'sqlite'
                ? "CREATE TRIGGER {$declencheur} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "CREATE TRIGGER {$declencheur} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        }
    }
}
