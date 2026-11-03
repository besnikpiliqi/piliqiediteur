<?php

namespace App\Integrations\Pdp\Contracts;

use App\Domain\Tenancy\Enums\StatutMandatPdp;

final readonly class ResultatMandatPdp
{
    public function __construct(
        public StatutMandatPdp $statut,
        public ?string $mandatExterneId = null,
        public ?array $donneesBrutes = null,
    ) {}
}
