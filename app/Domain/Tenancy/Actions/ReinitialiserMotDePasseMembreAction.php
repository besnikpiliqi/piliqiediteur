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
 * Qui choisit le mot de passe peut ensuite ouvrir le compte. Un autre
 * membre que le propriétaire n'en donne donc un qu'à un membre dont il a
 * lui-même toutes les autorisations : il n'obtient jamais, en ouvrant ce
 * compte, une autorisation que le propriétaire ne lui a pas cochée.
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

        $magasin = $membre->magasin;

        if (! $auteur->estProprietaireDe($magasin)
            && array_diff($membre->utilisateur->autorisationsCocheesDans($magasin), $auteur->autorisationsCocheesDans($magasin)) !== []) {
            throw new LogicException('Seul le propriétaire peut donner un nouveau mot de passe à ce membre : il a des autorisations que vous n\'avez pas.');
        }

        $membre->utilisateur->forceFill(['password' => $nouveauMotDePasse])->save();
    }
}
