<?php

use App\Domain\Recherche\RechercheSansAccents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fonction SQL des recherches sans casse ni accents (voir
 * RechercheSansAccents::creationDeLaFonction()), écrite avec les fonctions
 * natives de PostgreSQL, sans extension à installer.
 *
 * OR REPLACE : migrate:fresh supprime les tables, pas les fonctions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(RechercheSansAccents::creationDeLaFonction());
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS '.RechercheSansAccents::FONCTION_SQL.'(text)');
    }
};
