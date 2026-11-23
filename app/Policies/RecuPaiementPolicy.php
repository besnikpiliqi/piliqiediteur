<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Models\User;

/**
 * Le reçu de paiement d'un encaissement ou d'une contre-passation (R2,
 * voir RecusDePaiement) se télécharge comme ses paiements se voient (voir
 * PaiementPolicy::view()) : avec « Paiements : voir », et, sans « voir
 * celles de toute l'équipe », seulement le reçu d'un encaissement que l'on
 * a soi-même fait ; un compte du cabinet lié qui voit les paiements du
 * client le télécharge. Il part par e-mail chez qui peut envoyer chacun des
 * documents qu'il règle (voir FacturePolicy::envoyer()) : jamais un compte
 * du cabinet lié, qui n'envoie rien. Il ne se modifie ni ne se supprime
 * jamais.
 */
class RecuPaiementPolicy extends MagasinPolicy
{
    public function view(User $user, RecuPaiement $recu): bool
    {
        if (! $this->appartientAuMagasinActif($recu) || ! $user->can(PermissionRegistry::PAIEMENT_VIEW)) {
            return false;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW_TOUTES) || $recu->cree_par === $user->getKey();
    }

    public function envoyer(User $user, RecuPaiement $recu): bool
    {
        return $this->view($user, $recu)
            && $user->can(PermissionRegistry::FACTURE_ENVOYER)
            && $recu->lignes->every(fn (LigneRecuPaiement $ligne): bool => $user->can('envoyer', $ligne->facture));
    }

    public function update(User $user, RecuPaiement $recu): bool
    {
        return false;
    }

    public function delete(User $user, RecuPaiement $recu): bool
    {
        return false;
    }
}
