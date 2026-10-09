<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Permet au propriétaire d'accorder À UN MEMBRE PRÉCIS des permissions au
 * delà de celles de son rôle (ex : un vendeur qui doit exceptionnellement
 * voir les avoirs ou la valeur du stock) sans avoir à créer un rôle
 * dédié. Spatie distingue permissions "de rôle" et permissions "directes"
 * sur l'utilisateur -- syncPermissions() ne touche ici QUE les secondes,
 * les permissions héritées du rôle restent intactes.
 *
 * Un changement de ces permissions s'inscrit au journal des événements du
 * magasin (JET 130, voir JournalDesDroits).
 */
class DefinirPermissionsSupplementairesMembreAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(MagasinUser $membre, array $permissions): void
    {
        if ($membre->role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le propriétaire possède déjà toutes les permissions, inutile d\'en ajouter.');
        }

        DB::transaction(function () use ($membre, $permissions): void {
            $magasin = $membre->magasin;
            $utilisateur = $membre->utilisateur;
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, $utilisateur);

            $utilisateur->syncPermissions($permissions);

            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);
        });
    }
}
