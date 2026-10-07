<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\SoumissionEReportingGeneree;
use App\Domain\Facturation\Jobs\SubmitSoumissionEReportingToPdpJob;
use App\Domain\Tenancy\Models\MandatPdp;

/**
 * La transmission se déclenche après la génération d'une SOUMISSION
 * agrégée sur toute une période, jamais après chaque clôture quotidienne
 * individuelle -- voir le docblock de SoumissionEReporting.
 */
class DeclencherTransmissionPdpApresSoumissionEReporting
{
    public function handle(SoumissionEReportingGeneree $event): void
    {
        $soumission = $event->soumission;

        $mandat = MandatPdp::query()->where('magasin_id', $soumission->magasin_id)->first();

        if ($mandat === null || ! $mandat->statut->autoriseLaTransmission()) {
            return;
        }

        SubmitSoumissionEReportingToPdpJob::dispatch($soumission->magasin_id, $soumission->id)->afterCommit();
    }
}
