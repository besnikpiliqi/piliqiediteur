<?php

namespace App\Integrations\Pdp\Contracts;

use App\Domain\Tenancy\Enums\StatutMandatPdp;

final readonly class ResultatMandatPdp
{
    /**
     * @param  array<string, mixed>|null  $donneesBrutes  la réponse de la plateforme, telle quelle
     */
    public function __construct(
        public StatutMandatPdp $statut,
        public ?string $mandatExterneId = null,
        public ?array $donneesBrutes = null,
    ) {}
}
