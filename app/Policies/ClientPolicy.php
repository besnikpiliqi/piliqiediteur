<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Models\User;

class ClientPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CLIENT_VIEW);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client) && $user->can(PermissionRegistry::CLIENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::CLIENT_CREATE);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client) && $user->can(PermissionRegistry::CLIENT_UPDATE);
    }

    /**
     * Jamais le client comptoir : toutes les ventes anonymes du magasin
     * le portent, et la caisse le propose par défaut (voir
     * Client::estComptoir()).
     */
    public function delete(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client)
            && ! $client->estComptoir()
            && $user->can(PermissionRegistry::CLIENT_DELETE);
    }

    /**
     * Le bouton de suppression en masse ; chaque ligne cochée repasse
     * ensuite par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CLIENT_DELETE);
    }
}
