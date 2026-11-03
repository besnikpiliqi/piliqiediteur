<?php

namespace App\Integrations\Ordre;

/**
 * Ce que l'annuaire de l'Ordre des experts-comptables dit de l'inscription
 * d'une société au tableau (voir AnnuaireDeLOrdre) : son SIREN, sa
 * dénomination, son n° d'inscription, son conseil régional, et si elle est
 * inscrite au tableau, ni radiée ni suspendue.
 */
final readonly class InscriptionALOrdre
{
    public function __construct(
        public string $siren,
        public ?string $denomination,
        public string $numero,
        public string $conseilRegional,
        public bool $inscrite,
    ) {}
}
