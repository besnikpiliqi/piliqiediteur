<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Découple Billing d'Inventory : la facturation ne connaît pas
 * StockLedgerService directement, elle se contente d'émettre FactureEmise.
 * Écouteur SYNCHRONE (pas de ShouldQueue) : doit s'exécuter dans la même
 * transaction que l'émission pour rester atomique avec elle (voir le
 * commentaire sur FactureEmise).
 */
class DecrementerStockApresEmissionFacture
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureEmise $event): void
    {
        foreach ($event->facture->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit !== null && $produit->suivi_stock) {
                $this->stock->enregistrer(
                    produit: $produit,
                    type: TypeMouvementStock::Vente,
                    quantite: $ligne->quantite,
                    source: $event->facture,
                    note: "Vente facture {$event->facture->numero}",
                );
            }
        }
    }
}
