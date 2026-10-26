<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Change le rôle et les cases d'un membre dans son magasin, le seul de son
 * compte, avec le même compte et le même identifiant (voir PorteeDesDroits) :
 * - nommer responsable un vendeur ou un observateur : le propriétaire, ou
 *   un administrateur autorisé à nommer les responsables, dans un magasin
 *   réel de sa portée ; le propriétaire en est prévenu, et la nomination
 *   s'annule en un clic (voir AnnulerUneNominationAction) ;
 * - rétrograder un responsable : le propriétaire seul ;
 * - le rôle et les cases d'un vendeur ou d'un observateur : le propriétaire,
 *   ou un responsable de son magasin.
 * Un compte ne change jamais de magasin : une personne qui change de
 * magasin y reçoit un nouveau compte. Le retirer de son magasin le retire
 * de la société (voir ChangerStatutCompteAction::retirer()).
 *
 * Tout se contrôle sur l'auteur et le compte relus sous les verrous du
 * registre du magasin et de leurs lignes (voir JournalDesDroits), qui
 * inscrit le JET 130 du magasin et l'entrée d'audit, avec les rôles avant
 * et après.
 */
class ModifierMembreAction
{
    use AgitDansLaSociete;

    public const MESSAGE_SANS_MAGASIN = 'Ce compte n\'est plus affecté à aucun magasin.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly AffectationsDesMembres $affectations,
    ) {}

    /**
     * @param  list<string>|null  $autorisations  les cases d'un vendeur ou d'un observateur ; null, le modèle de son rôle
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $compte, RoleMagasin|string $role, ?array $autorisations = null): User
    {
        $demandee = $this->affectations->lire($role, $autorisations);
        $magasin = $compte->magasinDeSonIdentifiant() ?? throw new AuthorizationException(PorteeDesDroits::REFUS);

        $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, function () use ($auteur, $compte, $magasin, $demandee): void {
            $actuelle = $compte->affectationDeMembre();
            $action = match (true) {
                $demandee['role'] === RoleMagasin::Responsable && $actuelle?->role !== RoleMagasin::Responsable => ActionAuditee::ResponsableNomme,
                $actuelle?->role === RoleMagasin::Responsable && $demandee['role'] !== RoleMagasin::Responsable => ActionAuditee::ResponsableRetire,
                default => ActionAuditee::DroitsModifies,
            };

            $ecrite = $this->journalDesDroits->modifier($auteur, $action, [$compte], [$magasin], function (User $auteurRelu, array $relus) use ($magasin, $demandee) {
                [$compteRelu] = $relus;

                if ($compteRelu->affectationDeMembre() === null) {
                    throw new LogicException(self::MESSAGE_SANS_MAGASIN);
                }

                if (! $this->portee->peutDonnerLeRole($auteurRelu, $compteRelu, $magasin, $demandee['role'])) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                return $this->affectations->ecrire($auteurRelu, $compteRelu, $magasin, $demandee['role'], $this->affectations->cases($auteurRelu, $magasin, $demandee));
            });

            $this->affectations->prevenirDUneNomination($compte, $ecrite, compteCree: false);
        });

        return $compte->refresh();
    }
}
