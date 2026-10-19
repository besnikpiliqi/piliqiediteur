<?php

namespace App\Http\Middleware;

use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'accueil du panneau des magasins (/app) pour un compte qui n'a encore
 * aucun magasin à ouvrir (voir User::magasinsAccessibles()) : jamais une
 * erreur. Le propriétaire et un administrateur vont à l'espace de leur
 * société, où se créent les magasins (voir TableauDeBord) ; un
 * expert-comptable d'une société sans magasin réel, à une page qui le lui
 * dit (voir AucunMagasin). Un compte de membre a toujours son magasin, où
 * il va, comme Filament l'y mène. Après ExigerSonPropreMotDePasse : un
 * responsable, un administrateur ou un expert-comptable dont le mot de
 * passe est encore son identifiant choisit d'abord le sien.
 */
class MenerUnCompteSansMagasin
{
    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();

        if (! $compte instanceof User || ! $request->routeIs('filament.app.tenant') || $compte->magasinsAccessibles()->isNotEmpty()) {
            return $next($request);
        }

        return redirect()->to($compte->peutAdministrer()
            ? TableauDeBord::getUrl(panel: 'societe', tenant: $compte->societe)
            : route('filament.app.aucun-magasin'));
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
