<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\ChangerSonMotDePasseProvisoireAction;
use App\Http\Middleware\MenerUnCompteSansMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login;
use Illuminate\Validation\ValidationException;

/**
 * La connexion aux deux panneaux, comme celle de Filament (un compte
 * suspendu ou retiré reçoit la même réponse que des identifiants faux, et
 * un compte qui ne peut pas administrer sa société ne se connecte pas à son
 * espace, voir User::canAccessPanel()), sauf pour un mot de passe
 * provisoire dont les 72 heures sont passées : après des identifiants
 * exacts seulement, la personne apprend qu'il a expiré et qu'elle doit en
 * demander un nouveau à qui a créé son compte, et aucune session ne
 * s'ouvre. Un provisoire encore valable mène, avant toute autre page, à son
 * changement (voir ExigerUnMotDePasseDefinitif). Connectée, la personne va
 * à la page qu'elle demandait, sinon à l'accueil du panneau, même sans
 * magasin (voir ReponseDeConnexion et MenerUnCompteSansMagasin).
 */
class Connexion extends Login
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(MenerUnCompteSansMagasin::accueil());
        }

        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        $reponse = parent::authenticate();
        $compte = Filament::auth()->user();

        if ($compte instanceof User && $compte->sonMotDePasseProvisoireAExpire()) {
            Filament::auth()->logout();

            throw ValidationException::withMessages(['data.email' => ChangerSonMotDePasseProvisoireAction::MESSAGE_EXPIRE]);
        }

        return $reponse;
    }
}
