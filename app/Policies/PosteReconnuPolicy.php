<?php

namespace App\Policies;

use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * Les postes reconnus du magasin actif (voir PosteReconnuResource et
 * PostesReconnus) : son propriétaire et ses responsables les voient, en
 * reconnaissent un et en retirent un, tant que leur société n'est pas
 * révoquée ; personne d'autre. Un poste ne se supprime jamais : il se
 * retire, et reste listé.
 */
class PosteReconnuPolicy
{
    public function __construct(private readonly PorteeDesDroits $portee) {}

    public function viewAny(User $user): bool
    {
        $contexte = app(TenantContext::class);

        return $contexte->has() && $this->portee->gereLEquipeDe($user, $contexte->magasin());
    }

    public function view(User $user, PosteReconnu $poste): bool
    {
        return $this->viewAny($user) && (int) $poste->magasin_id === app(TenantContext::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $this->portee->gereSonEquipe($user);
    }

    /**
     * Retirer ce poste.
     */
    public function update(User $user, PosteReconnu $poste): bool
    {
        return $this->view($user, $poste) && $this->portee->gereSonEquipe($user) && ! $poste->estRetire();
    }

    public function delete(User $user, PosteReconnu $poste): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, PosteReconnu $poste): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, PosteReconnu $poste): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, PosteReconnu $poste): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
