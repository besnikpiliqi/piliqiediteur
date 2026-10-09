<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Change le rôle, le statut et les autorisations d'un membre, ensemble ou
 * pas du tout.
 *
 * Le rôle n'est qu'un modèle (voir RoleMagasin::permissionsParDefaut()) :
 * le propriétaire coche les autorisations de chaque membre. Lui seul
 * change donc le rôle et les autorisations d'un membre : une liste qu'il
 * fournit remplace celles du membre ; sans liste, un changement de rôle
 * donne le modèle du nouveau rôle, et ses autorisations ne changent pas
 * sinon. Un autre membre qui gère l'équipe ne change que le statut : il ne
 * se donne ni ne donne jamais une autorisation, et ne défait jamais ce que
 * le propriétaire a coché.
 *
 * Le rôle d'avant est relu sous le verrou du registre, qui sérialise les
 * modifications des droits du magasin : deux modifications simultanées ne
 * se fondent jamais sur un rôle périmé.
 *
 * Un membre qui n'est plus actif (suspendu) perd aussi ses notifications à
 * propos de ce magasin, comme s'il l'avait quitté (voir
 * RetirerMembreMagasinAction).
 *
 * La modification s'inscrit au journal des événements du magasin, en un
 * seul événement avec les droits d'avant et d'après (JET 130, voir
 * JournalDesDroits).
 */
class MettreAJourMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  list<string>|null  $permissions  les autorisations que le propriétaire coche ; null : le modèle du nouveau rôle s'il change, les siennes sinon
     *
     * @throws InvalidArgumentException quand une autorisation est inconnue
     * @throws LogicException quand un autre membre que le propriétaire change le rôle ou les autorisations
     */
    public function handle(User $auteur, MagasinUser $membre, RoleMagasin $nouveauRole, StatutMembreMagasin $nouveauStatut, ?array $permissions = null): MagasinUser
    {
        if ($membre->role === RoleMagasin::Proprietaire || $nouveauRole === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne se modifie pas via la gestion d\'équipe.');
        }

        $parLeProprietaire = $auteur->estProprietaireDe($membre->magasin);

        if ($permissions !== null) {
            if (! $parLeProprietaire) {
                throw new LogicException('Seul le propriétaire coche les autorisations d\'un membre.');
            }

            $permissions = PermissionRegistry::verifierConnues($permissions);
        }

        return DB::transaction(function () use ($membre, $nouveauRole, $nouveauStatut, $permissions, $parLeProprietaire) {
            $magasin = $membre->magasin;
            $utilisateur = $membre->utilisateur;
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, $utilisateur);
            $ancienRole = $membre->refresh()->role;

            if ($ancienRole !== $nouveauRole && ! $parLeProprietaire) {
                throw new LogicException('Seul le propriétaire change le rôle d\'un membre.');
            }

            $membre->update([
                'role' => $nouveauRole->value,
                'statut' => $nouveauStatut->value,
            ]);

            if ($permissions === null && $ancienRole !== $nouveauRole) {
                $permissions = $nouveauRole->permissionsParDefaut();
            }

            if ($permissions !== null) {
                $utilisateur->syncPermissions($permissions);
            }

            if ($nouveauStatut !== StatutMembreMagasin::Actif) {
                $utilisateur->effacerLesNotificationsDu($magasin);
            }

            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);

            return $membre;
        });
    }
}
