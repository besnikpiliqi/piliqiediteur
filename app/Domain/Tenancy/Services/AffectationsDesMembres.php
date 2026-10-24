<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\ResponsableNommeParUnAdministrateur;
use App\Models\User;
use LogicException;

/**
 * L'affectation de membre que demande une action de l'équipe (créer un
 * compte, changer son rôle ou ses cases, le réintégrer), lue, contrôlée et
 * écrite une seule fois. Un compte de membre n'a qu'une affectation, dans
 * le magasin de son identifiant (voir GardesDesDroits) :
 * - un responsable n'a aucune case ; un vendeur ou un observateur reçoit
 *   les cases demandées, ou le modèle de son rôle, jamais au-delà de ce que
 *   l'auteur peut cocher dans ce magasin (voir PorteeDesDroits) ;
 * - un responsable nommé par un administrateur garde la trace de sa
 *   nomination, que le propriétaire, prévenu, ou cet administrateur peut
 *   annuler (voir AnnulerUneNominationAction).
 */
final class AffectationsDesMembres
{
    public const MESSAGE_RESPONSABLE_SANS_CASE = 'Un responsable n\'a aucune case : il a toutes les autorisations de son magasin.';

    public function __construct(private readonly PorteeDesDroits $portee) {}

    /**
     * Le rôle demandé, et ses cases : aucune pour un responsable, celles
     * demandées (ou null, le modèle du rôle) pour un vendeur ou un
     * observateur.
     *
     * @param  list<string>|null  $autorisations
     * @return array{role: RoleMagasin, autorisations: list<string>|null}
     *
     * @throws LogicException quand le rôle est inconnu ou qu'un responsable porte des cases
     */
    public function lire(RoleMagasin|string $role, ?array $autorisations): array
    {
        $role = $role instanceof RoleMagasin ? $role : (RoleMagasin::tryFrom($role) ?? throw new LogicException('Rôle de magasin inconnu.'));

        if (! $role->aDesCases() && $autorisations !== null && $autorisations !== []) {
            throw new LogicException(self::MESSAGE_RESPONSABLE_SANS_CASE);
        }

        return ['role' => $role, 'autorisations' => $role->aDesCases() ? $autorisations : null];
    }

    /**
     * Les cases de l'affectation, contrôlées : aucune pour un responsable ;
     * celles demandées, ou le modèle du rôle, pour un vendeur ou un
     * observateur, que l'auteur peut toutes cocher dans ce magasin.
     *
     * @param  array{role: RoleMagasin, autorisations: list<string>|null}  $demandee
     * @return list<string>
     *
     * @throws LogicException
     */
    public function cases(User $auteur, Magasin $magasin, array $demandee): array
    {
        if (! $demandee['role']->aDesCases()) {
            return [];
        }

        return $this->portee->verifierCochables($auteur, $magasin, $demandee['autorisations'] ?? $demandee['role']->permissionsParDefaut());
    }

    /**
     * Écrit l'affectation de ce compte à ce magasin, ou la change. Un
     * responsable que nomme un administrateur garde la trace de sa
     * nomination (son auteur, et le rôle et les cases d'avant pour une
     * promotion) ; toute autre écriture l'efface. Les cases d'une
     * affectation qui devient celle d'un responsable s'effacent avant son
     * nouveau rôle (voir GardesDesDroits).
     *
     * @param  list<string>  $autorisations
     */
    public function ecrire(User $auteur, User $compte, Magasin $magasin, RoleMagasin $role, array $autorisations): Affectation
    {
        $affectation = Affectation::query()->where('user_id', $compte->getKey())->where('magasin_id', $magasin->getKey())->first()
            ?? (new Affectation)->forceFill(['societe_id' => $magasin->societe_id, 'user_id' => $compte->getKey(), 'magasin_id' => $magasin->getKey()]);
        $nomination = $role === RoleMagasin::Responsable && $affectation->role !== RoleMagasin::Responsable && ! $auteur->estProprietaire();
        $avant = $affectation->exists && $affectation->role !== null && $affectation->role !== RoleMagasin::Responsable
            ? ['role' => $affectation->role->value, 'autorisations' => $affectation->autorisations()]
            : null;

        $resteResponsable = $role === RoleMagasin::Responsable && $affectation->role === RoleMagasin::Responsable;

        if ($affectation->exists && ! $role->aDesCases()) {
            $affectation->effacerLesCases();
        }

        $affectation->forceFill(['role' => $role]);

        if (! $resteResponsable) {
            $affectation->forceFill([
                'nomme_par' => $nomination ? $auteur->getKey() : null,
                'avant_la_nomination' => $nomination ? $avant : null,
            ]);
        }

        $affectation->save();

        if ($role->aDesCases()) {
            $affectation->cocher($autorisations);
        }

        return $affectation;
    }

    /**
     * Prévient le propriétaire du responsable qu'un administrateur vient de
     * nommer, ou dont il vient de créer le compte : il en connaît alors le
     * premier mot de passe, l'identifiant.
     */
    public function prevenirDUneNomination(User $compte, Affectation $affectation, bool $compteCree): void
    {
        if ($affectation->nomme_par === null || (! $affectation->wasRecentlyCreated && ! $affectation->wasChanged('nomme_par'))) {
            return;
        }

        $magasin = $affectation->magasin()->firstOrFail();
        $administrateur = User::query()->findOrFail($affectation->nomme_par);

        $magasin->societe->proprietaire?->notify(new ResponsableNommeParUnAdministrateur($administrateur, $compte, $magasin, $compteCree));
    }
}
