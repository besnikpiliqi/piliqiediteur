<?php

namespace App\Domain\Achats\Events;

use App\Domain\Achats\Models\FactureAchat;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit : l'incrémentation du
 * stock (voir IncrementerStockApresValidationFactureAchat) doit être
 * atomique avec la validation de la facture d'achat, même raisonnement
 * que FactureEmise côté vente.
 */
class FactureAchatValidee
{
    use Dispatchable;

    public function __construct(public readonly FactureAchat $factureAchat) {}
}
