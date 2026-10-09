<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Prépare les autorisations d'un nouveau magasin : chaque permission de
 * l'application (voir PermissionRegistry), commune à tous les magasins,
 * et le seul rôle Spatie du magasin, celui du propriétaire ('owner'), qui
 * les porte toutes. Un membre de l'équipe n'a pas de rôle Spatie : il
 * reçoit directement, dans ce magasin, les autorisations que le
 * propriétaire coche pour lui (voir CreerMembreMagasinAction).
 *
 * Exige que TenantContext soit déjà positionné sur le magasin concerné
 * (Spatie rattache le rôle créé au magasin actif par le resolver, voir
 * MagasinTeamResolver).
 */
class SeedMagasinRolesAction
{
    public function handle(Magasin $magasin): void
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new LogicException('TenantContext doit être positionné sur le magasin avant de semer ses rôles.');
        }

        foreach (PermissionRegistry::toutes() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(RoleMagasin::Proprietaire->value, 'web')
            ->syncPermissions(RoleMagasin::Proprietaire->permissionsParDefaut());
    }
}
