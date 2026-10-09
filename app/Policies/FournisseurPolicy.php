<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Fournisseur;
use App\Models\User;

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
     * Un fournisseur supprimé se restaure avant de se modifier : sa page de
     * modification ne le trouve plus. Ce refus retire aussi, dans la liste
     * des enregistrements supprimés, le bouton « Modifier » et le lien de
     * sa ligne (voir FournisseurResource::table()).
     */
    public function update(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur)
            && ! $fournisseur->trashed()
            && $user->can(PermissionRegistry::FOURNISSEUR_UPDATE);
    }

    public function delete(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur) && $user->can(PermissionRegistry::FOURNISSEUR_DELETE);
    }

    /**
     * Le bouton de suppression en masse ; chaque ligne cochée repasse
     * ensuite par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FOURNISSEUR_DELETE);
    }

    /**
     * Restaurer un fournisseur supprimé défait sa suppression : la même
     * permission que pour le supprimer.
     */
    public function restore(User $user, Fournisseur $fournisseur): bool
    {
        return $this->appartientAuMagasinActif($fournisseur) && $user->can(PermissionRegistry::FOURNISSEUR_DELETE);
    }
}
