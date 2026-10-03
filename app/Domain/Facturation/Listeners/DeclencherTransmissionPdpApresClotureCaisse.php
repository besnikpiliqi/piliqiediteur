<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\ClotureCaisseEffectuee;
use App\Domain\Facturation\Jobs\SubmitClotureCaisseToPdpJob;
use App\Domain\Tenancy\Models\MandatPdp;

/**
 * Symétrique de DeclencherTransmissionPdpApresEmissionFacture : ici pas
 * de condition sur le type de client, une clôture de caisse n'agrège par
 * construction que des ventes à des particuliers (voir
 * CloturerCaisseAction) -- seule l'existence d'un mandat PDP actif
 * conditionne l'envoi.
 */
class DeclencherTransmissionPdpApresClotureCaisse
{
    public function handle(ClotureCaisseEffectuee $event): void
    {
        $cloture = $event->cloture;

        $mandat = MandatPdp::query()->where('magasin_id', $cloture->magasin_id)->first();

        if ($mandat === null || ! $mandat->statut->autoriseLaTransmission()) {
            return;
        }

        SubmitClotureCaisseToPdpJob::dispatch($cloture->magasin_id, $cloture->id)->afterCommit();
    }
}
