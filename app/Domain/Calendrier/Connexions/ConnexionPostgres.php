<?php

namespace App\Domain\Calendrier\Connexions;

use Illuminate\Database\PostgresConnection;

/**
 * La connexion PostgreSQL, dont les dates liées s'écrivent en UTC (voir
 * LieLesDatesEnUtc), comme la session (config/database.php).
 */
class ConnexionPostgres extends PostgresConnection
{
    use LieLesDatesEnUtc;
}
