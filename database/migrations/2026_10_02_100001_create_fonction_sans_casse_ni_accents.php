<?php

use App\Domain\Recherche\RechercheSansAccents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fonction SQL des recherches sans casse ni accents sous PostgreSQL :
 * le pliage de RechercheSansAccents::plier(), écrit avec les fonctions
 * natives du moteur, sans extension à installer. Sous SQLite, la fonction
 * s'enregistre sur chaque connexion (voir ConnecteurSqlite) : rien à
 * créer ici.
 *
 * OR REPLACE : migrate:fresh supprime les tables, pas les fonctions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(RechercheSansAccents::creationDeLaFonctionPostgresql());
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS '.RechercheSansAccents::FONCTION_SQL.'(text)');
    }
};
