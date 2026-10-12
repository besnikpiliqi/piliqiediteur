<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Change d'un seul coup les affectations d'un membre : pour chaque magasin
 * visé, son nouveau rôle et ses cases, ou son retrait. Chaque changement
 * est autorisé magasin par magasin (voir PorteeDesDroits) :
 * - nommer, retirer ou déplacer un responsable : le propriétaire, ou un
 *   administrateur autorisé à nommer les responsables, dans les magasins de
 *   sa portée ; rétrograder un responsable : le propriétaire seul ;
 * - le rôle et les cases d'un vendeur ou d'un observateur, et son retrait
 *   d'un magasin : le propriétaire, ou un responsable de tous ses magasins ;
 * - lui ajouter un magasin quand il en a déjà un : le propriétaire seul ;
 * - un compte au mot de passe provisoire ne reçoit un nouveau magasin ou un
 *   rôle de responsable que de la personne qui l'a créé.
 *
 * L'ensemble est atomique : ajouter un magasin et en retirer un autre ne
 * passe jamais par aucun magasin. S'il ne lui reste aucune affectation, le
 * compte est retiré de la société, seulement par qui peut l'y retirer :
 * ses sessions se ferment et ses notifications s'effacent. Une nomination
 * par un administrateur prévient le propriétaire.
 *
 * Tout se contrôle sur l'auteur et le compte relus sous les verrous des
 * registres des magasins touchés et de leurs lignes (voir JournalDesDroits),
 * qui inscrit le JET 130 de chaque magasin touché et l'entrée d'audit.
 */
class ModifierMembreAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PAS_AFFECTE = 'Cette personne n\'est pas affectée à ce magasin.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly AffectationsDesMembres $affectations,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}|null>  $changements  par identifiant de magasin : le nouveau rôle et les cases, ou null pour retirer le magasin
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $compte, array $changements): User
    {
        if ($changements === []) {
            return $compte;
        }

        return $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, function () use ($auteur, $compte, $changements): User {
            $demandes = $this->affectations->lire($compte->societe()->firstOrFail(), $changements);
            $ecrites = [];

            $this->journalDesDroits->modifier(
                $auteur,
                $this->actionAuditee($compte, $demandes),
                [$compte],
                array_map(fn (array $demande): Magasin => $demande['magasin'], array_filter($demandes)),
                function (User $auteurRelu, array $relus) use ($demandes, &$ecrites): void {
                    [$compteRelu] = $relus;
                    $ecrites = $this->appliquer($auteurRelu, $compteRelu, $demandes);
                },
            );

            $this->affectations->prevenirDesNominations($compte, $ecrites);

            return $compte->refresh();
        });
    }

    /**
     * @param  array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>|null}|null>  $demandes
     * @return list<Affectation> les affectations écrites
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function appliquer(User $auteur, User $compte, array $demandes): array
    {
        $actuelles = $compte->affectationsDeMembre()->keyBy(fn (Affectation $affectation): int => (int) $affectation->magasin_id);
        $etat = array_fill_keys($actuelles->keys()->all(), true);

        foreach ($demandes as $magasinId => $demande) {
            $etat[$magasinId] = $demande !== null;
        }

        $apres = array_keys(array_filter($etat));
        $nouveaux = array_diff($apres, $actuelles->keys()->all());

        if (count($apres) > 1 && $nouveaux !== [] && ! $this->portee->peutAffecterAPlusieursMagasins($auteur)) {
            throw new AuthorizationException(PorteeDesDroits::REFUS_PLUSIEURS_MAGASINS);
        }

        $cases = [];

        foreach ($demandes as $magasinId => $demande) {
            if ($demande === null) {
                $actuelle = $actuelles->get($magasinId) ?? throw new LogicException(self::MESSAGE_PAS_AFFECTE);

                if (! $this->portee->peutRetirerLAffectation($auteur, $actuelle, $apres === [])) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                continue;
            }

            if (! $this->portee->peutDonnerLeRole($auteur, $compte, $demande['magasin'], $demande['role'])) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $cases[$magasinId] = $this->affectations->cases($auteur, $demande);
        }

        $ecrites = [];

        foreach ($demandes as $magasinId => $demande) {
            if ($demande === null) {
                $actuelles->get($magasinId)->delete();

                continue;
            }

            $ecrites[] = $this->affectations->ecrire($auteur, $compte, $demande['magasin'], $demande['role'], $cases[$magasinId]);
        }

        $retires = array_keys(array_filter($demandes, fn (?array $demande): bool => $demande === null));
        $compte->effacerLesNotificationsDesMagasins($retires);

        if ($apres === []) {
            $compte->forceFill(['statut' => StatutCompte::Retire, 'statut_change_par' => $auteur->getKey()])->save();
            $compte->effacerToutesSesNotifications();
            $this->sessions->fermer($compte);
        }

        return $ecrites;
    }

    /**
     * L'action d'audit du changement : le retrait de la société quand le
     * dernier magasin part, une nomination ou un retrait de responsable,
     * sinon un changement de droits.
     *
     * @param  array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>|null}|null>  $demandes
     */
    private function actionAuditee(User $compte, array $demandes): ActionAuditee
    {
        $actuelles = $compte->affectationsDeMembre()->keyBy(fn (Affectation $affectation): int => (int) $affectation->magasin_id);
        $restants = array_diff($actuelles->keys()->all(), array_keys(array_filter($demandes, fn (?array $demande): bool => $demande === null)));
        $ajoutes = array_keys(array_filter($demandes));

        return match (true) {
            $restants === [] && $ajoutes === [] => ActionAuditee::CompteRetire,
            collect($demandes)->contains(fn (?array $demande, int $magasinId): bool => $demande !== null && $demande['role'] === RoleMagasin::Responsable && $actuelles->get($magasinId)?->role !== RoleMagasin::Responsable) => ActionAuditee::ResponsableNomme,
            collect($demandes)->contains(fn (?array $demande, int $magasinId): bool => $actuelles->get($magasinId)?->role === RoleMagasin::Responsable && ($demande === null || $demande['role'] !== RoleMagasin::Responsable)) => ActionAuditee::ResponsableRetire,
            default => ActionAuditee::DroitsModifies,
        };
    }
}
