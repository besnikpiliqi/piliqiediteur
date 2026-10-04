<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Models\User;

/**
 * Même esprit de confidentialité que FacturePolicy, mais basée sur le
 * créateur de l'AVOIR LUI-MÊME plutôt que sur celui d'une facture : un
 * avoir peut désormais regrouper des lignes de plusieurs factures de
 * vendeurs différents (voir CreerAvoirAction), il n'y a donc plus un
 * "créateur de la facture" unique auquel se rattacher.
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

        return $user->can(PermissionRegistry::FACTURE_VIEW_TOUTES) || $avoir->cree_par === $user->getKey();
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
