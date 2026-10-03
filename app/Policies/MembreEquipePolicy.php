<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Models\User;

/**
 * Le propriétaire n'est JAMAIS modifiable ni supprimable via cette
 * gestion d'équipe : il n'existe qu'un seul chemin pour le devenir (la
 * création du magasin), jamais via ce formulaire -- sinon n'importe quel
 * administrateur pourrait évincer le propriétaire ou s'auto-promouvoir.
 */
class MembreEquipePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::EQUIPE_VIEW);
    }

    public function view(User $user, MagasinUser $membre): bool
    {
        return $this->appartientAuMagasinActif($membre) && $user->can(PermissionRegistry::EQUIPE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::EQUIPE_CREER);
    }

    public function update(User $user, MagasinUser $membre): bool
    {
        return $this->appartientAuMagasinActif($membre)
            && $membre->role !== RoleMagasin::Proprietaire
            && $user->can(PermissionRegistry::EQUIPE_MODIFIER);
    }

    public function delete(User $user, MagasinUser $membre): bool
    {
        return $this->appartientAuMagasinActif($membre)
            && $membre->role !== RoleMagasin::Proprietaire
            && $user->can(PermissionRegistry::EQUIPE_MODIFIER);
    }
}
