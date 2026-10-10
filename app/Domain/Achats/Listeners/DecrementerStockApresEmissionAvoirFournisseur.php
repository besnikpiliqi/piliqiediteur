<?php

namespace App\Domain\Achats\Listeners;

use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Symétrique de IncrementerStockApresValidationFactureAchat : un retour au
 * fournisseur fait sortir la marchandise du stock. Écouteur SYNCHRONE,
 * même raisonnement que les autres écouteurs de stock de ce domaine.
 */
class DecrementerStockApresEmissionAvoirFournisseur
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(AvoirFournisseurEmis $event): void
    {
        foreach ($event->avoirFournisseur->lignes as $ligne) {
            if ($ligne->produit->suivi_stock) {
                $this->stock->enregistrer(
                    produit: $ligne->produit,
                    type: TypeMouvementStock::RetourFournisseur,
                    quantite: $ligne->quantite,
                    source: $event->avoirFournisseur,
                    note: "Avoir fournisseur {$event->avoirFournisseur->numero} sur facture d'achat {$event->avoirFournisseur->factureAchat->numero}",
                );
            }
        }
    }
}
