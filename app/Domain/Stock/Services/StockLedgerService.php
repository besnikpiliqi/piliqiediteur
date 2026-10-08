<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
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
 *
 * Le stock est valorisé au coût moyen unitaire pondéré (CMUP, voir
 * Produit::$cmup_ht), recalculé après chaque entrée achetée (voir
 * receptionner()) : toute sortie (vente, retour fournisseur) et toute
 * autre entrée (retour client, ajustement) se fait au CMUP du moment, qui
 * ne change donc pas.
 */
class StockLedgerService
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Entrée d'une réception d'achat, au coût réellement payé (voir
     * LigneAchat::coutUnitaireHt()) : le CMUP devient la moyenne du stock
     * en place et de cette entrée, pondérée par leurs quantités. Un stock
     * vide ou négatif ne pèse rien, le CMUP repart alors du coût de
     * l'entrée -- comme pour un produit qui ne suit pas son stock, dont le
     * coût est celui du dernier achat.
     */
    public function receptionner(Produit $produit, int $quantite, float $coutUnitaireHt, Model $source, string $note): ?MouvementStock
    {
        return DB::transaction(function () use ($produit, $quantite, $coutUnitaireHt, $source, $note) {
            $produitVerrouille = $this->verrouiller($produit);

            $this->changerLeCmup($produit, $produitVerrouille, $produitVerrouille->suivi_stock
                ? $this->calculateur->coutMoyenPondere([
                    ['quantite' => max($produitVerrouille->stock_quantite, 0), 'cout_unitaire' => $produitVerrouille->cmup_ht],
                    ['quantite' => $quantite, 'cout_unitaire' => $coutUnitaireHt],
                ]) ?? $coutUnitaireHt
                : $coutUnitaireHt);

            if (! $produitVerrouille->suivi_stock) {
                return null;
            }

            return $this->enregistrer($produit, TypeMouvementStock::ReceptionAchat, $quantite, $source, $note);
        });
    }

    /**
     * Correction d'une réception déjà entrée en stock (facture d'achat
     * validée, puis modifiée) : ses anciens lots sont retirés de la valeur
     * du stock et les nouveaux ajoutés, plutôt que de réappliquer la
     * réception en aveugle ; seul l'écart de quantité passe par un
     * ajustement. Si le stock restant ne pèse plus rien, le CMUP repart du
     * coût des nouveaux lots.
     *
     * @param  list<array{quantite: int, cout_unitaire: float}>  $lotsAvant
     * @param  list<array{quantite: int, cout_unitaire: float}>  $lotsApres
     */
    public function corrigerUneReception(Produit $produit, array $lotsAvant, array $lotsApres, string $note): void
    {
        DB::transaction(function () use ($produit, $lotsAvant, $lotsApres, $note) {
            $produitVerrouille = $this->verrouiller($produit);
            $coutDesNouveauxLots = $this->calculateur->coutMoyenPondere($lotsApres);

            $cmup = $produitVerrouille->suivi_stock
                ? $this->calculateur->coutMoyenPondere([
                    ['quantite' => max($produitVerrouille->stock_quantite, 0), 'cout_unitaire' => $produitVerrouille->cmup_ht],
                    ...array_map(fn (array $lot): array => ['quantite' => -$lot['quantite'], 'cout_unitaire' => $lot['cout_unitaire']], $lotsAvant),
                    ...$lotsApres,
                ]) ?? $coutDesNouveauxLots
                : $coutDesNouveauxLots;

            if ($cmup !== null) {
                $this->changerLeCmup($produit, $produitVerrouille, $cmup);
            }

            $ecart = array_sum(array_column($lotsApres, 'quantite')) - array_sum(array_column($lotsAvant, 'quantite'));

            if ($produitVerrouille->suivi_stock && $ecart !== 0) {
                $this->ajuster($produitVerrouille, $produitVerrouille->stock_quantite + $ecart, $note);
                $produit->setAttribute('stock_quantite', $produitVerrouille->stock_quantite)->syncOriginalAttribute('stock_quantite');
            }
        });
    }

    /**
     * Enregistre un mouvement typé (vente, réception, retour...) et met à
     * jour le cache produits.stock_quantite de façon atomique.
     */
    public function enregistrer(
        Produit $produit,
        TypeMouvementStock $type,
        int $quantite,
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
    public function ajuster(Produit $produit, int $quantiteCible, string $note, ?int $creeParUserId = null): MouvementStock
    {
        $delta = $quantiteCible - $produit->stock_quantite;

        if ($delta === 0) {
            throw new InvalidArgumentException('Aucun écart à ajuster.');
        }

        $noteComplete = sprintf('%s (%s%s)', $note, $delta > 0 ? '+' : '', $delta);

        return $this->appliquer($produit, TypeMouvementStock::Ajustement, abs($delta), $delta, null, $noteComplete, $creeParUserId);
    }

    private function appliquer(
        Produit $produit,
        TypeMouvementStock $type,
        int $quantiteMouvement,
        int $delta,
        ?Model $source,
        ?string $note,
        ?int $creeParUserId,
    ): MouvementStock {
        return DB::transaction(function () use ($produit, $type, $quantiteMouvement, $delta, $source, $note, $creeParUserId) {
            $produitVerrouille = $this->verrouiller($produit);

            $nouvelleQuantite = $produitVerrouille->stock_quantite + $delta;

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
            $produit->setAttribute('stock_quantite', $nouvelleQuantite)->syncOriginalAttribute('stock_quantite');

            return $mouvement;
        });
    }

    /**
     * Verrou pessimiste : sérialise les écritures concurrentes sur ce
     * produit (ex: deux ventes simultanées de la dernière unité en stock)
     * pour empêcher toute race condition.
     */
    private function verrouiller(Produit $produit): Produit
    {
        return Produit::query()->whereKey($produit->getKey())->lockForUpdate()->firstOrFail();
    }

    private function changerLeCmup(Produit $produit, Produit $produitVerrouille, float $cmup): void
    {
        $produitVerrouille->forceFill(['cmup_ht' => $cmup])->save();
        $produit->setAttribute('cmup_ht', $produitVerrouille->cmup_ht)->syncOriginalAttribute('cmup_ht');
    }
}
