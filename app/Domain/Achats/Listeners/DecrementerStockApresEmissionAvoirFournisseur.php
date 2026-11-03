<?php

namespace App\Domain\Achats\Listeners;

use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Symétrique de IncrementerStockApresValidationFactureAchat : un retour au
 * fournisseur fait sortir la marchandise du stock, au coût de la ligne
 * d'achat retournée, ce qui recalcule le CMUP du produit (voir
 * StockLedgerService::retournerAuFournisseur()). Les montants de l'avoir,
 * eux, restent ceux de la ligne achetée (voir LigneAchat::montantsDuRetour()).
 * Écouteur SYNCHRONE, même raisonnement que les autres écouteurs de stock de
 * ce domaine.
 */
class DecrementerStockApresEmissionAvoirFournisseur
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(AvoirFournisseurEmis $event): void
    {
        $avoirFournisseur = $event->avoirFournisseur;

        foreach ($avoirFournisseur->lignes as $ligne) {
            if ($ligne->produit->suivi_stock) {
                $this->stock->retournerAuFournisseur(
                    produit: $ligne->produit,
                    quantite: $ligne->quantite,
                    coutUnitaireHt: $ligne->ligneAchat->coutUnitaireHt(),
                    source: $avoirFournisseur,
                    note: "Avoir fournisseur {$avoirFournisseur->numero} sur facture d'achat {$avoirFournisseur->factureAchat->numero}",
                );
            }
        }
    }
}
