<?php

namespace App\Policies;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Models\User;

/**
 * Pas de confidentialité par créateur, même raisonnement que
 * FactureAchatPolicy. Toujours irrévocable une fois émis -- même
 * obligation de traçabilité qu'un avoir de vente, voir AvoirFournisseur::class.
 */
class AvoirFournisseurPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::AVOIR_FOURNISSEUR_VIEW);
    }

    public function view(User $user, AvoirFournisseur $avoirFournisseur): bool
    {
        return $this->appartientAuMagasinActif($avoirFournisseur) && $user->can(PermissionRegistry::AVOIR_FOURNISSEUR_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::AVOIR_FOURNISSEUR_CREER);
    }

    public function update(User $user, AvoirFournisseur $avoirFournisseur): bool
    {
        return false;
    }

    public function delete(User $user, AvoirFournisseur $avoirFournisseur): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, AvoirFournisseur $avoirFournisseur): bool
    {
        return false;
    }

    public function restore(User $user, AvoirFournisseur $avoirFournisseur): bool
    {
        return false;
    }
}
