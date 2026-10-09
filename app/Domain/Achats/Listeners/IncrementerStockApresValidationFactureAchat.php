<?php

namespace App\Domain\Achats\Listeners;

use App\Domain\Achats\Events\FactureAchatValidee;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Découple Achats de Stock, même pattern que
 * DecrementerStockApresEmissionFacture côté vente. Écouteur SYNCHRONE :
 * doit s'exécuter dans la même transaction que la validation pour rester
 * atomique avec elle (voir le commentaire sur FactureAchatValidee).
 *
 * Chaque ligne entre en stock à son coût réellement payé, remise et
 * escompte déduits (voir LigneAchat::coutUnitaireHt()), ce qui recalcule
 * le CMUP du produit (voir StockLedgerService::receptionner()) ; le
 * produit est rattaché au fournisseur de la facture. Chaque ligne a un
 * produit au catalogue : la validation refuse un produit archivé (voir
 * ValiderFactureAchatAction).
 */
class IncrementerStockApresValidationFactureAchat
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(FactureAchatValidee $event): void
    {
        $factureAchat = $event->factureAchat;

        foreach ($factureAchat->lignes as $ligne) {
            $this->stock->receptionner($ligne->produit, $ligne->quantite, $ligne->coutUnitaireHt(), $factureAchat, "Réception facture d'achat {$factureAchat->numero}");
            $ligne->produit->rattacherAuFournisseur($factureAchat->fournisseur_id);
        }
    }
}
