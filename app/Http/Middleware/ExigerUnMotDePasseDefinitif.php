<?php

namespace App\Http\Middleware;

use App\Filament\App\Pages\Auth\ChangerMotDePasseProvisoire;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tant que le mot de passe d'un compte est provisoire, toute page des deux
 * panneaux mène au changement de ce mot de passe (voir
 * ChangerMotDePasseProvisoire), sauf cette page elle-même et la
 * déconnexion ; une requête Livewire d'un autre écran, restée ouverte ou
 * forgée, est refusée. Persistant (voir les panneaux) : il se rejoue sur
 * chaque requête Livewire, avec la route de la page qui l'a montée. Les
 * droits, eux, restent refusés par ControleDesDroits tant que le compte ne
 * peut pas agir.
 */
class ExigerUnMotDePasseDefinitif
{
    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();

        if (! $compte instanceof User || ! $compte->doitChangerSonMotDePasse()) {
            return $next($request);
        }

        $panneau = Filament::getCurrentPanel()?->getId() ?? 'app';

        if (in_array($request->route()?->getName(), ["filament.{$panneau}.mot-de-passe-provisoire", "filament.{$panneau}.auth.logout"], true)) {
            return $next($request);
        }

        abort_if(Livewire::isLivewireRequest(), 403, 'Choisissez d\'abord votre mot de passe.');

        return redirect()->route("filament.{$panneau}.mot-de-passe-provisoire");
    }
}
