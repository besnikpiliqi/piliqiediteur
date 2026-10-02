<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\Facture;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit : ce event doit se
 * déclencher immédiatement, à l'intérieur de la même transaction que
 * l'émission, pour que le listener de décrément de stock (voir
 * DecrementerStockApresEmissionFacture) soit atomique avec l'émission --
 * une facture émise sans que son stock soit décrémenté serait pire qu'une
 * émission qui échoue entièrement.
 *
 * Le listener PDP, lui, gère sa propre temporalité en différant SON
 * dispatch de job via ->afterCommit() plutôt que de retarder le event
 * entier (voir DeclencherTransmissionPdpApresEmissionFacture).
 */
class FactureEmise
{
    use Dispatchable;

    public function __construct(public readonly Facture $facture) {}
}
