<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * « Garder » la nomination d'un responsable par un administrateur : le
 * propriétaire, prévenu de la nomination, la confirme (décision de
 * l'éditeur du 01/11/2026, Q-AF13 : « Paul a été nommé responsable de
 * Lyon par Julie » reste dans l'« À faire » de la société jusqu'à ce que
 * le propriétaire clique « Garder » ou « Annuler la nomination »). Le
 * propriétaire seul (voir PorteeDesDroits::peutGarderLaNomination()), tant
 * que l'affectation est celle de cette nomination.
 *
 * Rien ne change des droits de la personne, qui reste responsable : la
 * nomination cesse seulement d'être annulable (ses traces, nomme_par et
 * avant_la_nomination, s'effacent, comme quand une autre affectation la
 * remplace). Sous les verrous des lignes de l'auteur et du compte relus
 * (voir JournalDesDroits::sousVerrouDesComptes()), puis de l'affectation ;
 * le geste s'inscrit au journal d'audit, dans la chaîne du magasin de la
 * nomination, avec le compte et l'administrateur qui l'a nommé.
 */
class GarderUneNominationAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $auteur, Affectation $affectation): void
    {
        $compte = User::query()->findOrFail($affectation->user_id);

        $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, fn () => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus) use ($affectation): void {
            [$compteRelu] = $relus;
            $relue = Affectation::query()->with(['magasin', 'nommePar'])->whereKey($affectation->getKey())->lockForUpdate()->first();

            if ($relue === null || (int) $relue->user_id !== CleDuModele::de($compteRelu) || ! $this->portee->peutGarderLaNomination($auteurRelu, $relue)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $this->journalAudit->inscrire(ActionAuditee::NominationGardee, $auteurRelu, $relue->magasin, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
                'nomme_par' => ['id' => $relue->nomme_par, 'nom' => $relue->nommePar?->name],
            ]);

            $relue->forceFill(['nomme_par' => null, 'avant_la_nomination' => null])->save();
        }));
    }
}
