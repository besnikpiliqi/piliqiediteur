<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;

/**
 * Les services du magasin ont leurs propres autorisations (« Services :
 * voir », « Services : créer », « Services : modifier », voir
 * PermissionRegistry), de la famille commune, dans tous les types qui ont
 * un magasin, y compris pour créer un service depuis la caisse (voir
 * TrouverOuCreerServiceAction et
 * AjouteDesLignesDeVente::actionAjouterUnService()) : celles des produits
 * ne valent que pour les produits.
 */
class ServicePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::SERVICE_VIEW);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->appartientAuMagasinActif($service) && $user->can(PermissionRegistry::SERVICE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::SERVICE_CREATE);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->appartientAuMagasinActif($service) && $this->updateAny($user);
    }

    /**
     * La première question que pose la modification d'une fiche à son
     * auteur relu sous verrou, avant de relire la fiche ; la fiche, relue,
     * repasse ensuite par update() (voir ModifierUneFicheAction).
     */
    public function updateAny(User $user): bool
    {
        return $user->can(PermissionRegistry::SERVICE_UPDATE);
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
