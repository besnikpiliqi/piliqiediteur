<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Paiement;
use App\Models\User;

/**
 * Un paiement enregistré ne se modifie ni ne se supprime jamais (voir
 * ProtegeLesDonneesValidees) : une erreur de saisie se corrige par un nouveau
 * paiement, jamais en réécrivant l'historique d'encaissement -- même
 * logique que l'interdiction de suppression des factures.
 *
 * Confidentialité par vendeur, même règle que FacturePolicy : un paiement
 * révèle le chiffre d'affaires encaissé, donc sans FACTURE_VIEW_TOUTES un
 * utilisateur ne voit que les paiements qu'il a lui-même enregistrés.
 */
class PaiementPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::PAIEMENT_VIEW);
    }

    public function view(User $user, Paiement $paiement): bool
    {
        if (! $this->appartientAuMagasinActif($paiement)) {
            return false;
        }

        if (! $user->can(PermissionRegistry::PAIEMENT_VIEW)) {
            return false;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW_TOUTES) || $paiement->cree_par === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::PAIEMENT_CREER);
    }

    public function update(User $user, Paiement $paiement): bool
    {
        return false;
    }

    public function delete(User $user, Paiement $paiement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Paiement $paiement): bool
    {
        return false;
    }

    public function restore(User $user, Paiement $paiement): bool
    {
        return false;
    }
}
