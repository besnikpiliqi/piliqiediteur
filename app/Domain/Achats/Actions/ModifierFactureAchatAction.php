<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Services\StockLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Même raisonnement que ModifierFactureAction côté vente pour un
 * brouillon : il n'a aucune existence légale ni impact sur le stock (voir
 * ValiderFactureAchatAction), il reste donc librement modifiable, lignes
 * comprises, pour éviter de tout ressaisir en cas d'erreur de saisie.
 *
 * Contrairement à la vente, une facture d'achat saisie manuellement reste
 * modifiable même après validation (voir FactureAchat::estModifiable()) :
 * la personne qui l'enregistre peut se tromper, contrairement à une
 * facture récupérée automatiquement par API. Une facture déjà validée a
 * toutefois déjà impacté le stock, le prix d'achat et le fournisseur du
 * produit (voir IncrementerStockApresValidationFactureAchat) : toute modification
 * de ses lignes recalcule donc l'écart de stock produit par produit plutôt
 * que de réappliquer la réception en aveugle.
 */
class ModifierFactureAchatAction
{
    public function __construct(
        private readonly ConstructeurLigneAchat $constructeurLignes,
        private readonly StockLedgerService $stockLedger,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     */
    public function handle(FactureAchat $factureAchat, array $lignes, array $attributs = []): FactureAchat
    {
        if (! $factureAchat->estModifiable()) {
            throw new LogicException('Cette facture d\'achat n\'est plus modifiable.');
        }

        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        return DB::transaction(function () use ($factureAchat, $lignes, $attributs) {
            $estValidee = $factureAchat->statut === StatutFactureAchat::Validee;
            $quantitesAvant = $estValidee ? $this->quantitesParProduit($factureAchat->lignes) : collect();

            $factureAchat->update($attributs);
            $factureAchat->lignes()->delete();

            $lignesCreees = $this->constructeurLignes->enregistrer($factureAchat, $lignes);

            if ($estValidee) {
                $this->recalerLeStockEtLesProduits($factureAchat, $quantitesAvant, collect($lignesCreees));
            }

            return $factureAchat->fresh('lignes');
        });
    }

    /**
     * @param  Collection<int, LigneAchat>  $lignes
     * @return Collection<int, int> quantité totale par produit_id
     */
    private function quantitesParProduit(Collection $lignes): Collection
    {
        return $lignes
            ->filter(fn (LigneAchat $ligne) => $ligne->produit_id !== null)
            ->groupBy('produit_id')
            ->map(fn (Collection $lignesDuProduit): int => (int) $lignesDuProduit->sum('quantite'));
    }

    /**
     * Ne rejoue PAS un nouveau mouvement de réception (qui fausserait
     * l'historique) : seul l'écart entre l'ancienne et la nouvelle
     * quantité par produit est appliqué, via un ajustement explicite (voir
     * StockLedgerService::ajuster()), le même outil utilisé pour une
     * correction d'inventaire.
     *
     * @param  Collection<int, int>  $quantitesAvant
     * @param  Collection<int, LigneAchat>  $lignesCreees
     */
    private function recalerLeStockEtLesProduits(FactureAchat $factureAchat, Collection $quantitesAvant, Collection $lignesCreees): void
    {
        $quantitesApres = $this->quantitesParProduit($lignesCreees);

        foreach ($quantitesApres->keys()->merge($quantitesAvant->keys())->unique() as $produitId) {
            $produit = Produit::find($produitId);

            if ($produit === null || ! $produit->suivi_stock) {
                continue;
            }

            $delta = ($quantitesApres->get($produitId) ?? 0) - ($quantitesAvant->get($produitId) ?? 0);

            if ($delta === 0) {
                continue;
            }

            $cible = $produit->stock_quantite + $delta;

            $this->stockLedger->ajuster($produit, $cible, "Correction de la facture d'achat {$factureAchat->numero}");
        }

        foreach ($lignesCreees as $ligne) {
            $ligne->produit?->enregistrerUnAchat($ligne);
        }
    }
}
