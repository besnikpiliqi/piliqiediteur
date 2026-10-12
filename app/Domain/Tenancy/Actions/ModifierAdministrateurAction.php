<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Le propriétaire, et lui seul, modifie un administrateur :
 * - modifier() : sa portée et ses autorisations, remplacées d'un coup
 *   (voir PorteeDesAdministrateurs) ;
 * - retrograder() : il redevient membre, avec ses nouvelles affectations
 *   dans la même action, jamais sans magasin ; sa portée et ses
 *   autorisations de société disparaissent d'abord, puis il repasse
 *   membre, puis reçoit ses affectations de membre (voir GardesDesDroits).
 *
 * Contrôlé sur l'auteur et le compte relus sous les verrous des registres
 * des magasins touchés et de leurs lignes ; JET 130 dans chacun (tous les
 * magasins de la société pour un administrateur de tous les magasins),
 * puis l'entrée d'audit (voir JournalDesDroits).
 */
class ModifierAdministrateurAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
        private readonly AffectationsDesMembres $affectations,
    ) {}

    /**
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin
     * @param  array<array-key, mixed>  $autorisationsSociete
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function modifier(User $auteur, User $administrateur, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): User
    {
        $societe = $administrateur->societe()->firstOrFail();

        $this->dansLaSociete($societe, CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $administrateur, $societe, $magasins, $autorisationsMagasin, $autorisationsSociete): void {
            CreerAdministrateurAction::exigerLeProprietaire($auteur, $societe);
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);

            $this->journalDesDroits->modifier($auteur, ActionAuditee::AdministrateurModifie, [$administrateur], $this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu, array $relus) use ($societe, $portee): void {
                [$administrateurRelu] = $relus;
                self::exigerUnAdministrateur($auteurRelu, $administrateurRelu, $societe->getKey());

                $this->porteeDesAdministrateurs->effacer($administrateurRelu);
                $this->porteeDesAdministrateurs->ecrire($administrateurRelu, $portee);
            });
        });

        return $administrateur->refresh();
    }

    /**
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}>  $affectations  ses affectations de membre, par identifiant de magasin
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function retrograder(User $auteur, User $administrateur, array $affectations): User
    {
        $societe = $administrateur->societe()->firstOrFail();

        $this->dansLaSociete($societe, CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $administrateur, $societe, $affectations): void {
            CreerAdministrateurAction::exigerLeProprietaire($auteur, $societe);
            $demandees = array_filter($this->affectations->lire($societe, $affectations));

            if ($demandees === []) {
                throw new LogicException(AffectationsDesMembres::MESSAGE_SANS_MAGASIN);
            }

            $this->journalDesDroits->modifier($auteur, ActionAuditee::AdministrateurModifie, [$administrateur], array_map(fn (array $demandee): Magasin => $demandee['magasin'], $demandees), function (User $auteurRelu, array $relus) use ($societe, $demandees): void {
                [$administrateurRelu] = $relus;
                self::exigerUnAdministrateur($auteurRelu, $administrateurRelu, $societe->getKey());

                $verifiees = $this->affectations->verifierPourUnNouveauCompte($auteurRelu, $demandees);
                $this->porteeDesAdministrateurs->effacer($administrateurRelu);
                $administrateurRelu->forceFill(['role_societe' => RoleSociete::Membre])->save();

                foreach ($verifiees as $affectation) {
                    $this->affectations->ecrire($auteurRelu, $administrateurRelu, $affectation['magasin'], $affectation['role'], $affectation['autorisations']);
                }
            });
        });

        return $administrateur->refresh();
    }

    /**
     * Le propriétaire de la société, et un administrateur de cette société.
     *
     * @throws AuthorizationException
     */
    private static function exigerUnAdministrateur(User $auteur, User $administrateur, int $societeId): void
    {
        if (! $auteur->peutAgir() || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societeId
            || ! $administrateur->estAdministrateur() || (int) $administrateur->societe_id !== $societeId) {
            throw new AuthorizationException(PorteeDesDroits::REFUS);
        }
    }
}
