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
     * Voir FactureAchat::estModifiable() : un brouillon reste toujours
     * librement modifiable par qui saisit les factures d'achat (aucun
     * impact sur le stock, voir ValiderFactureAchatAction). Une fois
     * validée, seule une facture saisie manuellement reste modifiable --
     * une récupérée par API ne l'est jamais -- et la corriger, c'est
     * corriger une réception déjà entrée en stock (voir
     * ModifierFactureAchatAction) : il faut en plus le droit de valider les
     * factures d'achat.
     */
    public function update(User $user, FactureAchat $factureAchat): bool
    {
        return $this->view($user, $factureAchat)
            && $factureAchat->estModifiable()
            && $user->can(PermissionRegistry::FACTURE_ACHAT_CREER)
            && ($factureAchat->statut !== StatutFactureAchat::Validee || $user->can(PermissionRegistry::FACTURE_ACHAT_VALIDER));
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
