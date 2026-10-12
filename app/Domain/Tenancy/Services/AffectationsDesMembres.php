<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\ResponsableNommeParUnAdministrateur;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Les affectations de membre que demande une action de l'équipe (créer un
 * compte, le modifier, le réintégrer), lues, contrôlées et écrites une
 * seule fois :
 * - chaque magasin demandé est un magasin de la société, sinon le geste est
 *   refusé avant toute écriture (un magasin d'une autre société ne
 *   s'affecte jamais) ;
 * - un responsable n'a aucune case ; un vendeur ou un observateur reçoit
 *   les cases demandées, ou le modèle de son rôle, jamais au-delà de ce que
 *   l'auteur peut cocher dans ce magasin (voir PorteeDesDroits) ;
 * - un responsable nommé par un administrateur garde la trace de sa
 *   nomination, que le propriétaire, prévenu, ou cet administrateur peut
 *   annuler (voir AnnulerUneNominationAction).
 */
final class AffectationsDesMembres
{
    public const MESSAGE_SANS_MAGASIN = 'Un membre est affecté à au moins un magasin.';

    public const MESSAGE_RESPONSABLE_SANS_CASE = 'Un responsable n\'a aucune case : il a toutes les autorisations de son magasin.';

    public function __construct(private readonly PorteeDesDroits $portee) {}

    /**
     * Les affectations demandées, chaque magasin lu dans la société.
     *
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}|null>  $demandees  par identifiant de magasin ; null pour retirer le magasin
     * @return array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>|null}|null>
     *
     * @throws AuthorizationException quand un magasin n'est pas de la société
     * @throws LogicException quand un rôle est inconnu ou qu'un responsable porte des cases
     */
    public function lire(Societe $societe, array $demandees): array
    {
        $magasins = Magasin::query()->where('societe_id', $societe->getKey())->whereKey(array_keys($demandees))->get()->keyBy(fn (Magasin $magasin): int => (int) $magasin->getKey());
        $lues = [];

        foreach ($demandees as $magasinId => $demandee) {
            $magasin = $magasins->get((int) $magasinId) ?? throw new AuthorizationException(PorteeDesDroits::REFUS);

            if ($demandee === null) {
                $lues[(int) $magasinId] = null;

                continue;
            }

            $role = $demandee['role'] instanceof RoleMagasin ? $demandee['role'] : (RoleMagasin::tryFrom((string) $demandee['role']) ?? throw new LogicException('Rôle de magasin inconnu.'));
            $autorisations = $demandee['autorisations'] ?? null;

            if (! $role->aDesCases() && $autorisations !== null && $autorisations !== []) {
                throw new LogicException(self::MESSAGE_RESPONSABLE_SANS_CASE);
            }

            $lues[(int) $magasinId] = ['magasin' => $magasin, 'role' => $role, 'autorisations' => $role->aDesCases() ? $autorisations : null];
        }

        return $lues;
    }

    /**
     * Contrôle, pour un compte que l'auteur crée ou réintègre, des
     * affectations à donner : au moins une ; plusieurs, par le propriétaire
     * seul ; chacune, le rôle que l'auteur peut donner dans ce magasin, et
     * des cases qu'il peut cocher. Rend les cases de chacune, le modèle du
     * rôle à défaut.
     *
     * @param  array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>|null}|null>  $affectations
     * @return array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>}>
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function verifierPourUnNouveauCompte(User $auteur, array $affectations): array
    {
        $affectations = array_filter($affectations);

        if ($affectations === []) {
            throw new LogicException(self::MESSAGE_SANS_MAGASIN);
        }

        if (count($affectations) > 1 && ! $this->portee->peutAffecterAPlusieursMagasins($auteur)) {
            throw new AuthorizationException(PorteeDesDroits::REFUS_PLUSIEURS_MAGASINS);
        }

        $verifiees = [];

        foreach ($affectations as $magasinId => $affectation) {
            if (! $this->portee->peutDonnerLeRole($auteur, null, $affectation['magasin'], $affectation['role'])) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $verifiees[$magasinId] = [...$affectation, 'autorisations' => $this->cases($auteur, $affectation)];
        }

        return $verifiees;
    }

    /**
     * Les cases d'une affectation, contrôlées : aucune pour un responsable ;
     * celles demandées, ou le modèle du rôle, pour un vendeur ou un
     * observateur.
     *
     * @param  array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>|null}  $affectation
     * @return list<string>
     *
     * @throws LogicException
     */
    public function cases(User $auteur, array $affectation): array
    {
        if (! $affectation['role']->aDesCases()) {
            return [];
        }

        return $this->portee->verifierCochables($auteur, $affectation['magasin'], $affectation['autorisations'] ?? $affectation['role']->permissionsParDefaut());
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
     * Prévient le propriétaire de chaque responsable qu'un administrateur
     * vient de nommer.
     *
     * @param  list<Affectation>  $affectations
     */
    public function prevenirDesNominations(User $compte, array $affectations): void
    {
        foreach ($affectations as $affectation) {
            if ($affectation->nomme_par === null || (! $affectation->wasRecentlyCreated && ! $affectation->wasChanged('nomme_par'))) {
                continue;
            }

            $magasin = $affectation->magasin()->firstOrFail();
            $administrateur = User::query()->findOrFail($affectation->nomme_par);

            $magasin->societe->proprietaire?->notify(new ResponsableNommeParUnAdministrateur($administrateur, $compte, $magasin));
        }
    }
}
