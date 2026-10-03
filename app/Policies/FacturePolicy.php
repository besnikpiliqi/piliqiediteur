<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Models\User;

/**
 * Confidentialité par vendeur (décision produit explicite) : sans
 * FACTURE_VIEW_TOUTES, un utilisateur ne voit que les factures qu'il a
 * lui-même créées. Les clients restent partagés (voir ClientPolicy) --
 * seul l'historique de vente est cloisonné.
 */
class FacturePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_VIEW);
    }

    public function view(User $user, Facture $facture): bool
    {
        if (! $this->appartientAuMagasinActif($facture)) {
            return false;
        }

        if ($user->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            return true;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW) && $facture->cree_par === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    /**
     * Toujours refusé, y compris sur un brouillon : une facture se crée,
     * s'émet, s'annule, se supprime (si jamais numérotée) ou se corrige
     * par un avoir -- jamais en la modifiant après coup. Si une ligne est
     * fausse sur un brouillon, on le supprime et on recommence plutôt que
     * de l'éditer en place.
     */
    public function update(User $user, Facture $facture): bool
    {
        return false;
    }

    public function emettre(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_EMETTRE);
    }

    public function annuler(User $user, Facture $facture): bool
    {
        return $this->appartientAuMagasinActif($facture) && $user->can(PermissionRegistry::FACTURE_ANNULER);
    }

    /**
     * Un brouillon n'a jamais reçu de numéro (attribué uniquement à
     * l'émission, voir EmettreFactureAction) : il n'a donc aucune existence
     * légale ni fiscale, le supprimer ne casse aucune continuité de
     * numérotation. Dès que la facture quitte ce statut -- y compris après
     * une annulation -- sa suppression redevient refusée sans exception et
     * sans condition de rôle, y compris pour le propriétaire : la loi
     * impose la conservation de tout document numéroté. Correction d'une
     * facture déjà émise = avoir (voir Avoir), jamais suppression.
     */
    public function delete(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture)
            && $facture->statut === StatutFacture::Brouillon
            && $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function forceDelete(User $user, Facture $facture): bool
    {
        return false;
    }

    public function restore(User $user, Facture $facture): bool
    {
        return false;
    }
}
