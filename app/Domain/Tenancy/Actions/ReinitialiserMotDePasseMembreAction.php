<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use LogicException;

/**
 * Pas de lien de réinitialisation par email : comme à la création du
 * compte, le magasin choisit directement le nouveau mot de passe et le
 * communique lui-même au salarié.
 *
 * Un compte User est global : son mot de passe ouvre tous les magasins
 * auxquels il est rattaché. Le magasin ne change donc que le mot de passe
 * d'un compte qui n'appartient qu'à lui, et jamais celui de son
 * propriétaire. Toute autre personne change elle-même son mot de passe
 * (« Mot de passe oublié » sur la page de connexion, ou son profil).
 */
class ReinitialiserMotDePasseMembreAction
{
    public function handle(MagasinUser $membre, string $nouveauMotDePasse): void
    {
        if ($membre->role === RoleMagasin::Proprietaire || $this->estRattacheAUnAutreMagasin($membre)) {
            throw new LogicException('Ce mot de passe ne se réinitialise pas depuis l\'équipe : la personne utilise « Mot de passe oublié » sur la page de connexion.');
        }

        $membre->utilisateur->forceFill(['password' => $nouveauMotDePasse])->save();
    }

    /**
     * Interroge volontairement les rattachements de TOUS les magasins
     * (supprimés compris) de ce seul compte : la réponse n'est qu'un oui
     * ou un non, elle ne révèle rien de ces magasins.
     */
    private function estRattacheAUnAutreMagasin(MagasinUser $membre): bool
    {
        return MagasinUser::query()
            ->where('user_id', $membre->user_id)
            ->where('magasin_id', '!=', $membre->magasin_id)
            ->exists();
    }
}
