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
     * Un brouillon n'a jamais reçu de numéro légal (voir delete()) : il
     * reste librement modifiable, lignes comprises, pour éviter d'obliger
     * l'utilisateur à tout ressaisir pour corriger une erreur de saisie.
     * Dès que la facture quitte ce statut, la modification redevient
     * refusée sans exception : une facture émise se corrige par un avoir
     * (voir Avoir), jamais en la réécrivant.
     */
    public function update(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture)
            && $facture->statut === StatutFacture::Brouillon
            && $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function emettre(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_EMETTRE);
    }

    /**
     * Envoyer par e-mail ou relancer : seulement une facture que
     * l'utilisateur voit (confidentialité par vendeur, voir view()).
     */
    public function envoyer(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_ENVOYER);
    }

    /**
     * Un brouillon n'a jamais reçu de numéro (attribué uniquement à
     * l'émission, voir EmettreFactureAction) : il n'a donc aucune existence
     * légale ni fiscale, le supprimer ne casse aucune continuité de
     * numérotation. Dès que la facture quitte ce statut, sa suppression
     * est refusée sans exception et sans condition de rôle, y compris pour
     * le propriétaire : la loi impose la conservation de tout document
     * numéroté. Correction d'une facture déjà émise = avoir (voir
     * EnregistrerAvoirAction), jamais suppression.
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
