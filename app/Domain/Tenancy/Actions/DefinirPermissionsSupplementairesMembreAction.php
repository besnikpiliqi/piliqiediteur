<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use LogicException;

/**
 * Permet au propriétaire d'accorder À UN MEMBRE PRÉCIS des permissions au
 * delà de celles de son rôle (ex : un vendeur qui doit exceptionnellement
 * voir les avoirs ou la valeur du stock) sans avoir à créer un rôle
 * dédié. Spatie distingue permissions "de rôle" et permissions "directes"
 * sur l'utilisateur -- syncPermissions() ne touche ici QUE les secondes,
 * les permissions héritées du rôle restent intactes.
 */
class DefinirPermissionsSupplementairesMembreAction
{
    /**
     * @param  list<string>  $permissions
     */
    public function handle(MagasinUser $membre, array $permissions): void
    {
        if ($membre->role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le propriétaire possède déjà toutes les permissions, inutile d\'en ajouter.');
        }

        app(TenantContext::class)->set($membre->magasin);
        $membre->utilisateur->syncPermissions($permissions);
    }
}
