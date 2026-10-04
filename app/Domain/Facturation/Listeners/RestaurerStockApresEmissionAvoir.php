<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;

/**
 * Un avoir total crédite l'intégralité d'une facture déjà émise : le
 * stock vendu par cette facture doit donc être restauré, exactement comme
 * pour une annulation (voir RestaurerStockApresAnnulationFacture). Les
 * deux restent des listeners distincts malgré le code quasi-identique :
 * ils réagissent à des events de domaines différents (Facture vs Avoir),
 * les coupler forcerait une dépendance artificielle entre les deux.
 */
class RestaurerStockApresEmissionAvoir
{
    public function __construct(private readonly StockLedgerService $stock) {}

    public function handle(AvoirEmis $event): void
    {
        foreach ($event->avoir->lignes as $ligne) {
            $produit = $ligne->produit;

            if ($produit !== null && $produit->suivi_stock) {
                // Chaque ligne peut provenir d'une facture différente (voir
                // le docblock de CreerAvoirAction) : la note se construit
                // ligne par ligne, pas au niveau de l'avoir.
                $numeroFacture = $ligne->ligneFacture?->facture?->numero;

                $this->stock->enregistrer(
                    produit: $produit,
                    type: TypeMouvementStock::RetourClient,
                    quantite: (float) $ligne->quantite,
                    source: $event->avoir,
                    note: "Avoir {$event->avoir->numero} sur facture {$numeroFacture}",
                );
            }
        }
    }
}
