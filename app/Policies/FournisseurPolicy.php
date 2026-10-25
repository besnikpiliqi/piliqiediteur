<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Fournisseur;
use App\Models\User;

/**
 * Un fournisseur ne se supprime pas : il s'archive (delete(), SoftDeletes)
 * et se restaure (restore()), avec la permission d'archiver les
 * fournisseurs.
 */
class FournisseurPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FOURNISSEUR_VIEW);
    }

    public function view(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur) && $user->can(PermissionRegistry::FOURNISSEUR_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::FOURNISSEUR_CREATE);
    }

    /**
     * Un fournisseur archivé se restaure avant de se modifier : sa page de
     * modification ne le trouve plus. Ce refus retire aussi, dans la liste
     * des fournisseurs archivés, le bouton « Modifier » et le lien de sa
     * ligne (voir FournisseurResource::table()).
     */
    public function update(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur)
            && ! $fournisseur->trashed()
            && $user->can(PermissionRegistry::FOURNISSEUR_UPDATE);
    }

    public function delete(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur) && $user->can(PermissionRegistry::FOURNISSEUR_ARCHIVER);
    }

    /**
     * Le bouton d'archivage en masse, et la première question que pose
     * l'archivage ou la restauration d'une fiche à son auteur relu sous
     * verrou ; chaque fiche, relue, repasse ensuite par delete() ou
     * restore() (voir ArchiverUneFicheAction).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FOURNISSEUR_ARCHIVER);
    }

    /**
     * Restaurer défait l'archivage : la même permission que pour archiver.
     */
    public function restore(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur) && $user->can(PermissionRegistry::FOURNISSEUR_ARCHIVER);
    }

    /**
     * Jamais : une fiche ne s'efface pas, ses factures et ses produits la
     * gardent.
     */
    public function forceDelete(User $user, Fournisseur $fournisseur): bool
    {
        return false;
    }
}
