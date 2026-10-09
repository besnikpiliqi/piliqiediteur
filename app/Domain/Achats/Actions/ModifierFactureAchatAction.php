<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Services\StockLedgerService;
use Illuminate\Database\Eloquent\Builder;
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
 * toutefois déjà impacté le stock, le CMUP et le fournisseur du produit
 * (voir IncrementerStockApresValidationFactureAchat) : toute modification
 * de ses lignes recalcule donc l'écart de stock produit par produit plutôt
 * que de réappliquer la réception en aveugle.
 *
 * Comme CreerFactureAchatAction, elle reçoit le fournisseur déjà résolu par
 * une requête limitée au magasin, jamais un fournisseur_id brut venu du
 * formulaire : il est recopié sur les produits reçus.
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
    public function handle(FactureAchat $factureAchat, Fournisseur $fournisseur, array $lignes, array $attributs = []): FactureAchat
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        return DB::transaction(function () use ($factureAchat, $fournisseur, $lignes, $attributs) {
            // Verrouillée comme à sa validation (voir
            // ValiderFactureAchatAction) : une validation simultanée ne peut
            // ni être ignorée ni être appliquée deux fois.
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();

            if (! $factureAchat->estModifiable()) {
                throw new LogicException('Cette facture d\'achat n\'est plus modifiable.');
            }

            $estValidee = $factureAchat->statut === StatutFactureAchat::Validee;
            // Avant toute modification : le coût des anciennes lignes dépend
            // aussi de l'ancien escompte de la facture.
            $lotsAvant = $estValidee ? $this->lotsParProduit($factureAchat->lignes) : [];

            $factureAchat->update([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]);
            $factureAchat->lignes()->delete();

            $this->constructeurLignes->enregistrer($factureAchat, $lignes);

            if ($estValidee) {
                $this->corrigerLaReception($factureAchat->fresh('lignes'), $lotsAvant);
            }

            return $factureAchat->fresh('lignes');
        });
    }

    /**
     * Sans stock à pondérer, le coût d'un produit est celui de son dernier
     * achat validé, cette facture corrigée comprise -- ou retirée.
     */
    private function retenirLeCoutDuDernierAchat(Produit $produit): void
    {
        $dernierAchat = $produit->lignesAchat()
            ->whereHas('factureAchat', fn (Builder $factureAchat) => $factureAchat->where('statut', StatutFactureAchat::Validee))
            ->orderByDesc('facture_achat_id')
            ->orderByDesc('id')
            ->first();

        if ($dernierAchat !== null) {
            $this->stockLedger->retenirLeCoutDuDernierAchat($produit, $dernierAchat->coutUnitaireHt());
        }
    }

    /**
     * Ce que chaque ligne a fait entrer en stock, par produit.
     *
     * @param  Collection<int, LigneAchat>  $lignes
     * @return array<int, list<array{quantite: int, cout_unitaire: float}>>
     */
    private function lotsParProduit(Collection $lignes): array
    {
        return $lignes
            ->filter(fn (LigneAchat $ligne): bool => $ligne->produit_id !== null)
            ->groupBy('produit_id')
            ->map(fn (Collection $lignesDuProduit): array => $lignesDuProduit
                ->map(fn (LigneAchat $ligne): array => ['quantite' => $ligne->quantite, 'cout_unitaire' => $ligne->coutUnitaireHt()])
                ->values()
                ->all())
            ->all();
    }

    /**
     * Ne rejoue PAS un nouveau mouvement de réception (qui fausserait
     * l'historique) : pour chaque produit, l'ancienne réception est
     * remplacée par la nouvelle dans la valeur du stock et seul l'écart de
     * quantité passe par un ajustement (voir
     * StockLedgerService::corrigerUneReception()).
     *
     * @param  array<int, list<array{quantite: int, cout_unitaire: float}>>  $lotsAvant
     */
    private function corrigerLaReception(FactureAchat $factureAchat, array $lotsAvant): void
    {
        $lotsApres = $this->lotsParProduit($factureAchat->lignes);

        foreach (array_unique([...array_keys($lotsAvant), ...array_keys($lotsApres)]) as $produitId) {
            $produit = Produit::find($produitId);

            if ($produit === null) {
                continue;
            }

            if ($produit->suivi_stock) {
                $this->stockLedger->corrigerUneReception($produit, $lotsAvant[$produitId] ?? [], $lotsApres[$produitId] ?? [], "Correction de la facture d'achat {$factureAchat->numero}");
            } else {
                $this->retenirLeCoutDuDernierAchat($produit);
            }

            if (isset($lotsApres[$produitId])) {
                $produit->rattacherAuFournisseur($factureAchat->fournisseur_id);
            }
        }
    }
}
