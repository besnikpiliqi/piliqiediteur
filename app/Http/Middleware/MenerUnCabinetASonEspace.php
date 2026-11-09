<?php

namespace App\Http\Middleware;

use App\Filament\EspaceDeLaSociete;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'authentification de l'espace d'administration d'une société (voir
 * SocietePanelProvider), à la place de celle de Filament, pour un compte
 * actif d'un cabinet d'expertise comptable, qui n'a jamais cet espace :
 * l'adresse de cet espace sous le uuid de son propre cabinet
 * (/societe/{uuid} et ses pages), ou son accueil sans société (/societe),
 * le mène au tableau de bord de l'espace de son cabinet (voir
 * EspaceDeLaSociete), au lieu d'un refus. Pour tout autre compte et toute
 * autre adresse, celle d'une autre société comprise, existante ou non,
 * l'authentification de Filament décide, de la même façon qu'avant : rien
 * ne s'y apprend d'une autre société. Elle garde la place d'une
 * authentification dans l'ordre des middlewares (après la session, avant
 * la résolution des paramètres) : la session est lue, aucune société de
 * l'adresse ne l'est encore.
 */
class MenerUnCabinetASonEspace extends Authenticate
{
    /**
     * @param  string  ...$guards
     */
    public function handle($request, Closure $next, ...$guards): Response
    {
        $compte = Filament::auth()->user();

        if ($compte instanceof User && $compte->ouvreLEspaceDuCabinet() && $this->viseSonCabinet($request, $compte)) {
            return redirect()->to(EspaceDeLaSociete::accueil($compte->societe));
        }

        return parent::handle($request, $next, ...$guards);
    }

    /**
     * L'adresse vise-t-elle l'espace d'administration du propre cabinet du
     * compte : son uuid, ou l'accueil de l'espace sans société ?
     */
    private function viseSonCabinet(Request $request, User $compte): bool
    {
        return $request->routeIs('filament.societe.tenant')
            || $request->route('tenant') === $compte->societe->uuid;
    }
}
