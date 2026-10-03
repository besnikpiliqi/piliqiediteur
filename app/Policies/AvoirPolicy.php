<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Models\User;

/**
 * Même confidentialité par vendeur que FacturePolicy : un avoir révèle le
 * chiffre d'affaires annulé d'une vente, donc sans FACTURE_VIEW_TOUTES un
 * utilisateur ne voit que les avoirs des factures qu'il a lui-même créées.
 *
 * Toujours irrévocable une fois émis -- même obligation légale de
 * conservation qu'une facture, voir Avoir::class.
 */
class AvoirPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::AVOIR_VIEW);
    }

    public function view(User $user, Avoir $avoir): bool
    {
        if (! $this->appartientAuMagasinActif($avoir)) {
            return false;
        }

        if (! $user->can(PermissionRegistry::AVOIR_VIEW)) {
            return false;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW_TOUTES) || $avoir->facture->cree_par === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::AVOIR_CREER);
    }

    public function update(User $user, Avoir $avoir): bool
    {
        return false;
    }

    public function delete(User $user, Avoir $avoir): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Avoir $avoir): bool
    {
        return false;
    }

    public function restore(User $user, Avoir $avoir): bool
    {
        return false;
    }
}
