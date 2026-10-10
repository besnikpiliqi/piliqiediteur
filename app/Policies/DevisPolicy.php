<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Devis;
use App\Models\User;

class DevisPolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::DEVIS_VIEW);
    }

    public function view(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::DEVIS_CREATE);
    }

    /**
     * Un brouillon, ou un devis envoyé ou accepté pas encore transformé en
     * facture, qui repasse alors en brouillon (voir Devis::estModifiable()).
     */
    public function update(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis)
            && $devis->estModifiable()
            && $user->can(PermissionRegistry::DEVIS_UPDATE);
    }

    /**
     * Seul un brouillon jamais envoyé (voir Devis::estSupprimable()), par
     * ceux qui modifient les devis : jamais l'observateur.
     */
    public function delete(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis)
            && $devis->estSupprimable()
            && $user->can(PermissionRegistry::DEVIS_UPDATE);
    }

    /**
     * Le bouton de suppression en masse ; chaque devis coché repasse
     * ensuite par delete() (voir SupprimeEnMasseLigneParLigne).
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::DEVIS_UPDATE);
    }

    public function forceDelete(User $user, Devis $devis): bool
    {
        return false;
    }

    public function restore(User $user, Devis $devis): bool
    {
        return false;
    }

    /**
     * Le numéroter, et l'envoyer par e-mail à son client (voir
     * EnvoyerDocumentAction).
     */
    public function envoyer(User $user, Devis $devis): bool
    {
        return $this->view($user, $devis) && $user->can(PermissionRegistry::DEVIS_ENVOYER);
    }

    /**
     * Jamais une fois sa date de validité dépassée (voir
     * AccepterDevisAction).
     */
    public function accepter(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis)
            && ! $devis->validiteDepassee()
            && $user->can(PermissionRegistry::DEVIS_ENVOYER);
    }

    public function refuser(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_ENVOYER);
    }

    /**
     * Convertir crée une Facture : on vérifie la permission de création de
     * facture, pas seulement celle du devis.
     */
    public function convertir(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::FACTURE_CREATE);
    }
}
