<?php

namespace App\Http\Middleware;

use App\Filament\EspaceDeLaSociete;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'accueil du panneau des magasins (/app) pour un compte qui n'a encore
 * aucun magasin à ouvrir (voir User::magasinsAccessibles()). Un compte
 * d'un cabinet d'expertise comptable va toujours à l'accueil de l'espace
 * de son cabinet. Le propriétaire et un administrateur vont à celui de
 * l'espace de leur société, où se créent les magasins (voir
 * EspaceDeLaSociete). Un compte de membre a toujours son magasin, où il va,
 * comme Filament l'y mène : aucun autre compte actif n'est sans magasin,
 * et un compte que les données laisseraient sans magasin va de même à
 * l'espace de sa société, qui le refuse s'il ne l'ouvre pas (voir
 * User::canAccessPanel()). Après
 * ExigerSonPropreMotDePasse : un responsable ou un administrateur dont le
 * mot de passe est encore son identifiant choisit d'abord le sien.
 */
class MenerUnCompteSansMagasin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();

        if (! $compte instanceof User || ! $request->routeIs('filament.app.tenant')) {
            return $next($request);
        }

        if ($compte->ouvreLEspaceDuCabinet()) {
            return redirect()->to(EspaceDeLaSociete::accueil($compte->societe));
        }

        if ($compte->magasinsAccessibles()->isNotEmpty()) {
            return $next($request);
        }

        return redirect()->to(EspaceDeLaSociete::accueil($compte->societe));
    }

    /**
     * L'adresse de l'accueil du panneau courant après une connexion ou un
     * mot de passe choisi : celle que donne Filament, ou, pour un compte
     * sans magasin dans le panneau des magasins, dont Filament ne connaît
     * aucune, l'accueil de ce panneau, que ce middleware dirige.
     */
    public static function accueil(): string
    {
        return Filament::getUrl() ?? route('filament.app.tenant');
    }
}
