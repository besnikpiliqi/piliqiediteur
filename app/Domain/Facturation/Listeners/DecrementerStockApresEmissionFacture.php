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
 * commentaire sur FactureEmise), sur les produits que l'émission a déjà
 * verrouillés et dont elle a contrôlé le stock (voir VerificateurDeStock).
 *
 * Le mouvement d'une ligne qui fait passer son produit sous zéro, une
 * vente sans stock confirmée après l'avertissement (R1), le dit dans sa
 * note.
 */
class DecrementerStockApresEmissionFacture
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureEmise $event): void
    {
        foreach ($event->facture->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit !== null && $produit->suivi_stock) {
                $sansStock = $produit->stock_quantite - $ligne->quantite < 0;

                $this->stock->enregistrer(
                    produit: $produit,
                    type: TypeMouvementStock::Vente,
                    quantite: $ligne->quantite,
                    source: $event->facture,
                    note: "Vente facture {$event->facture->numero}".($sansStock ? ' (vente sans stock confirmée : stock négatif à régulariser)' : ''),
                );
            }
        }
    }
}
