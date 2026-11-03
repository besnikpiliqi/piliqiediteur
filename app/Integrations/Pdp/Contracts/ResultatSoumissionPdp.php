<?php

namespace App\Integrations\Pdp\Contracts;

use App\Integrations\Pdp\Enums\StatutTransmissionPdp;

final readonly class ResultatSoumissionPdp
{
    public function __construct(
        public StatutTransmissionPdp $statut,
        public ?string $transmissionExterneId = null,
        public ?string $format = null,
        public ?string $codeErreur = null,
        public ?string $messageErreur = null,
        public ?string $cheminFichierArchive = null,
    ) {}
}
