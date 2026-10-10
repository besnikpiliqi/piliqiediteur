<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Models\User;

/**
 * Un produit ne se supprime pas : il s'archive (delete(), SoftDeletes) et
 * se restaure (restore()), avec la permission d'archiver les produits.
 */
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

    /**
     * Un produit archivé se restaure avant de se modifier : sa page de
     * modification ne le trouve plus. Ce refus retire aussi, dans la liste
     * des produits archivés, le bouton « Modifier » et le lien de sa ligne
     * (voir ProduitResource::table()).
     */
    public function update(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit)
            && ! $produit->trashed()
            && $user->can(PermissionRegistry::PRODUIT_UPDATE);
    }

    public function delete(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_ARCHIVER);
    }

    /**
     * Le bouton d'archivage en masse ; chaque ligne cochée repasse ensuite
     * par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_ARCHIVER);
    }

    /**
     * Restaurer défait l'archivage : la même permission que pour archiver.
     */
    public function restore(User $user, Produit $produit): bool
    {
        return $this->appartientAuMagasinActif($produit) && $user->can(PermissionRegistry::PRODUIT_ARCHIVER);
    }

    /**
     * Jamais : une fiche ne s'efface pas, ses documents et son stock la
     * gardent.
     */
    public function forceDelete(User $user, Produit $produit): bool
    {
        return false;
    }
}
