<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ResolveurPrixLigne;
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
 * toutefois déjà impacté le stock et le prix d'achat courant du produit
 * (voir IncrementerStockApresValidationFactureAchat) : toute modification
 * de ses lignes recalcule donc l'écart de stock produit par produit plutôt
 * que de réappliquer la réception en aveugle.
 */
class ModifierFactureAchatAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateurLigne,
        private readonly ResolveurPrixLigne $resolveurPrix,
        private readonly StockLedgerService $stockLedger,
    ) {}

    /**
     * @param  list<array{produit_id: int|string, quantite: float|string, prix_unitaire_ht?: float|string|null, prix_unitaire_ttc?: float|string|null, prix_reference?: string|null, taux_tva?: float|string, remise_pourcentage?: float|string, description?: string, reference?: string}>  $lignes
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

            $lignesConstruites = [];
            $lignesCreees = [];

            foreach (array_values($lignes) as $index => $donnees) {
                if (! isset($donnees['produit_id'])) {
                    throw new InvalidArgumentException('Chaque ligne d\'une facture d\'achat doit référencer un produit du catalogue.');
                }

                $produit = Produit::query()->findOrFail($donnees['produit_id']);
                $quantite = (float) ($donnees['quantite'] ?? throw new InvalidArgumentException('Chaque ligne doit fournir une quantite.'));
                $tauxTva = (float) ($donnees['taux_tva'] ?? $produit->taux_tva);
                $remise = (float) ($donnees['remise_pourcentage'] ?? 0);
                // Un fournisseur facture le plus souvent en HT (prix d'achat
                // catalogue), mais un ticket de magasin se saisit en TTC :
                // le prix saisi fait foi, voir PrixReference.
                $prix = $this->resolveurPrix->resoudre($donnees, PrixReference::Ht, $tauxTva, (float) $produit->prix_achat_ht);

                $montants = $this->calculateurLigne->calculer(
                    $prix['prix_unitaire_ht'],
                    $prix['prix_unitaire_ttc'],
                    $quantite,
                    $remise,
                );

                $ligneCreee = $factureAchat->lignes()->create([
                    'produit_id' => $produit->id,
                    'description' => $donnees['description'] ?? $produit->nom,
                    'reference' => $donnees['reference'] ?? $produit->reference,
                    'quantite' => $quantite,
                    ...$prix,
                    'taux_tva' => $tauxTva,
                    'remise_pourcentage' => $remise,
                    'ordre' => $index,
                    ...$montants,
                ]);

                $lignesCreees[] = $ligneCreee;
                $lignesConstruites[] = $montants;
            }

            $factureAchat->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            if ($estValidee) {
                $this->recalerStockEtPrixAchat($factureAchat, $quantitesAvant, collect($lignesCreees));
            }

            return $factureAchat->fresh('lignes');
        });
    }

    /**
     * @param  Collection<int, LigneAchat>  $lignes
     * @return Collection<int, float> quantité totale par produit_id
     */
    private function quantitesParProduit(Collection $lignes): Collection
    {
        return $lignes
            ->filter(fn (LigneAchat $ligne) => $ligne->produit_id !== null)
            ->groupBy('produit_id')
            ->map(fn (Collection $lignesDuProduit) => (float) $lignesDuProduit->sum('quantite'));
    }

    /**
     * Ne rejoue PAS un nouveau mouvement de réception (qui fausserait
     * l'historique) : seul l'écart entre l'ancienne et la nouvelle
     * quantité par produit est appliqué, via un ajustement explicite (voir
     * StockLedgerService::ajuster()), le même outil utilisé pour une
     * correction d'inventaire.
     *
     * @param  Collection<int, float>  $quantitesAvant
     * @param  Collection<int, LigneAchat>  $lignesCreees
     */
    private function recalerStockEtPrixAchat(FactureAchat $factureAchat, Collection $quantitesAvant, Collection $lignesCreees): void
    {
        $quantitesApres = $this->quantitesParProduit($lignesCreees);

        foreach ($quantitesApres->keys()->merge($quantitesAvant->keys())->unique() as $produitId) {
            $produit = Produit::find($produitId);

            if ($produit === null || ! $produit->suivi_stock) {
                continue;
            }

            $delta = ($quantitesApres->get($produitId) ?? 0.0) - ($quantitesAvant->get($produitId) ?? 0.0);

            if (abs($delta) < 0.001) {
                continue;
            }

            $cible = round((float) $produit->stock_quantite + $delta, 3);

            $this->stockLedger->ajuster($produit, $cible, "Correction de la facture d'achat {$factureAchat->numero}");
        }

        foreach ($lignesCreees as $ligne) {
            if ($ligne->produit !== null) {
                $ligne->produit->forceFill(['prix_achat_ht' => $ligne->prix_unitaire_ht])->save();
            }
        }
    }
}
