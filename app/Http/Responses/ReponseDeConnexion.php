<?php

namespace App\Http\Responses;

use App\Http\Middleware\MenerUnCompteSansMagasin;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * La réponse à une connexion réussie, dans les deux panneaux : la page
 * demandée avant la connexion, sinon l'accueil du panneau (voir
 * MenerUnCompteSansMagasin::accueil()), y compris pour un compte qui n'a
 * encore aucun magasin.
 */
class ReponseDeConnexion implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->intended(MenerUnCompteSansMagasin::accueil());
    }
}
