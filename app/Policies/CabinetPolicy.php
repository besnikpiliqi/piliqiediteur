<?php

namespace App\Policies;

use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Models\User;

/**
 * Les gestes sur la vérification d'un cabinet d'expertise comptable (voir
 * Cabinet), dans l'espace du cabinet (voir CabinetPanelProvider), jamais
 * pour le compte d'une autre société :
 * - la consulter : les comptes actifs du cabinet ;
 * - faire vérifier son inscription au tableau de l'Ordre (voir
 *   VerifierLOrdreAction) : son propriétaire seul, tant qu'elle n'est pas
 *   vérifiée, dans un cabinet qui n'est ni révoqué ni clos ; une radiation
 *   constatée par l'éditeur ne se lève que par sa procédure (voir
 *   ConstaterLInscriptionALOrdreAction).
 * Un cabinet en lecture seule (suspendu, résilié) n'y garde que la
 * consultation (voir ControleDesDroits).
 */
class CabinetPolicy
{
    public function view(User $user, Cabinet $cabinet): bool
    {
        return $user->peutAgir() && $user->estDUnCabinet() && $user->societe_id === $cabinet->societe_id;
    }

    public function verifierLOrdre(User $user, Cabinet $cabinet): bool
    {
        $societe = $cabinet->societe()->first();

        return $this->view($user, $cabinet)
            && $user->estProprietaire()
            && $societe !== null
            && $societe->statut !== StatutSociete::Revoquee
            && ! $societe->estClose()
            && $cabinet->ordre_verifie_le === null
            && $cabinet->ordre_retire_le === null;
    }
}
