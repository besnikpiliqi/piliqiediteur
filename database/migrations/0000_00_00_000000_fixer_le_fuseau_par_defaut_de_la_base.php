<?php

use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La première migration : le fuseau par défaut de la base PostgreSQL (seul
 * moteur que PILIQI ouvre, voir AppServiceProvider), fixé à UTC avant
 * toute table de PILIQI : les sessions ouvertes hors de l'application
 * (psql, sauvegardes, scripts, répartiteur de connexions qui perd le
 * réglage de la session) lisent les horodatages en UTC, comme
 * l'application, sans qu'aucune commande manuelle ne soit à retenir au
 * déploiement. Le rôle qui lance les migrations doit être propriétaire de
 * la base : sinon la migration échoue en disant quoi faire, sauf si la
 * base est déjà à UTC (voir FuseauParDefautDeLaBase).
 *
 * Le réglage appartient à la base, pas au schéma : migrate:fresh, qui
 * supprime les tables, le laisse en place, et le rejouer ne change rien.
 * Une sauvegarde logique restaurée sans --create le perd, et cette
 * migration, déjà inscrite, ne passe plus : caisse:diagnostiquer le
 * signale et donne la commande qui le rétablit.
 */
return new class extends Migration
{
    public function up(): void
    {
        FuseauParDefautDeLaBase::fixerAUtc(DB::connection());
    }

    public function down(): void
    {
        FuseauParDefautDeLaBase::retirer(DB::connection());
    }
};
