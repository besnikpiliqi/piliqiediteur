<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Models\User;

/**
 * Même confidentialité par membre que FacturePolicy : un membre à qui le
 * propriétaire a délégué la clôture de la journée (cloture_caisse.gerer)
 * ne voit que les clôtures qu'il a lui-même déclenchées, sans
 * cloture_caisse.view_toutes -- détenue par le propriétaire, cochée
 * d'office pour l'administrateur et le comptable (voir
 * RoleMagasin::permissionsParDefaut()), et pour tout membre à qui le
 * propriétaire la coche. Un vendeur ferme sa propre caisse ailleurs (voir
 * ClotureCaissePersonnelle).
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
        if (! $this->appartientAuMagasinActif($cloture)) {
            return false;
        }

        if (! $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW)) {
            return false;
        }

        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) || $cloture->cree_par === $user->getKey();
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
