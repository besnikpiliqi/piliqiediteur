<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\Avoir;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit, même raisonnement que
 * FactureEmise : la restauration de stock (voir
 * RestaurerStockApresEmissionAvoir) doit être atomique avec l'émission de
 * l'avoir.
 */
class AvoirEmis
{
    use Dispatchable;

    public function __construct(public readonly Avoir $avoir) {}
}
