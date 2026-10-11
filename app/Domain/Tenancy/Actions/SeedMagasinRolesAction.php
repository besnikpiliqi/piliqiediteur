<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Prépare les autorisations que le propriétaire coche pour les membres de
 * l'équipe d'un nouveau magasin (voir CreerMembreMagasinAction) : chaque
 * permission de l'application (voir PermissionRegistry), commune à tous
 * les magasins. Les permissions manquantes s'ajoutent sans heurter l'index
 * unique (name, guard_name) quand deux magasins se créent au même instant
 * (insertOrIgnore) ; le cache des permissions, qui a pu être lu avant que
 * l'autre création ne les ajoute, est ensuite oublié.
 *
 * Aucun rôle n'est créé : le propriétaire de la société a tous les droits
 * sans case (voir ControleDesDroits), et un membre reçoit directement,
 * dans son magasin, les autorisations cochées pour lui.
 */
class SeedMagasinRolesAction
{
    public function handle(): void
    {
        $maintenant = now();

        Permission::query()->insertOrIgnore(array_map(static fn (string $permission): array => [
            'name' => $permission,
            'guard_name' => 'web',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], PermissionRegistry::toutes()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
