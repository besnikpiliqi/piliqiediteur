<?php

namespace App\Policies;

use App\Domain\Tenancy\Models\EntreeAudit;
use App\Models\User;

/**
 * Le journal d'audit d'une société (voir JournalAuditResource, et, pour
 * un cabinet d'expertise comptable, JournalAuditDuCabinetResource) : son
 * propriétaire seul le consulte, entrées et traces techniques (adresse IP
 * et navigateur) comprises, même quand la société est en lecture seule
 * (suspendue, résiliée, révoquée ou close) ; jamais un administrateur,
 * quelles que soient ses autorisations, ni un membre, ni un compte d'un
 * cabinet lié, ni le compte d'une autre société (ControleDesDroits refuse
 * en amont tout geste dans l'espace d'une autre société). Personne ne crée,
 * ne modifie ni ne supprime une entrée : seul JournalAudit l'écrit, et la
 * base refuse toute modification.
 */
class EntreeAuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->peutAgir() && $user->estProprietaire();
    }

    public function view(User $user, EntreeAudit $entree): bool
    {
        return $this->viewAny($user) && $entree->societe_id === $user->societe_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EntreeAudit $entree): bool
    {
        return false;
    }

    public function delete(User $user, EntreeAudit $entree): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
