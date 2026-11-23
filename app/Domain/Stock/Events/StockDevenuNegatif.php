<?php

namespace App\Domain\Stock\Events;

use App\Domain\Catalogue\Models\Produit;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une vente confirmée après l'avertissement vient de faire passer ce
 * produit sous zéro (R1, voir EmettreFactureAction) : sa quantité négative
 * reste à régulariser. Seulement une fois la vente validée : une vente
 * dont la transaction échoue n'alerte personne.
 */
class StockDevenuNegatif implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $numeroDeLaVente  le numéro de la facture ou du ticket qui l'a fait passer sous zéro
     */
    public function __construct(
        public readonly Produit $produit,
        public readonly string $numeroDeLaVente,
    ) {}
}
