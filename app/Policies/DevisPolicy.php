<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Devis;
use App\Models\User;

class DevisPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::DEVIS_VIEW);
    }

    public function view(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::DEVIS_CREATE);
    }

    public function update(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis)
            && $devis->estModifiable()
            && $user->can(PermissionRegistry::DEVIS_UPDATE);
    }

    public function envoyer(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_ENVOYER);
    }
}
