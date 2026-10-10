<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique pour la création d'un magasin : l'enregistrement
 * seul ne suffit jamais, il faut toujours dans la même transaction créer
 * le rôle de son propriétaire (voir SeedMagasinRolesAction), créer son
 * client comptoir et rattacher le propriétaire. Oublier une de ces étapes
 * laisserait un magasin dans un état incohérent (rôle manquant, personne
 * n'y ayant accès...).
 *
 * Le magasin est actif dès sa création (voir Magasin::booted()) : aucune
 * vérification du SIRET (API Sirene de l'INSEE) ne précède son activation.
 *
 * Son code (voir CodeMagasin) est celui que la personne qui s'inscrit a
 * choisi ; il ne se remplace jamais en silence : déjà pris, l'index unique
 * de magasins.code refuse la création, et le refus remonte (voir
 * RegisterMagasin). Sans code donné, le magasin reçoit celui que propose
 * son nom (voir Magasin::booted()).
 */
class CreerMagasinAction
{
    public function __construct(private readonly SeedMagasinRolesAction $seedMagasinRoles) {}

    /**
     * @param  array<string, mixed>  $donneesMagasin
     *
     * @throws UniqueConstraintViolationException
     */
    public function handle(array $donneesMagasin, User $proprietaire): Magasin
    {
        return DB::transaction(function () use ($donneesMagasin, $proprietaire) {
            $magasin = Magasin::create($donneesMagasin);

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
