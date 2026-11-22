<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une personne qui vient de se connecter avec son mot de passe provisoire
 * (voir User::doitChoisirSonMotDePasse() et MotsDePasseProvisoires) choisit
 * le sien à sa connexion, avant toute autre page des deux panneaux : à sa
 * première connexion, et après chaque accès redonné en son absence, quel
 * que soit son rôle, vendeur et observateur compris. La connexion le note
 * dans la session (voir Connexion::authenticate()). Dans une session ainsi
 * notée, toute page mène à ce choix (voir ChoisirSonMotDePasse), sauf cette
 * page elle-même et la déconnexion ; une requête Livewire d'un autre
 * écran, restée ouverte ou forgée, est refusée.
 * Persistant (voir les panneaux) : il se rejoue sur chaque requête
 * Livewire, toujours après SetUpPanel, qui a déjà choisi le panneau
 * courant.
 */
class ExigerSonPropreMotDePasse
{
    /**
     * La clé de session que pose une connexion dont le compte doit choisir
     * son mot de passe.
     */
    public const A_LA_CONNEXION = 'doit_choisir_son_mot_de_passe';

    /**
     * @param  Closure(Request): Response  $next
     */
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

        $panneau = ValeurRequise::de(Filament::getCurrentPanel(), 'le panneau de la page')->getId();

        if ($request->routeIs("filament.{$panneau}.choisir-son-mot-de-passe", "filament.{$panneau}.auth.logout")) {
            return $next($request);
        }

        abort_if(Livewire::isLivewireRequest(), 403, 'Choisissez d\'abord votre mot de passe.');

        return redirect()->route("filament.{$panneau}.choisir-son-mot-de-passe");
    }
}
