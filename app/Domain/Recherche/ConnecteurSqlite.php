<?php

namespace App\Domain\Recherche;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;
use Pdo\Sqlite;

/**
 * Le connecteur SQLite de Laravel, qui enregistre en plus sur chaque
 * connexion la fonction sans_casse_ni_accents() des recherches (voir
 * RechercheSansAccents::plier()) ; PostgreSQL la tient d'une migration.
 * Branché par AppServiceProvider sur db.connector.sqlite : toute connexion
 * SQLite, reconnexions et processus des tests parallèles compris, passe
 * par lui.
 */
final class ConnecteurSqlite extends SQLiteConnector
{
    public function connect(array $config): PDO
    {
        $connexion = parent::connect($config);
        $plier = RechercheSansAccents::plier(...);

        // PHP 8.4 et plus : Laravel ouvre un Pdo\Sqlite (PDO::connect()),
        // dont createFunction() remplace sqliteCreateFunction(), dépréciée.
        if ($connexion instanceof Sqlite) {
            $connexion->createFunction(RechercheSansAccents::FONCTION_SQL, $plier, 1, Sqlite::DETERMINISTIC);
        } else {
            $connexion->sqliteCreateFunction(RechercheSansAccents::FONCTION_SQL, $plier, 1, PDO::SQLITE_DETERMINISTIC);
        }

        return $connexion;
    }
}
