<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;

/**
 * Les services font partie du catalogue, comme les produits : mêmes
 * permissions (voir PermissionRegistry). Créer un service depuis la caisse
 * reste ouvert à quiconque encaisse (voir Caisse::actionAjouterUnService()).
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
     * Jamais : des lignes de vente renvoient au service. On l'archive
     * (actif) pour le retirer de la caisse.
     */
    public function delete(User $user, Service $service): bool
    {
        return false;
    }
}
