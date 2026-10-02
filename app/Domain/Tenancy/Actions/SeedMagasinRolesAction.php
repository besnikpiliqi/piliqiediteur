<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Crée les 5 rôles Spatie par défaut pour un magasin, avec une matrice de
 * permissions raisonnable par défaut. Un propriétaire pourra ensuite
 * affiner via Filament Shield -- ceci ne fait qu'amorcer un magasin neuf
 * avec des rôles exploitables immédiatement.
 *
 * Exige que TenantContext soit déjà positionné sur le magasin concerné
 * (Spatie scope automatiquement les rôles créés par team via le resolver,
 * voir MagasinTeamResolver).
 */
class SeedMagasinRolesAction
{
    public function handle(Magasin $magasin): void
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new \LogicException('TenantContext doit être positionné sur le magasin avant de semer ses rôles.');
        }

        $toutesLesPermissions = PermissionRegistry::toutes();

        foreach ($toutesLesPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $permissionsLecture = [
            PermissionRegistry::CLIENT_VIEW,
            PermissionRegistry::FOURNISSEUR_VIEW,
            PermissionRegistry::PRODUIT_VIEW,
        ];

        $matrice = [
            RoleMagasin::Proprietaire->value => $toutesLesPermissions,
            RoleMagasin::Administrateur->value => array_values(array_diff(
                $toutesLesPermissions,
                [PermissionRegistry::MANDAT_PDP_GERER]
            )),
            RoleMagasin::Comptable->value => $permissionsLecture,
            RoleMagasin::Vendeur->value => [
                PermissionRegistry::CLIENT_VIEW,
                PermissionRegistry::CLIENT_CREATE,
                PermissionRegistry::CLIENT_UPDATE,
                PermissionRegistry::PRODUIT_VIEW,
            ],
            RoleMagasin::Observateur->value => $permissionsLecture,
        ];

        foreach ($matrice as $nomRole => $permissions) {
            Role::findOrCreate($nomRole, 'web')->syncPermissions($permissions);
        }
    }
}
