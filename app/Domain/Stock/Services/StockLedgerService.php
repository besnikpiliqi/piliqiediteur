<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Point d'entrée UNIQUE pour toute modification de stock. Aucun autre
 * endroit du code ne doit écrire dans produits.stock_quantite ou dans
 * mouvements_stock directement -- ni Billing (ventes), ni Purchasing
 * (réceptions/retours fournisseur), qui déclenchent ce service via des
 * listeners d'événements plutôt que des appels directs, pour garder les
 * domaines découplés.
 */
class StockLedgerService
{
    /**
     * Enregistre un mouvement typé (vente, réception, retour...) et met à
     * jour le cache produits.stock_quantite de façon atomique.
     */
    public function enregistrer(
        Produit $produit,
        TypeMouvementStock $type,
        float $quantite,
        ?Model $source = null,
        ?string $note = null,
        ?int $creeParUserId = null,
    ): MouvementStock {
        if ($quantite <= 0) {
            throw new InvalidArgumentException("La quantité d'un mouvement de stock doit être strictement positive.");
        }

        if (! $produit->suivi_stock) {
            throw new LogicException("Le produit [{$produit->id}] ne suit pas son stock (suivi_stock = false).");
        }

        $delta = $type->estEntree() ? $quantite : -$quantite;

        return $this->appliquer($produit, $type, $quantite, $delta, $source, $note, $creeParUserId);
    }

    /**
     * Correction manuelle (inventaire physique, casse...) : on part de la
     * quantité cible plutôt que d'un delta, pour coller à l'usage ("j'ai
     * compté 42 articles") plutôt qu'à une mécanique interne.
     */
    public function ajuster(Produit $produit, float $quantiteCible, string $note, ?int $creeParUserId = null): MouvementStock
    {
        $delta = $quantiteCible - (float) $produit->stock_quantite;

        if (abs($delta) < 0.001) {
            throw new InvalidArgumentException('Aucun écart à ajuster.');
        }

        $noteComplete = sprintf('%s (%s%.3f)', $note, $delta >= 0 ? '+' : '', $delta);

        return $this->appliquer($produit, TypeMouvementStock::Ajustement, abs($delta), $delta, null, $noteComplete, $creeParUserId);
    }

    private function appliquer(
        Produit $produit,
        TypeMouvementStock $type,
        float $quantiteMouvement,
        float $delta,
        ?Model $source,
        ?string $note,
        ?int $creeParUserId,
    ): MouvementStock {
        return DB::transaction(function () use ($produit, $type, $quantiteMouvement, $delta, $source, $note, $creeParUserId) {
            // Verrou pessimiste : sérialise les écritures concurrentes sur
            // ce produit (ex: deux ventes simultanées de la dernière unité
            // en stock) pour empêcher toute race condition.
            $produitVerrouille = Produit::query()->whereKey($produit->getKey())->lockForUpdate()->firstOrFail();

            $nouvelleQuantite = round((float) $produitVerrouille->stock_quantite + $delta, 3);

            $mouvement = MouvementStock::create([
                'produit_id' => $produitVerrouille->id,
                'type' => $type,
                'quantite' => $quantiteMouvement,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'survenu_le' => now(),
                'cree_par' => $creeParUserId,
                'note' => $note,
            ]);

            $produitVerrouille->forceFill(['stock_quantite' => $nouvelleQuantite])->save();
            $produit->setAttribute('stock_quantite', $nouvelleQuantite);

            return $mouvement;
        });
    }
}
