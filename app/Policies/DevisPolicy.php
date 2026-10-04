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
     * Voir Devis::estModifiable() : modifiable quel que soit son statut
     * tant qu'aucune facture n'en a été créée, jamais après.
     */
    public function update(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis)
            && $devis->estModifiable()
            && $user->can(PermissionRegistry::DEVIS_UPDATE);
    }

    public function envoyer(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_ENVOYER);
    }

    public function accepter(User $user, Devis $devis): bool
    {
        return $this->appartientAuMagasinActif($devis) && $user->can(PermissionRegistry::DEVIS_ENVOYER);
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
