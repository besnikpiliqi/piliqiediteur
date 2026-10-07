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
 * Met aussi à jour le coût d'achat courant du produit (prix_achat_ht) :
 * c'est le seul champ de coût suivi par cette application (pas de coût
 * moyen pondéré par lot), une réception de marchandise est le moment
 * naturel pour le rafraîchir au dernier coût réellement payé, remise et
 * escompte déduits (voir LigneAchat::coutUnitaireHt()).
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

            $produit->forceFill(['prix_achat_ht' => $ligne->coutUnitaireHt()])->save();

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
