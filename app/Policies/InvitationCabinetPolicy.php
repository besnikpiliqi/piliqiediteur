<?php

namespace App\Policies;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;

/**
 * Les invitations d'un cabinet d'expertise comptable : son propriétaire
 * seul les voit, en envoie (son cabinet vérifié, voir
 * Societe::estUnCabinetVerifie()) et retire une invitation ouverte ; un
 * collaborateur ne les voit pas, elles nomment des sociétés qui ne lui
 * sont pas confiées. L'acceptation et le refus passent par le jeton du
 * lien, sans compte (voir AccepterLInvitationAction).
 */
class InvitationCabinetPolicy
{
    public function __construct(private readonly PorteeDesDroits $portee) {}

    public function viewAny(User $user): bool
    {
        return $user->peutAgir() && $user->estProprietaire() && $user->estDUnCabinet();
    }

    public function view(User $user, InvitationCabinet $invitation): bool
    {
        return $this->viewAny($user) && (int) $invitation->societe_id === (int) $user->societe_id;
    }

    public function create(User $user, ?Societe $cabinet = null): bool
    {
        $cabinet ??= $user->societe;

        return $this->viewAny($user)
            && $this->portee->gereSonEquipe($user)
            && (int) $cabinet->getKey() === (int) $user->societe_id
            && $cabinet->estUnCabinetVerifie();
    }

    public function retirer(User $user, InvitationCabinet $invitation): bool
    {
        return $this->view($user, $invitation) && $this->portee->gereSonEquipe($user) && $invitation->etat === EtatInvitation::Ouverte;
    }

    public function delete(User $user, InvitationCabinet $invitation): bool
    {
        return false;
    }
}
