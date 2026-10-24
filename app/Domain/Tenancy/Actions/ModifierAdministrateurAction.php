<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Le propriétaire, et lui seul, modifie la portée et les autorisations
 * d'un administrateur, remplacées d'un coup (voir PorteeDesAdministrateurs) ;
 * son rôle de société change par ChangerLeRoleDeSocieteAction.
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
     * Le propriétaire de la société, et un administrateur de cette société
     * qui n'est pas retiré (un administrateur retiré se réintègre, voir
     * ChangerStatutCompteAction::reintegrerLAdministrateur()).
     *
     * @throws AuthorizationException
     */
    private static function exigerUnAdministrateur(User $auteur, User $administrateur, int $societeId): void
    {
        if (! $auteur->peutAgir() || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societeId
            || ! $administrateur->estAdministrateur() || $administrateur->estRetire() || (int) $administrateur->societe_id !== $societeId) {
            throw new AuthorizationException(PorteeDesDroits::REFUS);
        }
    }
}
