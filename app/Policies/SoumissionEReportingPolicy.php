<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Models\User;

/**
 * Une soumission agrège les clôtures de caisse de toute l'équipe sur une
 * période : même permission que cloture_caisse.view_toutes (voir
 * ClotureCaissePolicy), pas de confidentialité par créateur puisqu'il n'y
 * a pas de créateur individuel -- ce document est généré par le système.
 *
 * Jamais créée, modifiée ni supprimée depuis l'UI : uniquement via
 * GenererSoumissionEReportingAction (voir le docblock de la classe).
 */
class SoumissionEReportingPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES);
    }

    public function view(User $user, SoumissionEReporting $soumission): bool
    {
        return $this->appartientAuMagasinActif($soumission) && $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SoumissionEReporting $soumission): bool
    {
        return false;
    }

    public function delete(User $user, SoumissionEReporting $soumission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SoumissionEReporting $soumission): bool
    {
        return false;
    }

    public function restore(User $user, SoumissionEReporting $soumission): bool
    {
        return false;
    }
}
