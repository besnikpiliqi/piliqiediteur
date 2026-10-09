<?php

namespace App\Domain\Stock\Events;

use App\Domain\Catalogue\Models\Produit;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un mouvement de stock vient de faire passer un produit à son seuil
 * d'alerte, ou dessous (voir StockLedgerService). Seulement une fois le
 * mouvement validé : une vente annulée par sa transaction n'alerte
 * personne.
 */
class StockPasseSousLeSeuilDAlerte implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Produit $produit) {}
}
