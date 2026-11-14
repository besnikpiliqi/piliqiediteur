<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as RequeteSql;

/**
 * Le stock du tableau de bord, dans une société qui a des articles : la
 * valeur HT du stock au coût moyen à la fin de chaque mois, et le coût des
 * articles vendus chaque mois, que la marge brute déduit de leurs ventes.
 * Le grand livre de chaque produit se rejoue dans l'ordre, avec ses
 * réceptions, exactement comme la vérification de chaque nuit établit son
 * coût moyen (voir StockLedgerService::coutMoyenRejoue()) : après chaque
 * mouvement, son stock et son coût moyen ; une vente sort au coût moyen
 * du moment, un retour client rentre au même coût, un stock vide ou
 * négatif ne vaut rien (CLAUDE.md, « Achats et stock »). Chaque mouvement
 * appartient au mois de son magasin.
 *
 * Une lecture hors du magasin courant, limitée aux magasins que le
 * périmètre permet pour le stock (et pour les ventes, pour la marge ; voir
 * Perimetre::exiger()) : quatre requêtes (produits, grand livre, lignes
 * d'achat validées et leurs factures), quel que soit le nombre de magasins
 * ou de produits.
 *
 * @phpstan-type Historique array{valeurs: array<int, array<string, int>>, ventes: array<int, array<string, int>>, couts: array<int, array<string, int>>}
 */
final class StockDuTableauDeBord
{
    public function __construct(
        private readonly StockLedgerService $stock,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * La valeur du stock à la fin de chacun de ces mois, et, de chacun, le
     * chiffre d'affaires HT et le coût des articles suivis en stock vendus,
     * retours déduits, en centimes, par magasin puis par mois « AAAA-MM ».
     * Le chiffre
     * d'affaires d'une vente est le HT de sa ligne ($htDesLignes : la ligne
     * d'un produit dans un ticket ou une facture, sous « facture:id:produit »,
     * et une ligne d'avoir, sous « avoir_ligne:id »).
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  list<string>  $mois
     * @param  array<string, int>  $htDesLignes
     * @return Historique
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function historique(Perimetre $perimetre, Collection $magasins, array $mois, array $htDesLignes = []): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Stock);
        $zero = array_fill_keys($mois, 0);
        $historique = ['valeurs' => [], 'ventes' => [], 'couts' => []];

        foreach ($magasins as $magasin) {
            foreach (array_keys($historique) as $quoi) {
                $historique[$quoi][CleDuModele::de($magasin)] = $zero;
            }
        }

        if ($magasins->isEmpty() || $mois === []) {
            return $historique;
        }

        $ids = $magasins->modelKeys();
        $calendriers = $magasins->mapWithKeys(fn (Magasin $magasin): array => [CleDuModele::de($magasin) => $magasin->calendrier()]);
        $produits = Produit::withoutGlobalScope(MagasinScope::class)->withTrashed()->whereIn('magasin_id', $ids)->orderBy('id')->get(['id', 'magasin_id', 'stock_quantite', 'cmup_ht']);
        $grandsLivres = MouvementStock::withoutGlobalScope(MagasinScope::class)->whereIn('magasin_id', $ids)->orderBy('id')
            ->get(['id', 'magasin_id', 'produit_id', 'type', 'quantite', 'cout_unitaire_ht', 'source_type', 'source_id', 'survenu_le'])
            ->groupBy('produit_id');
        $receptions = $this->receptions($ids);
        $dernier = $mois[array_key_last($mois)];
        $typeDeVente = (new Facture)->getMorphClass();
        $typeDeRetour = (new LigneAvoir)->getMorphClass();

        foreach ($produits as $produit) {
            $grandLivre = $grandsLivres->get($produit->id, new Collection);

            if ($grandLivre->isEmpty()) {
                continue;
            }

            $etats = [];
            $calendrier = $calendriers[$produit->magasin_id];

            $this->stock->coutMoyenRejoue($produit, $grandLivre, $receptions[$produit->id] ?? [], function (MouvementStock $mouvement, int $stock, int $cmup) use (&$etats, &$historique, $calendrier, $dernier, $typeDeVente, $typeDeRetour, $htDesLignes, $produit): void {
                $leMois = Mois::duJour($calendrier->jourDe($mouvement->survenu_le));

                if ($leMois > $dernier) {
                    return;
                }

                $etats[$leMois] = ['stock' => $stock, 'cmup' => $cmup];

                if (! array_key_exists($leMois, $historique['couts'][$produit->magasin_id])) {
                    return;
                }

                $cout = $this->calculateur->valeurAuCoutMoyen($mouvement->quantite, $cmup, 0)['montant_ht'];

                if ($mouvement->type === TypeMouvementStock::Vente && $mouvement->source_type === $typeDeVente) {
                    $historique['couts'][$produit->magasin_id][$leMois] += $cout;
                    $historique['ventes'][$produit->magasin_id][$leMois] += $htDesLignes["facture:{$mouvement->source_id}:{$produit->id}"] ?? 0;
                }

                if ($mouvement->type === TypeMouvementStock::RetourClient && $mouvement->source_type === $typeDeRetour) {
                    $historique['couts'][$produit->magasin_id][$leMois] -= $cout;
                    $historique['ventes'][$produit->magasin_id][$leMois] -= $htDesLignes["avoir_ligne:{$mouvement->source_id}"] ?? 0;
                }
            });

            $courant = null;

            foreach ($mois as $unMois) {
                foreach ($etats as $moisDeLEtat => $etat) {
                    if ($moisDeLEtat <= $unMois) {
                        $courant = $etat;
                    }
                }

                if ($courant !== null) {
                    $historique['valeurs'][$produit->magasin_id][$unMois] += $this->calculateur->valeurAuCoutMoyen(max($courant['stock'], 0), $courant['cmup'], 0)['montant_ht'];
                }
            }
        }

        return $historique;
    }

    /**
     * Les réceptions validées de chaque produit de ces magasins, dans
     * l'ordre des validations (voir StockLedgerService::lotsDe()), par
     * identifiant de produit.
     *
     * @param  list<int>  $magasins
     * @return array<int, array<int|string, array{repere: int, lots: non-empty-list<array{quantite: int, cout_unitaire: int}>}>>
     */
    private function receptions(array $magasins): array
    {
        $validees = fn (RequeteSql $factures) => $factures->from('factures_achat')
            ->whereIn('magasin_id', $magasins)
            ->where('statut', StatutFactureAchat::Validee->value)
            ->select('id');
        $lignes = LigneAchat::withoutGlobalScope(MagasinScope::class)
            ->whereIn('lignes_achat.magasin_id', $magasins)
            ->whereIn('lignes_achat.facture_achat_id', $validees)
            ->with(['factureAchat' => fn (BelongsTo $facture) => $facture->withoutGlobalScope(MagasinScope::class)->whereIn('factures_achat.magasin_id', $magasins)])
            ->orderBy(fn (RequeteSql $rang) => $rang->from('factures_achat')->select('rang_validation')->whereColumn('factures_achat.id', 'lignes_achat.facture_achat_id'))
            ->orderBy('lignes_achat.ordre')
            ->orderBy('lignes_achat.id')
            ->get()
            ->groupBy('produit_id');
        $parProduit = [];

        foreach ($lignes as $produit => $sesLignes) {
            $parProduit[(int) $produit] = $this->stock->lotsDe($sesLignes);
        }

        return $parProduit;
    }
}
