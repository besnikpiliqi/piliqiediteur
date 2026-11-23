<?php

namespace App\Domain\Demonstration\Bases;

use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Database\Connection;

/**
 * La fonction unique des réglages d'une base, appelée par la première
 * migration (le fuseau) et, sur le site de démonstration, aussitôt après
 * chaque copie d'un modèle (lot DEMO-COPIE, C.3) : CREATE DATABASE …
 * TEMPLATE ne recopie ni les réglages de la base (pg_db_role_setting) ni
 * ses droits (GRANT … ON DATABASE). Elle pose donc tout ce qui appartient à
 * la base et non à son schéma : le fuseau UTC et, sur le site de
 * démonstration, le seul droit de connexion du rôle des pages (demo_app),
 * celui de tout autre rôle (PUBLIC) étant retiré. Un futur réglage de la
 * base s'ajoute ici, et nulle part ailleurs.
 */
final class ReglagesDeLaBase
{
    public static function appliquer(Connection $connexion): void
    {
        FuseauParDefautDeLaBase::fixerAUtc($connexion);

        if (SiteDePiliqi::estLaDemonstration()) {
            RolesDeLaDemonstration::reglerLaConnexionALaBase($connexion);
        }
    }
}
