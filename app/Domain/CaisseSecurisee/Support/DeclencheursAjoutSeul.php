<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les déclencheurs SQL d'une table où l'on ne fait qu'ajouter (art. 286
 * I-3° bis du CGI) : la base refuse elle-même de modifier ou de supprimer
 * une ligne, même par une requête qui ne passe pas par le modèle
 * (DB::table(), update() ou delete() en masse, requête brute). Appelé
 * dans le up() de la migration qui crée la table.
 *
 * Seuls SQLite (développement et tests) et MySQL ou MariaDB (production)
 * sont pris en charge : sur tout autre pilote, la migration échoue au lieu
 * de laisser la table sans protection.
 *
 * Restent hors de leur portée TRUNCATE et DROP TABLE sous MySQL, réservés
 * aux droits d'administration de la base : la signature chaînée des
 * enregistrements en révèle les effets. Les déclencheurs disparaissent
 * avec leur table : le down() de la migration n'a rien d'autre à défaire.
 */
final class DeclencheursAjoutSeul
{
    public static function poser(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Nom de table invalide pour un déclencheur : {$table}.");
        }

        $connexion = DB::connection();
        $pilote = $connexion->getDriverName();

        if (! in_array($pilote, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("La table {$table} doit refuser toute modification et toute suppression : le pilote de base de données « {$pilote} » n'est pas pris en charge (SQLite, MySQL ou MariaDB).");
        }

        foreach (['UPDATE' => 'modification', 'DELETE' => 'suppression'] as $operation => $refus) {
            $declencheur = $table.'_ajout_seul_'.strtolower($operation);
            $message = "{$table} : table en ajout seul, {$refus} refusée";

            $connexion->unprepared($pilote === 'sqlite'
                ? "CREATE TRIGGER {$declencheur} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "CREATE TRIGGER {$declencheur} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        }
    }
}
