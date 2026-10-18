<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * Un inventaire d'ouverture se consulte avec les factures d'achat : c'est,
 * comme elles, un document de coût interne du magasin (voir
 * FactureAchatPolicy). Il se saisit, se modifie et se valide par une
 * personne autorisée à valider les achats et à créer des produits, puisque
 * sa validation crée des produits et fait entrer du stock, et seulement
 * jusqu'à la première vente du magasin (voir Magasin::aVendu()) ; validé,
 * il ne se modifie ni ne se supprime plus. Un brouillon resté après la
 * première vente se supprime encore, sans rien créer (voir
 * SupprimerInventaireOuvertureAction).
 *
 * Le propriétaire et les responsables du magasin ont toujours ces
 * autorisations ; un expert-comptable le consulte sans jamais le saisir
 * (voir ControleDesDroits), et un magasin en lecture seule ne le saisit
 * plus.
 */
class InventaireOuverturePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_ACHAT_VIEW);
    }

    public function view(User $user, InventaireOuverture $inventaire): bool
    {
        return $this->appartientAuMagasinActif($inventaire) && $user->can(PermissionRegistry::FACTURE_ACHAT_VIEW);
    }

    public function create(User $user): bool
    {
        $contexte = app(TenantContext::class);

        return $contexte->has() && $this->peutSaisirLesInventaires($user) && ! $contexte->magasin()->aVendu();
    }

    public function update(User $user, InventaireOuverture $inventaire): bool
    {
        return $this->view($user, $inventaire) && $inventaire->estBrouillon() && $this->create($user);
    }

    public function valider(User $user, InventaireOuverture $inventaire): bool
    {
        return $this->update($user, $inventaire);
    }

    public function delete(User $user, InventaireOuverture $inventaire): bool
    {
        return $this->view($user, $inventaire) && $inventaire->estBrouillon() && $this->peutSaisirLesInventaires($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->peutSaisirLesInventaires($user);
    }

    public function forceDelete(User $user, InventaireOuverture $inventaire): bool
    {
        return false;
    }

    public function restore(User $user, InventaireOuverture $inventaire): bool
    {
        return false;
    }

    /**
     * Valider les achats et créer des produits, dans le magasin actif.
     */
    private function peutSaisirLesInventaires(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_ACHAT_VALIDER) && $user->can(PermissionRegistry::PRODUIT_CREATE);
    }
}
