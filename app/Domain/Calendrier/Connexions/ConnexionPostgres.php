<?php

namespace App\Domain\Calendrier\Connexions;

use App\Domain\Calendrier\Casts\Instant;
use DateTimeInterface;
use Illuminate\Database\PostgresConnection;

/**
 * La connexion PostgreSQL, dont la session est en UTC
 * (config/database.php) et dont les dates liées s'écrivent aussi en UTC.
 */
class ConnexionPostgres extends PostgresConnection
{
    /**
     * Une date liée à une requête (constructeur de requêtes, DB::table(),
     * requête brute) s'écrit en UTC, comme les colonnes qu'elle compare
     * (voir Instant), quel que soit son fuseau : Laravel l'écrirait sinon
     * dans le fuseau de l'objet.
     *
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $cle => $valeur) {
            if ($valeur instanceof DateTimeInterface) {
                $bindings[$cle] = Instant::enBase($valeur);
            }
        }

        return parent::prepareBindings($bindings);
    }
}
