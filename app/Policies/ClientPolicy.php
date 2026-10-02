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

    public function delete(User $user, Client $client): bool
    {
        return $this->appartientAuMagasinActif($client) && $user->can(PermissionRegistry::CLIENT_DELETE);
    }
}
