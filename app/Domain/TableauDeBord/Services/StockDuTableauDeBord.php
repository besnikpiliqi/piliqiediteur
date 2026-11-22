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
use App\Domain\TableauDeBord\Support\HistoriqueDuStock;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as RequeteSql;
use Illuminate\Support\Facades\DB;

/**
 * Le stock du tableau de bord, dans une société qui a des articles : la
 * valeur HT du stock au coût moyen à la fin de chaque jour, et le coût des
 * articles vendus chaque jour, que la marge brute déduit de leurs ventes
 * (voir historique() et HistoriqueDuStock, que chaque barre d'un graphique
 * par période, du jour à l'année, interroge).
 * Le grand livre de chaque produit se rejoue dans l'ordre, avec ses
 * réceptions, exactement comme la vérification de chaque nuit établit son
 * coût moyen (voir StockLedgerService::coutMoyenRejoue()) : après chaque
 * mouvement, son stock et son coût moyen ; une vente sort au coût moyen
 * du moment, un retour client rentre au même coût, un stock vide ou
 * négatif ne vaut rien (CLAUDE.md, « Achats et stock »). Chaque mouvement
 * appartient au jour de son magasin.
 *
 * Une lecture hors du magasin courant, limitée aux magasins que le
 * périmètre permet pour le stock (et pour les ventes, pour la marge ; voir
 * Perimetre::exiger()) : quatre requêtes (produits, grand livre, lignes
 * d'achat validées et leurs factures), quel que soit le nombre de magasins
 * ou de produits, et une cinquième, les factures rectifiées, seulement
 * quand la ligne d'une régularisation ne trouve pas directement la sortie
 * de stock de la facture qu'elle régularise.
 *
 * @phpstan-import-type LigneDeVente from VentesDuTableauDeBord
 */
final class StockDuTableauDeBord
{
    public function __construct(
        private readonly StockLedgerService $stock,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * La valeur du stock à la fin de chaque jour où elle change, et, de
     * chaque jour, le chiffre d'affaires HT et le coût des articles suivis
     * en stock vendus, retours déduits, en centimes, par magasin puis par
     * jour « AAAA-MM-JJ » (voir HistoriqueDuStock).
     *
     * Le coût se lit sur le grand livre : une vente sort au coût moyen du
     * moment, un retour client rentre au même coût. Le chiffre d'affaires
     * est le HT de chacune de ces lignes de vente ($lignes, celles des
     * produits) dont la vente a fait sortir le produit du stock, dans le
     * jour de son document : la ligne d'un ticket ou d'une facture qui a
     * son mouvement de vente, celle d'un avoir qui a son retour au stock,
     * et la ligne d'une facture rectificative ou d'un avoir de
     * régularisation, qui ne bougent pas le stock, quand la vente qu'ils
     * régularisent l'a fait (voir VentesDuTableauDeBord::lignes()) : un
     * export régularisé faute de preuve de sortie déduit de la marge du
     * jour de sa régularisation le HT de son avoir et y ajoute celui de sa
     * rectificative, sans aucun coût de plus.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  list<LigneDeVente>  $lignes
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function historique(Perimetre $perimetre, Collection $magasins, array $lignes = []): HistoriqueDuStock
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Stock);

        if ($magasins->isEmpty()) {
            return new HistoriqueDuStock([], [], []);
        }

        $ids = CleDuModele::desModeles($magasins);
        $valeurs = array_fill_keys($ids, []);
        $ventes = array_fill_keys($ids, []);
        $couts = array_fill_keys($ids, []);
        $calendriers = [];

        foreach ($magasins as $magasin) {
            $calendriers[CleDuModele::de($magasin)] = $magasin->calendrier();
        }

        $produits = Produit::withoutGlobalScope(MagasinScope::class)->withTrashed()->whereIn('magasin_id', $ids)->orderBy('id')->get(['id', 'magasin_id', 'stock_quantite', 'cmup_ht']);
        $grandsLivres = MouvementStock::withoutGlobalScope(MagasinScope::class)->whereIn('magasin_id', $ids)->orderBy('id')
            ->get(['id', 'magasin_id', 'produit_id', 'type', 'quantite', 'cout_unitaire_ht', 'source_type', 'source_id', 'survenu_le'])
            ->groupBy('produit_id');
        $receptions = $this->receptions($ids);
        $typeDeVente = (new Facture)->getMorphClass();
        $typeDeRetour = (new LigneAvoir)->getMorphClass();
        $sorties = [];
        $retours = [];

        foreach ($produits as $produit) {
            $grandLivre = $grandsLivres->get($produit->id, new Collection);

            if ($grandLivre->isEmpty()) {
                continue;
            }

            $etats = [];
            $calendrier = $calendriers[$produit->magasin_id];

            $this->stock->coutMoyenRejoue($produit, $grandLivre, $receptions[$produit->id] ?? [], function (MouvementStock $mouvement, int $stock, int $cmup) use (&$etats, &$couts, &$sorties, &$retours, $calendrier, $typeDeVente, $typeDeRetour, $produit): void {
                $vente = $mouvement->type === TypeMouvementStock::Vente && $mouvement->source_type === $typeDeVente;
                $retour = $mouvement->type === TypeMouvementStock::RetourClient && $mouvement->source_type === $typeDeRetour;

                if ($vente) {
                    $sorties["{$mouvement->source_id}:{$produit->id}"] = true;
                }

                if ($retour) {
                    $retours[ValeurRequise::de($mouvement->source_id, 'la ligne d\'avoir qui justifie un retour du grand livre')] = true;
                }

                $leJour = $calendrier->jourDe(ValeurRequise::de($mouvement->survenu_le, 'l\'instant d\'un mouvement du grand livre'));
                $etats[$leJour] = ['stock' => $stock, 'cmup' => $cmup];

                if (! $vente && ! $retour) {
                    return;
                }

                $cout = $this->calculateur->valeurAuCoutMoyen($mouvement->quantite, $cmup, 0)['montant_ht'];
                $couts[$produit->magasin_id][$leJour] = ($couts[$produit->magasin_id][$leJour] ?? 0) + ($vente ? $cout : -$cout);
            });

            ksort($etats);
            $precedente = 0;

            foreach ($etats as $jour => $etat) {
                $valeur = $this->calculateur->valeurAuCoutMoyen(max($etat['stock'], 0), $etat['cmup'], 0)['montant_ht'];
                $valeurs[$produit->magasin_id][$jour] = ($valeurs[$produit->magasin_id][$jour] ?? 0) + $valeur - $precedente;
                $precedente = $valeur;
            }
        }

        $rectifiees = null;

        foreach ($lignes as $ligne) {
            if ($ligne['produit'] === null || ! isset($ventes[$ligne['magasin']])) {
                continue;
            }

            $sortie = match (true) {
                $ligne['sortie_par'] !== null => $this->venteSortieDuStock($ligne['sortie_par'], $ligne['produit'], $sorties, $rectifiees, $ids),
                $ligne['document'] === 'facture' => isset($sorties["{$ligne['document_id']}:{$ligne['produit']}"]),
                default => isset($retours[$ligne['ligne_id']]),
            };

            if ($sortie) {
                $ventes[$ligne['magasin']][$ligne['jour']] = ($ventes[$ligne['magasin']][$ligne['jour']] ?? 0) + ($ligne['document'] === 'facture' ? $ligne['ht'] : -$ligne['ht']);
            }
        }

        return new HistoriqueDuStock(self::cumulerLesValeurs($valeurs), $ventes, $couts);
    }

    /**
     * La valeur du stock de chaque magasin à la fin de chaque jour où elle
     * change : la somme des changements de ses produits jusqu'à ce jour.
     *
     * @param  array<int, array<string, int>>  $changements  par magasin, de combien la valeur change à la fin de chaque jour
     * @return array<int, array<string, int>>
     */
    private static function cumulerLesValeurs(array $changements): array
    {
        $valeurs = [];

        foreach ($changements as $magasin => $parJour) {
            ksort($parJour);
            $total = 0;
            $valeurs[$magasin] = [];

            foreach ($parJour as $jour => $changement) {
                $total += $changement;
                $valeurs[$magasin][$jour] = $total;
            }
        }

        return $valeurs;
    }

    /**
     * Cette vente a-t-elle fait sortir ce produit du stock ? Une facture
     * rectificative ne le fait pas : c'est la vente qu'elle rectifie qui
     * l'a fait, et ainsi de suite, les factures rectifiées de ces magasins
     * (identifiant de la rectificative => identifiant de la facture
     * rectifiée) n'étant lues qu'à la première question qui en a besoin.
     *
     * @param  array<string, true>  $sorties  « facture:produit » de chaque mouvement de vente
     * @param  array<int, int>|null  $rectifiees
     * @param  list<int>  $magasins
     */
    private function venteSortieDuStock(int $facture, int $produit, array $sorties, ?array &$rectifiees, array $magasins): bool
    {
        $vues = [];

        while (! isset($sorties["{$facture}:{$produit}"])) {
            $rectifiees ??= $this->facturesRectifiees($magasins);

            if (! isset($rectifiees[$facture]) || isset($vues[$facture])) {
                return false;
            }

            $vues[$facture] = true;
            $facture = $rectifiees[$facture];
        }

        return true;
    }

    /**
     * Les factures rectificatives de ces magasins, chacune avec la facture
     * qu'elle rectifie.
     *
     * @param  list<int>  $magasins
     * @return array<int, int>
     */
    private function facturesRectifiees(array $magasins): array
    {
        $rectifiees = [];

        foreach (DB::table('factures')->whereIn('magasin_id', $magasins)->whereNotNull('facture_rectifiee_id')->orderBy('id')->get(['id', 'facture_rectifiee_id']) as $facture) {
            $rectifiees[ValeurSimple::enEntier($facture->id)] = ValeurSimple::enEntier($facture->facture_rectifiee_id);
        }

        return $rectifiees;
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
