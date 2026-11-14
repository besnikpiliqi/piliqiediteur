<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Un avoir, total ou partiel, rend au stock les articles qu'il reprend,
 * ligne par ligne : chacune remet en stock au plus ce que la vente de sa
 * facture en a fait sortir et qui n'y est pas encore revenu (voir
 * Facture::quantiteSortieDuStock()) -- un article vendu sans suivi du
 * stock n'en est jamais sorti, son retour n'y fait rien entrer. Chaque
 * retour a pour source la ligne de l'avoir, qui relie l'avoir à la facture
 * reprise. C'est le seul retour au stock d'une vente : une facture émise
 * ne se corrige que par un avoir. L'avoir de régularisation d'une facture,
 * après la correction du SIREN de la société, ne rend rien : les articles
 * restent vendus, à la facture rectificative (voir
 * RegularisationDUneFacture). Un retour sur cette rectificative, qui n'a
 * rien sorti du stock, rend ce que la vente de la facture rectifiée a
 * sorti et qui n'y est pas encore revenu.
 */
class RestaurerStockApresEmissionAvoir
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(AvoirEmis $event): void
    {
        if ($event->avoir->estUneRegularisation()) {
            return;
        }

        foreach ($event->avoir->loadMissing(['lignes.produit', 'lignes.ligneFacture.facture'])->lignes as $ligne) {
            $produit = $ligne->produit;
            $facture = $ligne->ligneFacture?->facture;

            if ($produit !== null && $produit->suivi_stock && $facture !== null) {
                $this->stock->retournerDeLaVente(
                    produit: $produit,
                    quantite: $ligne->quantite,
                    quantiteEncoreSortie: fn (): int => $facture->quantiteSortieDuStock($produit),
                    source: $ligne,
                    note: "Avoir {$event->avoir->numero} sur facture {$facture->numero}",
                );
            }
        }
    }
}
