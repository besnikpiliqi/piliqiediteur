<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;

/**
 * Le règlement d'une vente comptoir, encaissé à son émission (voir
 * EmettreFactureAction) : ce qui reste dû une fois le crédit d'avoir du
 * client appliqué, payé sur-le-champ dans ce mode. Il fait ainsi partie du
 * ticket signé au registre fiscal, au lieu d'arriver après lui.
 */
final readonly class ReglementComptant
{
    /**
     * @param  string|null  $reference  n° de transaction CB, de chèque...
     */
    public function __construct(
        public ModePaiement $mode,
        public ?string $reference = null,
    ) {}
}
