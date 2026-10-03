<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Models\User;

/**
 * Pas de confidentialité par vendeur ici (contrairement à FacturePolicy) :
 * une clôture de caisse agrège TOUTES les ventes particulier de la
 * journée, ce n'est jamais le document d'un seul vendeur.
 *
 * Document fiscal au même titre qu'une facture : jamais modifiable ni
 * supprimable une fois créée (voir ClotureCaisse::class).
 */
class ClotureCaissePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW);
    }

    public function view(User $user, ClotureCaisse $cloture): bool
    {
        return $this->appartientAuMagasinActif($cloture) && $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW);
    }

    public function cloturer(User $user): bool
    {
        return $user->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    public function update(User $user, ClotureCaisse $cloture): bool
    {
        return false;
    }

    public function delete(User $user, ClotureCaisse $cloture): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ClotureCaisse $cloture): bool
    {
        return false;
    }

    public function restore(User $user, ClotureCaisse $cloture): bool
    {
        return false;
    }
}
