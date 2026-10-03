<?php

namespace App\Domain\Tenancy\Actions;

use App\Models\User;

/**
 * Pas de lien de réinitialisation par email : comme à la création du
 * compte, le propriétaire choisit directement le nouveau mot de passe et
 * le communique lui-même au salarié.
 */
class ReinitialiserMotDePasseMembreAction
{
    public function handle(User $utilisateur, string $nouveauMotDePasse): User
    {
        $utilisateur->forceFill(['password' => $nouveauMotDePasse])->save();

        return $utilisateur;
    }
}
