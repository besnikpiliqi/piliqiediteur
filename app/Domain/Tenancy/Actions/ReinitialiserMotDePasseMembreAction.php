<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Models\User;
use LogicException;

/**
 * Pas de lien de réinitialisation par e-mail : comme à la création du
 * compte, le magasin choisit directement le nouveau mot de passe et le
 * communique lui-même au salarié. Un compte n'appartient qu'à un seul
 * magasin : ce mot de passe n'ouvre que celui-ci.
 *
 * Le mot de passe du propriétaire ne se réinitialise jamais ici : il le
 * change dans son profil, ou par « Mot de passe oublié » sur la page de
 * connexion.
 *
 * Celui qui agit ne change pas non plus le sien ici : ce formulaire ne
 * demande pas le mot de passe actuel, et une session restée ouverte
 * suffirait sinon à s'approprier son compte. Il passe par son profil.
 */
class ReinitialiserMotDePasseMembreAction
{
    public function handle(User $auteur, MagasinUser $membre, string $nouveauMotDePasse): void
    {
        if ($membre->utilisateur->is($auteur)) {
            throw new LogicException('Changez votre propre mot de passe depuis votre profil, avec votre mot de passe actuel.');
        }

        if ($membre->role === RoleMagasin::Proprietaire) {
            throw new LogicException('Ce mot de passe ne se réinitialise pas depuis l\'équipe : la personne utilise « Mot de passe oublié » sur la page de connexion.');
        }

        $membre->utilisateur->forceFill(['password' => $nouveauMotDePasse])->save();
    }
}
