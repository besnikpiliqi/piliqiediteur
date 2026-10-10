<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
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
 * toutefois déjà impacté le stock, le CMUP et le fournisseur du produit
 * (voir IncrementerStockApresValidationFactureAchat) : toute modification
 * de ses lignes corrige donc la réception produit par produit plutôt que
 * de la réappliquer en aveugle (voir corrigerLaReception()).
 *
 * Comme CreerFactureAchatAction, elle reçoit le fournisseur déjà résolu par
 * une requête limitée au magasin, jamais un fournisseur_id brut venu du
 * formulaire, et quand une ligne change le taux de TVA d'un produit, le
 * registre fiscal du magasin est le premier verrou de sa transaction,
 * avant la facture d'achat (voir
 * ConstructeurLigneAchat::verrouillerLeRegistreSiUnTauxChange()).
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
            $this->constructeurLignes->verrouillerLeRegistreSiUnTauxChange($lignes);

            // Verrouillée comme à sa validation (voir
            // ValiderFactureAchatAction) : une validation simultanée ne peut
            // ni être ignorée ni être appliquée deux fois.
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();

            if (! $factureAchat->estModifiable()) {
                throw new LogicException('Cette facture d\'achat n\'est plus modifiable.');
            }

            $estValidee = $factureAchat->statut === StatutFactureAchat::Validee;

            if ($estValidee && ($produitArchive = $factureAchat->produitArchive()) !== null) {
                throw new LogicException("Le produit « {$produitArchive->nom} » a été archivé : restaurez-le depuis le catalogue avant de corriger cette facture d'achat.");
            }

            // Corriger la réception rattache ses produits au fournisseur de
            // leur dernier achat validé (voir corrigerLaReception()) :
            // archivé, comme à la validation, il ne le devient pas sans être
            // restauré. Un brouillon, sans réception, reste modifiable.
            if ($estValidee && $fournisseur->trashed()) {
                throw new LogicException("Le fournisseur « {$fournisseur->nom} » a été archivé : restaurez-le depuis la liste des fournisseurs avant de corriger cette facture d'achat.");
            }

            $produitsAvant = $factureAchat->lignes->pluck('produit_id')->map(fn (int|string $produitId): int => (int) $produitId)->unique()->values()->all();

            $factureAchat->update([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]);
            $factureAchat->lignes()->delete();

            $this->constructeurLignes->enregistrer($factureAchat, $lignes);

            if ($estValidee) {
                $this->corrigerLaReception($factureAchat->fresh('lignes'), $produitsAvant);
            }

            return $factureAchat->fresh('lignes');
        });
    }

    /**
     * Ne rejoue PAS un nouveau mouvement de réception (qui fausserait
     * l'historique) : chaque produit de la facture, avant ou après la
     * correction, voit sa réception corrigée dans le grand livre et son
     * coût recalculé (voir StockLedgerService::corrigerUneReception()),
     * une fois ses lignes enregistrées : c'est ce que la réception a
     * réellement fait entrer en stock, et non le suivi du stock coché ou
     * décoché sur sa fiche ou sa ligne, qui dit ce qui se corrige. Chacun
     * reprend aussi le fournisseur de son dernier achat validé -- pas
     * forcément celui de cette facture, si elle n'est pas la plus récente.
     *
     * @param  list<int>  $produitsAvant
     */
    private function corrigerLaReception(FactureAchat $factureAchat, array $produitsAvant): void
    {
        $quantitesApres = $factureAchat->lignes
            ->groupBy('produit_id')
            ->map(fn (Collection $lignesDuProduit): int => $lignesDuProduit->sum('quantite'));

        foreach (array_unique([...$produitsAvant, ...$quantitesApres->keys()->all()]) as $produitId) {
            $produit = Produit::query()->findOrFail($produitId);

            $this->stockLedger->corrigerUneReception(
                $produit,
                $factureAchat,
                in_array($produitId, $produitsAvant, true),
                $quantitesApres->get($produitId, 0),
                $this->lotsDesReceptions($produit),
                "Correction de la facture d'achat {$factureAchat->numero}",
            );

            if (($dernierAchat = $produit->dernierAchatValide()) !== null) {
                $produit->rattacherAuFournisseur($dernierAchat->factureAchat->fournisseur_id);
            }
        }
    }

    /**
     * Les lignes de chaque facture d'achat validée du produit, au coût que
     * chacune a réellement payé (voir LigneAchat::coutUnitaireHt()), de la
     * réception la plus ancienne à la plus récente.
     *
     * @return array<int, list<array{quantite: int, cout_unitaire: float}>>
     */
    private function lotsDesReceptions(Produit $produit): array
    {
        return $produit->achatsValides()
            ->with('factureAchat')
            ->get()
            ->groupBy('facture_achat_id')
            ->map(fn (Collection $lignes): array => $lignes
                ->map(fn (LigneAchat $ligne): array => ['quantite' => $ligne->quantite, 'cout_unitaire' => $ligne->coutUnitaireHt()])
                ->values()
                ->all())
            ->all();
    }
}
