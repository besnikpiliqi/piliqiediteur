<?php

namespace Database\Seeders\Semis;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Une requête de l'écran, venue d'un poste du magasin, le temps d'un geste
 * du semis : son adresse IP (de la plage réservée à la documentation,
 * 192.0.2.0/24, qu'aucun appareil réel ne porte) et son navigateur se
 * notent alors à part du journal d'audit et du journal des événements,
 * comme pour une vraie requête (voir TraceDuVisiteur), sans jamais entrer
 * dans un contenu signé.
 */
final class RequeteDuPoste
{
    public const IP = '192.0.2.10';

    public const NAVIGATEUR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /**
     * Exécute $geste comme une requête de l'écran de ce chemin, puis rend
     * la requête d'avant.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    public static function pendant(string $chemin, Closure $geste): mixed
    {
        $avant = app('request');
        $requete = Request::create($chemin, 'POST', server: ['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => self::NAVIGATEUR]);
        $route = (new Route('POST', ltrim($chemin, '/'), []))->bind($requete);
        $requete->setRouteResolver(static fn (): Route => $route);
        app()->instance('request', $requete);

        try {
            return $geste();
        } finally {
            app()->instance('request', $avant);
        }
    }
}
