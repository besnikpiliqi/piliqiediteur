<?php

use App\Domain\Recherche\RechercheSansAccents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fonction SQL des recherches sans casse ni accents (voir
 * RechercheSansAccents) : sous PostgreSQL, lower(unaccent()), sur
 * l'extension unaccent, marquée de confiance (trusted) : le propriétaire
 * de la base la crée sans être superutilisateur. Sous SQLite, la fonction
 * s'enregistre sur chaque connexion (voir ConnecteurSqlite) : rien à
 * créer ici.
 *
 * IF NOT EXISTS et OR REPLACE : migrate:fresh supprime les tables, pas
 * les extensions ni les fonctions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $fonction = RechercheSansAccents::FONCTION_SQL;

        DB::unprepared('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::unprepared("CREATE OR REPLACE FUNCTION {$fonction}(texte text) RETURNS text LANGUAGE sql STABLE RETURNS NULL ON NULL INPUT AS \$\$ SELECT lower(unaccent(texte)) \$\$");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS '.RechercheSansAccents::FONCTION_SQL.'(text)');
        DB::unprepared('DROP EXTENSION IF EXISTS unaccent');
    }
};
