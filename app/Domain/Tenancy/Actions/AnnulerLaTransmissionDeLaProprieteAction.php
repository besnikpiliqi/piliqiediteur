<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Le propriétaire annule la transmission de la propriété qui attend pour
 * un administrateur (voir DemanderLaTransmissionDeLaProprieteAction) : son
 * lien ne sert plus. Les lignes du propriétaire et de l'administrateur se
 * verrouillent d'abord, par identifiant croissant, puis la ligne de la
 * demande : dans le même ordre que l'acceptation, qui verrouille les
 * registres, puis ces deux comptes, puis la demande (voir
 * AccepterLaTransmissionDeLaProprieteAction). Aucun interblocage, même par
 * l'entrée du journal d'audit, dont la clé vers le compte de l'auteur
 * attend une acceptation qui tiendrait déjà ce compte ; une seule des deux
 * aboutit. L'annulation s'inscrit au journal d'audit (TransmissionAnnulee)
 * et l'administrateur en est prévenu dans sa cloche.
 */
class AnnulerLaTransmissionDeLaProprieteAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly JournalDesDroits $journalDesDroits,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException quand aucune demande n'attend pour cet administrateur
     */
    public function handle(User $auteur, User $administrateur): void
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        $this->dansLaSociete($societe, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $administrateur, $societe): void {
            $this->journalDesDroits->sousVerrouDesComptes($auteur, [$administrateur], function (User $auteurRelu) use ($administrateur, $societe): void {
                DemanderLaTransmissionDeLaProprieteAction::exigerLeProprietaire($auteurRelu, $societe);

                $demande = TransmissionPropriete::query()
                    ->where('societe_id', $societe->getKey())
                    ->where('destinataire_id', $administrateur->getKey())
                    ->whereNull('annulee_le')
                    ->whereNull('acceptee_le')
                    ->where('expire_le', '>', now())
                    ->lockForUpdate()
                    ->first();

                if ($demande === null) {
                    throw new LogicException(DemanderLaTransmissionDeLaProprieteAction::MESSAGE_AUCUNE_DEMANDE);
                }

                $demande->forceFill(['annulee_le' => now()])->save();
                $this->journalAudit->inscrire(ActionAuditee::TransmissionAnnulee, $auteurRelu, null, null, [
                    'destinataire' => ['id' => $administrateur->getKey(), 'nom' => $administrateur->name],
                ], $demande);
            });
        });

        $administrateur->notify(new AvisDeLEquipe(
            $societe,
            'Transmission de la propriété annulée',
            "{$auteur->name} a annulé la transmission de la propriété de {$societe->getFilamentName()} : le lien qui vous a été envoyé ne sert plus.",
        ));
    }
}
