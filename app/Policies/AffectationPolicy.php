<?php

namespace App\Policies;

use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * L'équipe d'un magasin, dans son panneau (voir EquipeDuMagasinResource) :
 * les affectations de membre (avec un rôle de magasin) du magasin actif,
 * que voient et gèrent son propriétaire et ses responsables, selon les
 * règles de PorteeDesDroits ; jamais une affectation d'un autre magasin,
 * ni celle d'un administrateur, que ni un vendeur, ni un administrateur, ni
 * un expert-comptable ne voient. Une affectation ne se supprime que par le
 * retrait du membre de ce magasin (delete).
 */
class AffectationPolicy
{
    public function __construct(private readonly PorteeDesDroits $portee) {}

    public function viewAny(User $user): bool
    {
        $contexte = app(TenantContext::class);

        return $contexte->has() && $this->portee->gereLEquipeDe($user, $contexte->magasin());
    }

    public function view(User $user, Affectation $affectation): bool
    {
        $contexte = app(TenantContext::class);

        return $this->viewAny($user)
            && (int) $affectation->magasin_id === $contexte->id()
            && $affectation->role !== null;
    }

    /**
     * Créer un vendeur ou un observateur du magasin actif : son
     * propriétaire ou un responsable du magasin (l'action contrôle le
     * rôle : un responsable se nomme par le propriétaire).
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Changer le rôle et les cases de cette affectation : celle d'un
     * vendeur ou d'un observateur, par qui gère ce compte tout entier ;
     * celle d'un responsable, par le propriétaire seul (la rétrograder).
     */
    public function update(User $user, Affectation $affectation): bool
    {
        if (! $this->view($user, $affectation) || (int) $affectation->user_id === (int) $user->getKey()) {
            return false;
        }

        $compte = User::query()->find($affectation->user_id);

        if ($compte === null) {
            return false;
        }

        return $affectation->estDeResponsable()
            ? $user->estProprietaire()
            : $this->portee->gereLeCompte($user, $compte);
    }

    /**
     * Retirer le membre de ce magasin (de la société, si c'était son
     * dernier magasin).
     */
    public function delete(User $user, Affectation $affectation): bool
    {
        return $this->view($user, $affectation) && $this->portee->peutRetirerLAffectation($user, $affectation);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Affectation $affectation): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Affectation $affectation): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Affectation $affectation): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
