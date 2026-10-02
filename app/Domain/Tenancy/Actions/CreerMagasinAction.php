<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique pour la création d'un magasin : l'enregistrement
 * seul ne suffit jamais, il faut toujours dans la même transaction semer
 * ses rôles par défaut, rattacher le propriétaire, et activer ce magasin
 * comme magasin courant de l'utilisateur. Oublier une de ces étapes
 * laisserait un magasin dans un état incohérent (rôles manquants,
 * personne n'y ayant accès...).
 *
 * Le statut est mis directement à `active` : aucune vérification SIRET
 * automatisée n'est branchée pour l'instant (nécessiterait une
 * intégration API Sirene/INSEE, prévue mais pas encore implémentée).
 * À revoir si une étape de vérification doit bloquer l'activation.
 */
class CreerMagasinAction
{
    public function __construct(private readonly SeedMagasinRolesAction $seedMagasinRoles) {}

    /**
     * @param  array<string, mixed>  $donneesMagasin
     */
    public function handle(array $donneesMagasin, User $proprietaire): Magasin
    {
        return DB::transaction(function () use ($donneesMagasin, $proprietaire) {
            $magasin = Magasin::create([
                ...$donneesMagasin,
                'statut' => StatutMagasin::Active,
            ]);

            app(TenantContext::class)->set($magasin);

            $this->seedMagasinRoles->handle($magasin);

            $magasin->utilisateurs()->attach($proprietaire->getKey(), [
                'role' => RoleMagasin::Proprietaire->value,
                'statut' => StatutMembreMagasin::Actif->value,
                'joined_at' => now(),
            ]);

            $proprietaire->assignRole(RoleMagasin::Proprietaire->value);
            $proprietaire->forceFill(['current_magasin_id' => $magasin->getKey()])->save();

            return $magasin;
        });
    }
}
