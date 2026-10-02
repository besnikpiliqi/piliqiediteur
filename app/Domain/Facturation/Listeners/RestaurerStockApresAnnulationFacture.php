<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\FactureAnnulee;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Symétrique de DecrementerStockApresEmissionFacture : annuler une facture
 * déjà émise doit rendre au stock ce que la vente en avait retiré. On
 * utilise le type "retour_client" (entrée) plutôt qu'un simple ajustement
 * générique : ça reste lisible dans le ledger ("pourquoi ce retour ?" →
 * la note référence la facture annulée).
 */
class RestaurerStockApresAnnulationFacture
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureAnnulee $event): void
    {
        foreach ($event->facture->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit !== null && $produit->suivi_stock) {
                $this->stock->enregistrer(
                    produit: $produit,
                    type: TypeMouvementStock::RetourClient,
                    quantite: (float) $ligne->quantite,
                    source: $event->facture,
                    note: "Annulation facture {$event->facture->numero}",
                );
            }
        }
    }
}
