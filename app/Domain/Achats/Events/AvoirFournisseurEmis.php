<?php

namespace App\Domain\Achats\Events;

use App\Domain\Achats\Models\AvoirFournisseur;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit, même raisonnement que
 * FactureAchatValidee : la décrémentation du stock (voir
 * DecrementerStockApresEmissionAvoirFournisseur) doit être atomique avec
 * l'émission de l'avoir.
 */
class AvoirFournisseurEmis
{
    use Dispatchable;

    public function __construct(public readonly AvoirFournisseur $avoirFournisseur) {}
}
