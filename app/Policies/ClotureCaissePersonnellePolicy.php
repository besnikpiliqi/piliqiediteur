<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Models\User;

/**
 * Aucune permission spéciale n'est nécessaire pour fermer SA PROPRE
 * caisse : quiconque peut vendre (facture.create) peut la fermer, voir le
 * docblock de ClotureCaissePersonnelle. Le propriétaire et quiconque a
 * cloture_caisse.view_toutes (comptable...) peuvent voir les relevés de
 * toute l'équipe, pour la réconciliation de fin de journée.
 */
class ClotureCaissePersonnellePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->vendDansLeMagasin($user) || $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES);
    }

    public function view(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        if (! $this->appartientAuMagasinActif($cloture)) {
            return false;
        }

        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) || $cloture->cree_par === $user->getKey();
    }

    public function fermer(User $user): bool
    {
        return $this->vendDansLeMagasin($user);
    }

    public function update(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function delete(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function restore(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    /**
     * Le droit de vendre du membre, lu sur ses permissions plutôt que
     * demandé à la Gate : dans un magasin suspendu ou fermé, celle-ci
     * refuse facture.create (voir ControleDesDroits), mais le vendeur
     * ferme toujours sa caisse et consulte toujours ses relevés.
     */
    private function vendDansLeMagasin(User $user): bool
    {
        return $user->checkPermissionTo(PermissionRegistry::FACTURE_CREATE);
    }
}
