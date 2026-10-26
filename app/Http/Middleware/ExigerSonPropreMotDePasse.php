<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un responsable, un administrateur ou un expert-comptable dont le mot de
 * passe est encore son identifiant (voir User::doitChoisirSonMotDePasse())
 * choisit le sien à sa connexion, avant toute autre page des deux
 * panneaux : à sa première connexion, et, quand il vient d'être promu à
 * l'un de ces rôles, à sa connexion suivante. La connexion le note dans la
 * session (voir Connexion::authenticate()) ; une session déjà ouverte au
 * moment de la promotion continue sans interruption jusqu'à sa fin. Dans
 * une session ainsi notée, toute page mène à ce choix (voir
 * ChoisirSonMotDePasse), sauf cette page elle-même et la déconnexion ; une
 * requête Livewire d'un autre écran, restée ouverte ou forgée, est refusée.
 * Persistant (voir les panneaux) : il se rejoue sur chaque requête
 * Livewire. Un vendeur ou un observateur garde son identifiant pour mot de
 * passe tant qu'il le veut.
 */
class ExigerSonPropreMotDePasse
{
    /**
     * La clé de session que pose une connexion dont le compte doit choisir
     * son mot de passe.
     */
    public const A_LA_CONNEXION = 'doit_choisir_son_mot_de_passe';

    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();

        if (! $compte instanceof User || ! $request->session()->get(self::A_LA_CONNEXION, false)) {
            return $next($request);
        }

        if (! $compte->doitChoisirSonMotDePasse()) {
            $request->session()->forget(self::A_LA_CONNEXION);

            return $next($request);
        }

        $panneau = Filament::getCurrentPanel()?->getId() ?? 'app';

        if (in_array($request->route()?->getName(), ["filament.{$panneau}.choisir-son-mot-de-passe", "filament.{$panneau}.auth.logout"], true)) {
            return $next($request);
        }

        abort_if(Livewire::isLivewireRequest(), 403, 'Choisissez d\'abord votre mot de passe.');

        return redirect()->route("filament.{$panneau}.choisir-son-mot-de-passe");
    }
}
