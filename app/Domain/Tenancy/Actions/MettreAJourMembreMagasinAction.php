<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Changer le rôle d'un membre doit TOUJOURS re-synchroniser son rôle
 * Spatie réel pour ce magasin -- mettre à jour uniquement la colonne
 * `role` du pivot sans toucher à l'assignation Spatie laisserait les
 * permissions effectives désynchronisées de ce qui est affiché à l'écran.
 */
class MettreAJourMembreMagasinAction
{
    public function handle(MagasinUser $membre, RoleMagasin $nouveauRole, StatutMembreMagasin $nouveauStatut): MagasinUser
    {
        if ($membre->role === RoleMagasin::Proprietaire || $nouveauRole === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne se modifie pas via la gestion d\'équipe.');
        }

        return DB::transaction(function () use ($membre, $nouveauRole, $nouveauStatut) {
            $ancienRole = $membre->role;
            $utilisateur = $membre->utilisateur;

            $membre->update([
                'role' => $nouveauRole->value,
                'statut' => $nouveauStatut->value,
            ]);

            if ($ancienRole !== $nouveauRole) {
                app(TenantContext::class)->set($membre->magasin);
                $utilisateur->removeRole($ancienRole->value);
                $utilisateur->assignRole($nouveauRole->value);
            }

            return $membre;
        });
    }
}
