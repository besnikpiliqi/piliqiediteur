<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Models\User;

/**
 * Un client ne se supprime pas : il s'archive (delete(), SoftDeletes) et
 * se restaure (restore()), avec la permission d'archiver les clients.
 */
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

    /**
     * Un client archivé se restaure avant de se modifier : sa page de
     * modification ne le trouve plus. Ce refus retire aussi, dans la liste
     * des clients archivés, le bouton « Modifier » (voir
     * ClientResource::table()) ; sa fiche reste lisible (voir ViewClient).
     */
    public function update(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client)
            && ! $client->trashed()
            && $user->can(PermissionRegistry::CLIENT_UPDATE);
    }

    /**
     * Archiver, jamais le client comptoir : toutes les ventes anonymes du
     * magasin le portent, et la caisse le propose par défaut (voir
     * Client::estComptoir()).
     */
    public function delete(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client)
            && ! $client->estComptoir()
            && $user->can(PermissionRegistry::CLIENT_ARCHIVER);
    }

    /**
     * Le bouton d'archivage en masse ; chaque ligne cochée repasse ensuite
     * par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CLIENT_ARCHIVER);
    }

    /**
     * Restaurer défait l'archivage : la même permission que pour archiver.
     */
    public function restore(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client) && $user->can(PermissionRegistry::CLIENT_ARCHIVER);
    }

    /**
     * Jamais : une fiche ne s'efface pas, ses documents la gardent.
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }
}
