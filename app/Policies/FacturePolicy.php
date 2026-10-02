<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Facture;
use App\Models\User;

/**
 * Confidentialité par vendeur (décision produit explicite) : sans
 * FACTURE_VIEW_TOUTES, un utilisateur ne voit que les factures qu'il a
 * lui-même créées. Les clients restent partagés (voir ClientPolicy) --
 * seul l'historique de vente est cloisonné.
 */
class FacturePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_VIEW);
    }

    public function view(User $user, Facture $facture): bool
    {
        if (! $this->appartientAuMagasinActif($facture)) {
            return false;
        }

        if ($user->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            return true;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW) && $facture->cree_par === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function update(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture)
            && $facture->estModifiable()
            && $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function emettre(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_EMETTRE);
    }

    public function annuler(User $user, Facture $facture): bool
    {
        return $this->appartientAuMagasinActif($facture) && $user->can(PermissionRegistry::FACTURE_ANNULER);
    }
}
