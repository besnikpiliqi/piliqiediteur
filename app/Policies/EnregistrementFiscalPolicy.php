<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Models\User;

/**
 * Le registre fiscal (caisse sécurisée) se consulte avec
 * caisse_securisee.consulter : le propriétaire, l'administrateur et le
 * comptable par défaut (voir RoleMagasin::permissionsParDefaut()), sans
 * confidentialité par vendeur, puisqu'il retrace l'activité de tout le
 * magasin. La permission est celle du magasin actif (voir
 * MagasinTeamResolver) : sans magasin actif, aucune ne s'applique, et un
 * enregistrement ne se consulte que dans son propre magasin.
 *
 * Rien d'autre n'est permis : un enregistrement ne s'écrit que par
 * RegistreFiscal, et ne se modifie ni ne se supprime jamais (voir
 * ProtegeLesDonneesValidees et DeclencheursAjoutSeul).
 */
class EnregistrementFiscalPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER);
    }

    public function view(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return $this->appartientAuMagasinActif($enregistrement) && $user->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return false;
    }

    public function delete(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, EnregistrementFiscal $enregistrement): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
