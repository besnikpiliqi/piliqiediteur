<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\SoumissionEReporting;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit, même raisonnement que
 * ClotureCaisseEffectuee : le listener PDP diffère SON dispatch via
 * ->afterCommit() (voir DeclencherTransmissionPdpApresSoumissionEReporting).
 */
class SoumissionEReportingGeneree
{
    use Dispatchable;

    public function __construct(public readonly SoumissionEReporting $soumission) {}
}
