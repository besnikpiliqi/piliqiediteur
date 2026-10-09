<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Models\User;

class ProduitPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_VIEW);
    }

    public function view(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_CREATE);
    }

    public function update(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_UPDATE);
    }

    public function delete(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_DELETE);
    }

    /**
     * Le bouton de suppression en masse ; chaque ligne cochée repasse
     * ensuite par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_DELETE);
    }

    /**
     * Restaurer un produit supprimé défait sa suppression : la même
     * permission que pour le supprimer.
     */
    public function restore(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_DELETE);
    }

    public function ajusterStock(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_AJUSTER_STOCK);
    }
}
