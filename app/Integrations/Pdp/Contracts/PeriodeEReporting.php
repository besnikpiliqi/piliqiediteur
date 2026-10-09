<?php

namespace App\Integrations\Pdp\Contracts;

use Carbon\CarbonImmutable;

/**
 * Bornes d'une période d'e-reporting (voir CalculateurPeriodeEReporting)
 * et sa date limite légale de transmission à cette date de calcul.
 */
final readonly class PeriodeEReporting
{
    public function __construct(
        public CarbonImmutable $debut,
        public CarbonImmutable $fin,
        public CarbonImmutable $dateLimiteTransmission,
    ) {}
}
