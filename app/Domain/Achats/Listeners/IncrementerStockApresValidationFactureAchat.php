<?php

namespace App\Domain\Achats\Listeners;

use App\Domain\Achats\Events\FactureAchatValidee;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Découple Achats de Stock, même pattern que
 * DecrementerStockApresEmissionFacture côté vente. Écouteur SYNCHRONE :
 * doit s'exécuter dans la même transaction que la validation pour rester
 * atomique avec elle (voir le commentaire sur FactureAchatValidee).
 *
 * Met aussi à jour le coût d'achat et le fournisseur du produit (voir
 * Produit::enregistrerUnAchat()) : une réception de marchandise est le
 * moment naturel pour les rafraîchir.
 */
class IncrementerStockApresValidationFactureAchat
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureAchatValidee $event): void
    {
        foreach ($event->factureAchat->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit === null) {
                continue;
            }

            $produit->enregistrerUnAchat($ligne);

            if ($produit->suivi_stock) {
                $this->stock->enregistrer(
                    produit: $produit,
                    type: TypeMouvementStock::ReceptionAchat,
                    quantite: $ligne->quantite,
                    source: $event->factureAchat,
                    note: "Réception facture d'achat {$event->factureAchat->numero}",
                );
            }
        }
    }
}
