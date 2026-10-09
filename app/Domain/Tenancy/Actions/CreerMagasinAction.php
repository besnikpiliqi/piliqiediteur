<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique pour la création d'un magasin : l'enregistrement
 * seul ne suffit jamais, il faut toujours dans la même transaction créer
 * le rôle de son propriétaire (voir SeedMagasinRolesAction), créer son
 * client comptoir et rattacher le propriétaire. Oublier une de ces étapes
 * laisserait un magasin dans un état incohérent (rôle manquant, personne
 * n'y ayant accès...).
 *
 * Le statut est mis directement à `active` : aucune vérification du
 * SIRET (API Sirene de l'INSEE) ne précède l'activation d'un magasin.
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

            // Vente au comptoir (particulier anonyme, sans coordonnées
            // saisies) : plutôt que de forcer un nom à chaque vente, un
            // client générique réutilisable existe dès la création du
            // magasin, reconnu à est_comptoir (voir Client::estComptoir()).
            // Les clients particuliers nommés (fidélité, retour produit...)
            // restent des fiches à part entière, créées au besoin via le
            // "+" du formulaire de devis/facture.
            Client::forceCreate([
                'nom' => Client::NOM_COMPTOIR,
                'type_client' => TypeClient::Particulier,
                'est_comptoir' => true,
            ]);

            $magasin->utilisateurs()->attach($proprietaire->getKey(), [
                'role' => RoleMagasin::Proprietaire->value,
                'statut' => StatutMembreMagasin::Actif->value,
                'joined_at' => now(),
            ]);

            $proprietaire->assignRole(RoleMagasin::Proprietaire->value);

            return $magasin;
        });
    }
}
