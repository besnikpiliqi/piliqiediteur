<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Tenancy\Models\MandatPdp;

/**
 * Décide SI une facture doit être transmise au PDP, sans jamais faire
 * l'appel lui-même (ça, c'est le job, dispatché seulement une fois la
 * transaction d'émission commitée -- voir SubmitFactureToPdpJob).
 *
 * Deux conditions, aucune des deux n'est une erreur si elle manque :
 *  - client professionnel (un particulier relève de l'e-reporting agrégé
 *    via la clôture de caisse, pas de l'e-invoicing facture par facture) ;
 *  - mandat PDP actif pour ce magasin (sans mandat, on ne peut
 *    légalement pas transmettre en son nom).
 */
class DeclencherTransmissionPdpApresEmissionFacture
{
    public function handle(FactureEmise $event): void
    {
        $facture = $event->facture;

        if (! $facture->relevantDeLeInvoicing()) {
            return;
        }

        $mandat = MandatPdp::query()->where('magasin_id', $facture->magasin_id)->first();

        if ($mandat === null || ! $mandat->statut->autoriseLaTransmission()) {
            return;
        }

        SubmitFactureToPdpJob::dispatch($facture->magasin_id, $facture->id)->afterCommit();
    }
}
