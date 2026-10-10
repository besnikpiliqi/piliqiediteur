<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Prépare les autorisations d'un nouveau magasin : chaque permission de
 * l'application (voir PermissionRegistry), commune à tous les magasins,
 * et le seul rôle Spatie du magasin, celui du propriétaire ('owner'), qui
 * les porte toutes. Les permissions manquantes s'ajoutent sans heurter
 * l'index unique (name, guard_name) quand deux magasins s'inscrivent au
 * même instant (insertOrIgnore) ; le cache des permissions, qui a pu être
 * lu avant que l'autre inscription ne les crée, est ensuite oublié.
 *
 * Un membre de l'équipe n'a pas de rôle Spatie : il reçoit directement,
 * dans ce magasin, les autorisations que le propriétaire coche pour lui
 * (voir CreerMembreMagasinAction).
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

        $maintenant = now();

        Permission::query()->insertOrIgnore(array_map(static fn (string $permission): array => [
            'name' => $permission,
            'guard_name' => 'web',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], PermissionRegistry::toutes()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(RoleMagasin::Proprietaire->value, 'web')
            ->syncPermissions(RoleMagasin::Proprietaire->permissionsParDefaut());
    }
}
