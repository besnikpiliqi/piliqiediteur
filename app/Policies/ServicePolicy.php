<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;

/**
 * Les services font partie du catalogue, comme les produits : mêmes
 * permissions (voir PermissionRegistry), y compris pour créer un service
 * depuis la caisse (voir TrouverOuCreerServiceAction et
 * AjouteDesLignesDeVente::actionAjouterUnService()).
 */
class ServicePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_VIEW);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->appartientAuMagasinActif($service) && $user->can(PermissionRegistry::PRODUIT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::PRODUIT_CREATE);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->appartientAuMagasinActif($service) && $user->can(PermissionRegistry::PRODUIT_UPDATE);
    }

    /**
     * Jamais : des lignes de vente renvoient au service. Il se retire de la
     * caisse (« Proposé à la caisse » décoché, voir ServiceResource).
     */
    public function delete(User $user, Service $service): bool
    {
        return false;
    }
}
