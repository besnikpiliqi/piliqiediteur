<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Changer le rôle d'un membre doit TOUJOURS re-synchroniser son rôle
 * Spatie réel pour ce magasin -- mettre à jour uniquement la colonne
 * `role` du pivot sans toucher à l'assignation Spatie laisserait les
 * permissions effectives désynchronisées de ce qui est affiché à l'écran.
 *
 * Un membre qui n'est plus actif (suspendu) perd aussi ses notifications
 * à propos de ce magasin, comme s'il l'avait quitté (voir
 * RetirerMembreMagasinAction).
 *
 * Un changement de rôle ou de statut s'inscrit au journal des événements
 * du magasin (JET 130, voir JournalDesDroits).
 */
class MettreAJourMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    public function handle(MagasinUser $membre, RoleMagasin $nouveauRole, StatutMembreMagasin $nouveauStatut): MagasinUser
    {
        if ($membre->role === RoleMagasin::Proprietaire || $nouveauRole === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne se modifie pas via la gestion d\'équipe.');
        }

        return DB::transaction(function () use ($membre, $nouveauRole, $nouveauStatut) {
            $magasin = $membre->magasin;
            $utilisateur = $membre->utilisateur;
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, $utilisateur);
            $ancienRole = $membre->role;

            $membre->update([
                'role' => $nouveauRole->value,
                'statut' => $nouveauStatut->value,
            ]);

            if ($ancienRole !== $nouveauRole) {
                $utilisateur->removeRole($ancienRole->value);
                $utilisateur->assignRole($nouveauRole->value);
            }

            if ($nouveauStatut !== StatutMembreMagasin::Actif) {
                $utilisateur->effacerLesNotificationsDu($magasin);
            }

            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);

            return $membre;
        });
    }
}
