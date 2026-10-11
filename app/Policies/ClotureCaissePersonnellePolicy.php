<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Models\User;

/**
 * Aucune permission dédiée n'est nécessaire pour fermer SA PROPRE caisse :
 * quiconque vend (facture.create) ou encaisse (paiement.creer) la ferme et
 * consulte ses relevés, voir le docblock de ClotureCaissePersonnelle, le
 * comptable comme le vendeur. Qui peut clôturer la journée
 * (cloture_caisse.gerer) ferme aussi celle d'un autre vendeur, pour lui
 * (voir fermerLaCaisseDe()) ; et toute caisse encore ouverte se ferme
 * d'office avec la journée (voir FermerLesCaissesPersonnellesAction). Le
 * propriétaire et quiconque a cloture_caisse.view_toutes (comptable...)
 * voient les relevés de toute l'équipe, pour la réconciliation de fin de
 * journée.
 */
class ClotureCaissePersonnellePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tientUneCaisse($user) || $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES);
    }

    public function view(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        if (! $this->appartientAuMagasinActif($cloture)) {
            return false;
        }

        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) || $cloture->vendeur_id === $user->getKey();
    }

    public function fermer(User $user): bool
    {
        return $this->tientUneCaisse($user);
    }

    /**
     * « Fermer la caisse d'un vendeur » : qui peut clôturer la journée
     * ferme celle d'un autre membre, pour lui ; la sienne se ferme par
     * fermer(). Permis dans un magasin suspendu ou fermé, comme toute
     * clôture (voir ControleDesDroits).
     */
    public function fermerLaCaisseDe(User $user, User $vendeur): bool
    {
        return ! $vendeur->is($user) && $user->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
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
     * Le droit de vendre ou d'encaisser du membre, lu sur ses permissions
     * plutôt que demandé à la Gate : dans un magasin suspendu ou fermé,
     * celle-ci refuse facture.create et paiement.creer (voir
     * ControleDesDroits), mais le membre ferme toujours sa caisse et
     * consulte toujours ses relevés.
     */
    private function tientUneCaisse(User $user): bool
    {
        return $user->checkPermissionTo(PermissionRegistry::FACTURE_CREATE)
            || $user->checkPermissionTo(PermissionRegistry::PAIEMENT_CREER);
    }
}
