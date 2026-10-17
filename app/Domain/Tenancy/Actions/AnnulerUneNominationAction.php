<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Annule, en un clic, la nomination d'un responsable par un administrateur
 * : le propriétaire, qui en a été prévenu, ou l'administrateur qui l'a
 * faite par erreur, s'il peut toujours nommer un responsable dans ce
 * magasin ; jamais un autre administrateur (voir
 * PorteeDesDroits::peutAnnulerLaNomination()). Seulement tant que
 * l'affectation est celle de cette nomination.
 *
 * Le rôle de responsable disparaît de ce magasin :
 * - une promotion rend à la personne son rôle et ses cases d'avant ;
 * - une affectation que la nomination avait créée disparaît ; si c'était le
 *   dernier magasin de la personne (son compte créé par la nomination,
 *   par exemple), elle est retirée de la société, ses sessions se ferment.
 * Aucun document émis n'est touché. Sous les verrous des registres des
 * magasins du compte et des lignes de l'auteur et du compte relus, avec le
 * JET 130 de chaque magasin touché et l'entrée d'audit (voir
 * JournalDesDroits).
 */
class AnnulerUneNominationAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $auteur, Affectation $affectation): void
    {
        $compte = User::query()->findOrFail($affectation->user_id);

        $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, function () use ($auteur, $affectation, $compte): void {
            $this->journalDesDroits->modifier($auteur, ActionAuditee::NominationAnnulee, [$compte], [], function (User $auteurRelu, array $relus) use ($affectation): void {
                [$compteRelu] = $relus;
                $relue = Affectation::query()->whereKey($affectation->getKey())->lockForUpdate()->first();

                if ($relue === null || (int) $relue->user_id !== (int) $compteRelu->getKey() || ! $this->portee->peutAnnulerLaNomination($auteurRelu, $relue)) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $avant = $relue->avant_la_nomination;

                if ($avant === null) {
                    $magasinId = (int) $relue->magasin_id;
                    $relue->delete();
                    $compteRelu->effacerLesNotificationsDesMagasins([$magasinId]);

                    if ($compteRelu->affectationsDeMembre()->isEmpty()) {
                        $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Retire, $auteurRelu))->save();
                        $compteRelu->effacerToutesSesNotifications();
                        $this->sessions->fermer($compteRelu);
                    }

                    return;
                }

                $relue->forceFill(['role' => RoleMagasin::from($avant['role']), 'nomme_par' => null, 'avant_la_nomination' => null])->save();
                $relue->cocher($avant['autorisations']);
            });
        });
    }
}
