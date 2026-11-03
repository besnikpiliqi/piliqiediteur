<?php

namespace App\Domain\Calendrier\Connexions;

use Illuminate\Database\SQLiteConnection;

/**
 * La connexion SQLite, dont les dates liées s'écrivent en UTC (voir
 * LieLesDatesEnUtc).
 */
class ConnexionSqlite extends SQLiteConnection
{
    use LieLesDatesEnUtc;
}
