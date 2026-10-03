<?php

namespace App\Policies;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Models\User;

/**
 * Pas de confidentialité par créateur ici, contrairement à FacturePolicy :
 * une facture d'achat est un document de coût interne visible par toute
 * l'équipe ayant facture_achat.view, pas un document de vente attribué à
 * un vendeur (voir le docblock de PermissionRegistry).
 */
class FactureAchatPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_ACHAT_VIEW);
    }

    public function view(User $user, FactureAchat $factureAchat): bool
    {
        return $this->appartientAuMagasinActif($factureAchat) && $user->can(PermissionRegistry::FACTURE_ACHAT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_ACHAT_CREER);
    }

    /**
     * Un brouillon n'a jamais reçu de numéro interne ni impacté le stock
     * (voir ValiderFactureAchatAction) : il reste librement modifiable,
     * lignes comprises. Dès la validation, la modification redevient
     * refusée sans exception : une correction passe par un avoir
     * fournisseur, jamais par une réécriture.
     */
    public function update(User $user, FactureAchat $factureAchat): bool
    {
        return $this->view($user, $factureAchat)
            && $factureAchat->statut === StatutFactureAchat::Brouillon
            && $user->can(PermissionRegistry::FACTURE_ACHAT_CREER);
    }

    public function valider(User $user, FactureAchat $factureAchat): bool
    {
        return $this->view($user, $factureAchat) && $user->can(PermissionRegistry::FACTURE_ACHAT_VALIDER);
    }

    /**
     * Même logique que FacturePolicy::delete() : un brouillon n'a aucune
     * existence légale, il peut être supprimé librement. Dès la
     * validation, sa suppression est refusée sans exception et sans
     * condition de rôle.
     */
    public function delete(User $user, FactureAchat $factureAchat): bool
    {
        return $this->view($user, $factureAchat)
            && $factureAchat->statut === StatutFactureAchat::Brouillon
            && $user->can(PermissionRegistry::FACTURE_ACHAT_CREER);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_ACHAT_CREER);
    }

    public function forceDelete(User $user, FactureAchat $factureAchat): bool
    {
        return false;
    }

    public function restore(User $user, FactureAchat $factureAchat): bool
    {
        return false;
    }
}
