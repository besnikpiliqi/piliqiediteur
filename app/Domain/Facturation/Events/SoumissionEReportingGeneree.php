<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\SoumissionEReporting;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit : c'est le listener PDP qui
 * diffère SON envoi via ->afterCommit() (voir
 * DeclencherTransmissionPdpApresSoumissionEReporting), pour qu'un appel au
 * prestataire ne parte jamais avant que la soumission soit enregistrée.
 */
class SoumissionEReportingGeneree
{
    use Dispatchable;

    public function __construct(public readonly SoumissionEReporting $soumission) {}
}
