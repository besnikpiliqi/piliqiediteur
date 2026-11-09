<?php

use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La première migration : PILIQI ne fonctionne qu'avec PostgreSQL (décision
 * de l'éditeur du 08/11/2026), et ses déclencheurs et ses gardes ne
 * s'écrivent que pour lui ; sur une autre base, les migrations s'arrêtent
 * ici, avant toute table, en disant quoi régler.
 *
 * Puis le fuseau par défaut de la base, fixé à UTC avant toute table : les
 * sessions ouvertes hors de l'application (psql, sauvegardes, scripts,
 * répartiteur de connexions qui perd le réglage de la session) lisent les
 * horodatages en UTC, comme l'application, sans qu'aucune commande
 * manuelle ne soit à retenir au déploiement. Le rôle qui lance les
 * migrations doit être propriétaire de la base : sinon la migration échoue
 * en disant quoi faire, sauf si la base est déjà à UTC (voir
 * FuseauParDefautDeLaBase).
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
        $connexion = DB::connection();

        if ($connexion->getDriverName() !== 'pgsql') {
            throw new RuntimeException("PILIQI ne fonctionne qu'avec PostgreSQL (17 ou 18) : la connexion « {$connexion->getName()} » utilise le pilote « {$connexion->getDriverName()} ». Réglez DB_CONNECTION=pgsql et les autres variables DB_ de .env (voir le README), puis relancez php artisan migrate.");
        }

        FuseauParDefautDeLaBase::fixerAUtc($connexion);
    }

    public function down(): void
    {
        FuseauParDefautDeLaBase::retirer(DB::connection());
    }
};
