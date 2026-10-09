<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\FactureAnnulee;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Symétrique de DecrementerStockApresEmissionFacture : annuler une facture
 * déjà émise rend au stock ce que la vente en avait retiré, jamais plus
 * (voir Facture::quantiteSortieDuStock()). On utilise le type
 * "retour_client" (entrée) plutôt qu'un simple ajustement générique : ça
 * reste lisible dans le ledger ("pourquoi ce retour ?" → la source est la
 * facture annulée).
 */
class RestaurerStockApresAnnulationFacture
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureAnnulee $event): void
    {
        $facture = $event->facture;

        foreach ($facture->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit !== null && $produit->suivi_stock) {
                $this->stock->retournerDeLaVente(
                    produit: $produit,
                    quantite: $ligne->quantite,
                    quantiteEncoreSortie: fn (): int => $facture->quantiteSortieDuStock($produit),
                    source: $facture,
                    note: "Annulation facture {$facture->numero}",
                );
            }
        }
    }
}
