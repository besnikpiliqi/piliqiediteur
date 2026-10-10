<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;

/**
 * Le règlement d'une vente comptoir, encaissé à son émission (voir
 * EmettreFactureAction) : le bon d'avoir présenté au comptoir, s'il y en
 * a un, impute d'abord son crédit restant sur ce que le crédit d'avoir du
 * client laisse dû, puis ce qui reste dû est payé sur-le-champ dans ce
 * mode. Il fait ainsi partie du document signé au registre fiscal, au
 * lieu d'arriver après lui.
 */
final readonly class ReglementComptant
{
    /**
     * @param  string|null  $reference  n° de transaction CB, de chèque...
     * @param  Avoir|null  $bonDAvoir  l'avoir au client comptoir dont le numéro a été présenté, quel que soit le client de la vente, relu sous le verrou du registre (voir EnregistrerVenteCaisseAction)
     */
    public function __construct(
        public ModePaiement $mode,
        public ?string $reference = null,
        public ?Avoir $bonDAvoir = null,
    ) {}
}
