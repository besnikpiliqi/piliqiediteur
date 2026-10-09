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
 * administrateur pourrait évincer le propriétaire.
 *
 * Personne ne modifie ni ne retire non plus sa propre ligne : on ne change
 * pas ses propres droits, on ne se donne pas de mot de passe sans le mot
 * de passe actuel (voir ReinitialiserMotDePasseMembreAction), et un
 * retrait par erreur ne se rattrape pas (son adresse e-mail ne sert plus
 * jamais à un nouveau membre).
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
        return $this->estUnAutreMembreDuMagasinActif($user, $membre) && $user->can(PermissionRegistry::EQUIPE_MODIFIER);
    }

    public function delete(User $user, MagasinUser $membre): bool
    {
        return $this->estUnAutreMembreDuMagasinActif($user, $membre) && $user->can(PermissionRegistry::EQUIPE_MODIFIER);
    }

    /**
     * Un membre du magasin actif, ni le propriétaire ni celui qui agit.
     */
    private function estUnAutreMembreDuMagasinActif(User $user, MagasinUser $membre): bool
    {
        return $this->appartientAuMagasinActif($membre)
            && $membre->role !== RoleMagasin::Proprietaire
            && ! $membre->utilisateur()->is($user);
    }
}
