<?php

namespace App\Domain\Achats\Listeners;

use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Support\ValeurRequise;

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
        $avoirFournisseur = $event->avoirFournisseur->loadMissing(['factureAchat', 'lignes.produit', 'lignes.ligneAchat.factureAchat']);

        $factureAchat = ValeurRequise::de($avoirFournisseur->factureAchat, 'la facture d\'achat de l\'avoir fournisseur');

        foreach ($avoirFournisseur->lignes as $ligne) {
            $produit = ValeurRequise::de($ligne->produit, 'le produit de la ligne de l\'avoir fournisseur');

            if ($produit->suivi_stock) {
                $this->stock->retournerAuFournisseur(
                    produit: $produit,
                    quantite: $ligne->quantite,
                    coutUnitaireHt: ValeurRequise::de($ligne->ligneAchat, 'la ligne d\'achat que retourne la ligne de l\'avoir fournisseur')->coutUnitaireHt(),
                    source: $avoirFournisseur,
                    note: "Avoir fournisseur {$avoirFournisseur->numero} sur facture d'achat {$factureAchat->numero}",
                );
            }
        }
    }
}
