<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\CalculateurLigne;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/**
 * Calcul des montants (voir le commentaire de classe de CalculateurLigne),
 * en centimes entiers : toute vente, à un particulier comme à un
 * professionnel, part de ses prix TTC -- le HT de chaque taux est le TTC
 * de ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche,
 * un demi-centime au centime supérieur, et réparti sur ses lignes par
 * cumul ; la TVA est la différence --, et un retour rend le calcul de la
 * vente sur les articles rendus, dans la limite de la ligne ; un achat
 * part du PU HT du fournisseur, la TVA de chaque taux se calculant sur le
 * total HT du taux, arrondie une fois et répartie par cumul.
 *
 * Au-delà des exemples nommés (cas obligatoire de l'éditeur, cas réels
 * signalés par un magasin), les tests "sur_tous_les" et "aleatoires"
 * comparent le calculateur, sur des dizaines de milliers de cas, à une
 * référence indépendante : l'arithmétique décimale exacte de brick/math,
 * arrondie au centime au demi supérieur. Le hasard est tiré d'une graine
 * fixe (GRAINE) : un échec se rejoue toujours à l'identique, et son
 * message donne le cas fautif.
 */
class CalculateurLigneTest extends TestCase
{
    private const GRAINE = 20261006;

    /**
     * Taux de TVA français : normal, intermédiaire, réduits, et ceux des
     * DOM et de la Corse.
     */
    private const TAUX = ['0', '2.1', '5.5', '8.5', '10', '13', '20'];

    private function calculateur(): CalculateurLigne
    {
        return new CalculateurLigne;
    }

    /**
     * Une ligne de vente : PU TTC en centimes, taux et remise en centièmes
     * de point ; elle facture le taux de son prix, ou aucun quand la vente
     * retire la TVA du prix.
     *
     * @return array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int}
     */
    private static function ligneDeVente(int $prixTtc, int $quantite = 1, int $taux = 2000, int $remise = 0, bool $sansTva = false): array
    {
        return ['prix_unitaire_ttc' => $prixTtc, 'quantite' => $quantite, 'remise_pourcentage' => $remise, 'taux_tva_du_prix' => $taux, 'taux_tva' => $sansTva ? 0 : $taux];
    }

    /**
     * Une ligne d'achat : PU HT en centimes, taux et remise en centièmes de
     * point.
     *
     * @return array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int}
     */
    private static function ligneDAchat(int $prixHt, int $quantite = 1, int $taux = 2000, int $remise = 0): array
    {
        return ['prix_unitaire_ht' => $prixHt, 'quantite' => $quantite, 'remise_pourcentage' => $remise, 'taux_tva' => $taux];
    }

    /**
     * @param  array{montant_ht: int, montant_tva: int, montant_ttc: int}  $montants
     * @return array{int, int, int}
     */
    private static function triplet(array $montants): array
    {
        return [$montants['montant_ht'], $montants['montant_tva'], $montants['montant_ttc']];
    }

    /**
     * @param  array<array-key, array{montant_ht: int, montant_tva: int, montant_ttc: int}>  $montants
     * @return array<array-key, array{int, int, int}>
     */
    private static function triplets(array $montants): array
    {
        return array_map(self::triplet(...), $montants);
    }

    /**
     * Les retours successifs d'une ligne vendue, pour ces quantités rendues
     * cumulées, chacun déduction faite des précédents.
     *
     * @param  array<string, int>  $ligne
     * @param  list<int>  $quantitesRendues
     * @return list<array{int, int, int}>
     */
    private function retoursDeVente(array $ligne, array $quantitesRendues): array
    {
        return $this->retours(fn (int $quantite, array $dejaRendu): array => $this->calculateur()->retourDeLigne($ligne, $quantite, $dejaRendu), $quantitesRendues);
    }

    /**
     * @param  array<string, int>  $ligne
     * @param  list<int>  $quantitesRetournees
     * @return list<array{int, int, int}>
     */
    private function retoursDAchat(array $ligne, array $quantitesRetournees): array
    {
        return $this->retours(fn (int $quantite, array $dejaRendu): array => $this->calculateur()->retourDAchat($ligne, $quantite, $dejaRendu), $quantitesRetournees);
    }

    /**
     * @param  Closure(int, array{montant_ht: int, montant_tva: int}): array{montant_ht: int, montant_tva: int, montant_ttc: int}  $retour
     * @param  list<int>  $quantites
     * @return list<array{int, int, int}>
     */
    private function retours(Closure $retour, array $quantites): array
    {
        $dejaRendu = ['montant_ht' => 0, 'montant_tva' => 0];
        $retours = [];

        foreach ($quantites as $quantite) {
            $montants = $retour($quantite, $dejaRendu);
            $dejaRendu = ['montant_ht' => $dejaRendu['montant_ht'] + $montants['montant_ht'], 'montant_tva' => $dejaRendu['montant_tva'] + $montants['montant_tva']];
            $retours[] = self::triplet($montants);
        }

        return $retours;
    }

    public function test_la_valeur_d_un_stock_au_cout_moyen_s_arrondit_une_seule_fois(): void
    {
        $calculateur = new CalculateurLigne;

        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $calculateur->valeurAuCoutMoyen(2000, 0.045, 20));
        $this->assertSame(['montant_ht' => 21.45, 'montant_tva' => 4.29, 'montant_ttc' => 25.74], $calculateur->valeurAuCoutMoyen(1, 21.4545, 20));
    }

    /**
     * Le cas obligatoire de l'éditeur : 50,00 € TTC à 20 % font 41,67 € HT
     * (50 ÷ 1,2 = 41,666...) et 8,33 € de TVA.
     */
    public function test_cinquante_euros_ttc_a_vingt_pour_cent_font_41_67_ht_et_8_33_de_tva(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(5000)]);

        $this->assertSame([[4167, 833, 5000]], self::triplets($vente['lignes']));
        $this->assertSame([2000 => [4167, 833, 5000]], self::triplets($vente['taux']));
        $this->assertSame([4167, 833, 5000], self::triplet($vente['total']));
    }

    /**
     * Sur un même document, 50,00 € TTC à 20 % font 41,67 € HT et 8,33 € de
     * TVA en une ligne, en 2 x 25,00 € ou en deux lignes de 25,00 € : le HT
     * du taux s'arrondit une fois, puis se répartit, la première ligne
     * recevant 20,83 € (20,833...) et la seconde le reste du cumul,
     * 41,67 - 20,83 = 20,84 €.
     */
    public function test_le_meme_total_donne_les_memes_montants_quel_que_soit_le_decoupage(): void
    {
        $enUneLigne = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(5000)]);
        $enDeuxArticles = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(2500, 2)]);
        $enDeuxLignes = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(2500), self::ligneDeVente(2500)]);

        $this->assertSame([4167, 833, 5000], self::triplet($enUneLigne['total']));
        $this->assertSame([4167, 833, 5000], self::triplet($enDeuxArticles['total']));
        $this->assertSame([4167, 833, 5000], self::triplet($enDeuxLignes['total']));
        $this->assertSame([[2083, 417, 2500], [2084, 416, 2500]], self::triplets($enDeuxLignes['lignes']));
    }

    /**
     * 2 x 17,50 € TTC à 20 % : 35,00 ÷ 1,2 = 29,166... -> 29,17 € HT et
     * 5,83 € de TVA, jamais 2 x 14,58 € = 29,16 € et 5,84 €.
     */
    public function test_trente_cinq_euros_en_deux_articles_font_5_83_de_tva(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(1750, 2)]);

        $this->assertSame([2917, 583, 3500], self::triplet($vente['total']));
    }

    /**
     * Le client paie toujours la somme des prix affichés : 9,99 € (8,325
     * -> 8,33 € HT), 3 x 0,99 € = 2,97 € (2,475 -> 2,48 € HT), et trois
     * lignes de 0,10 €, 0,30 € (0,25 € HT : 0,083 -> 0,08, puis 0,167 ->
     * 0,17 moins 0,08, puis 0,25 moins 0,17).
     */
    public function test_le_ttc_paye_est_toujours_la_somme_des_prix(): void
    {
        $calculateur = $this->calculateur();
        $troisLignes = $calculateur->chiffrerUneVente([self::ligneDeVente(10), self::ligneDeVente(10), self::ligneDeVente(10)]);

        $this->assertSame([833, 166, 999], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(999)])['total']));
        $this->assertSame([248, 49, 297], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(99, 3)])['total']));
        $this->assertSame([[8, 2, 10], [9, 1, 10], [8, 2, 10]], self::triplets($troisLignes['lignes']));
        $this->assertSame([25, 5, 30], self::triplet($troisLignes['total']));
    }

    /**
     * Chaque taux se calcule sur le TTC de ses seules lignes : 1,00 € et
     * 2 x 2,00 € à 20 % (5,00 ÷ 1,2 = 4,166... -> 4,17 € HT, répartis 0,83
     * puis 3,34) et 2 x 4,50 € à 5,5 % (9,00 ÷ 1,055 = 8,530... -> 8,53 €)
     * font 12,70 € HT et 1,30 € de TVA pour 14,00 € ; 3 x 12,90 € à 10 %
     * (35,18 €), 2 x 3,99 € à 5,5 % (7,56 €) et 19,99 € à 20 % (16,66 €)
     * font 59,40 € HT et 7,27 € de TVA pour 66,67 €.
     */
    public function test_plusieurs_taux_se_calculent_chacun_sur_son_ttc(): void
    {
        $calculateur = $this->calculateur();

        $panier = $calculateur->chiffrerUneVente([self::ligneDeVente(100), self::ligneDeVente(450, 2, 550), self::ligneDeVente(200, 2)]);
        $courses = $calculateur->chiffrerUneVente([self::ligneDeVente(1290, 3, 1000), self::ligneDeVente(399, 2, 550), self::ligneDeVente(1999)]);

        $this->assertSame([[83, 17, 100], [853, 47, 900], [334, 66, 400]], self::triplets($panier['lignes']));
        $this->assertSame([2000 => [417, 83, 500], 550 => [853, 47, 900]], self::triplets($panier['taux']));
        $this->assertSame([1270, 130, 1400], self::triplet($panier['total']));
        $this->assertSame([[3518, 352, 3870], [756, 42, 798], [1666, 333, 1999]], self::triplets($courses['lignes']));
        $this->assertSame([5940, 727, 6667], self::triplet($courses['total']));
    }

    /**
     * La remise s'arrondit une fois, sur le TTC de la ligne : 50,00 €
     * remisés de 10 % font 45,00 € (37,50 € HT) ; 3 x 9,99 € = 29,97 €
     * remisés de 15 % (4,4955 -> 4,50 €) font 25,47 € TTC, et 25,47 ÷ 1,2
     * = 21,225 -> 21,23 € HT.
     */
    public function test_une_remise_s_arrondit_une_fois_sur_le_ttc_de_la_ligne(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([3750, 750, 4500], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(5000, 1, 2000, 1000)])['total']));
        $this->assertSame([2123, 424, 2547], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(999, 3, 2000, 1500)])['total']));
    }

    /**
     * « L'arrondi classique au centime » de l'éditeur : 2,97 € TTC à 20 %
     * font 2,475 € HT, arrondi au centime supérieur (2,48 € HT, 0,49 € de
     * TVA) ; 1,05 € font 0,875 € -> 0,88 € HT et 0,17 € de TVA.
     */
    public function test_un_demi_centime_exact_arrondit_le_ht_au_centime_superieur(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([248, 49, 297], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(297)])['total']));
        $this->assertSame([88, 17, 105], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(105)])['total']));
    }

    /**
     * Les lignes d'un taux se partagent son HT par cumul, dans leur ordre :
     * 0,10 €, 0,05 € puis 0,10 € à 20 % (cumuls 0,083 -> 0,08, 0,125 ->
     * 0,13 et 0,208 -> 0,21) reçoivent 0,08, 0,05 et 0,08 € HT ; 0,05 €
     * d'abord (cumuls 0,042 -> 0,04, 0,125 -> 0,13 puis 0,21), elles
     * reçoivent 0,04, 0,09 et 0,08 € : le centime d'arrondi va à la ligne où
     * le cumul le fait apparaître. Le taux, lui, fait toujours 0,21 € HT et
     * 0,04 € de TVA.
     */
    public function test_le_ht_d_un_taux_se_repartit_par_cumul_dans_l_ordre_des_lignes(): void
    {
        $calculateur = $this->calculateur();

        $dixCinqDix = $calculateur->chiffrerUneVente([self::ligneDeVente(10), self::ligneDeVente(5), self::ligneDeVente(10)]);
        $cinqDixDix = $calculateur->chiffrerUneVente([self::ligneDeVente(5), self::ligneDeVente(10), self::ligneDeVente(10)]);

        $this->assertSame([[8, 2, 10], [5, 0, 5], [8, 2, 10]], self::triplets($dixCinqDix['lignes']));
        $this->assertSame([[4, 1, 5], [9, 1, 10], [8, 2, 10]], self::triplets($cinqDixDix['lignes']));
        $this->assertSame([21, 4, 25], self::triplet($dixCinqDix['total']));
        $this->assertSame([21, 4, 25], self::triplet($cinqDixDix['total']));
    }

    /**
     * Hors de la TVA française, le client paie le HT de chaque taux, calculé
     * de même sur les prix TTC du catalogue : 2 x 40,00 € TTC à 20 % font
     * 66,67 € ; 1 000 articles à 1,00 € à 20 % et un livre à 10,55 € à
     * 5,5 %, 833,33 € et 10,00 €. En franchise en base, la ligne est à 0 %
     * et le client paie le prix TTC : 2 x 40,00 € font 80,00 €.
     */
    public function test_hors_tva_francaise_le_client_paie_le_ht_de_chaque_taux_et_en_franchise_le_prix_ttc(): void
    {
        $calculateur = $this->calculateur();

        $export = $calculateur->chiffrerUneVente([self::ligneDeVente(4000, 2, 2000, sansTva: true)]);
        $deuxTaux = $calculateur->chiffrerUneVente([self::ligneDeVente(100, 1000, 2000, sansTva: true), self::ligneDeVente(1055, 1, 550, sansTva: true)]);
        $franchise = $calculateur->chiffrerUneVente([self::ligneDeVente(4000, 2, 0)]);

        $this->assertSame([6667, 0, 6667], self::triplet($export['total']));
        $this->assertSame([[83333, 0, 83333], [1000, 0, 1000]], self::triplets($deuxTaux['lignes']));
        $this->assertSame([0 => [84333, 0, 84333]], self::triplets($deuxTaux['taux']));
        $this->assertSame([8000, 0, 8000], self::triplet($franchise['total']));
    }

    /**
     * Une ligne seule a pour HT quantité x PU TTC, remise déduite, ÷ (1 +
     * taux), arrondi une seule fois -- 1 000 x 1,00 € à 20 % = 833,333... ->
     * 833,33 €, jamais 1 000 x 0,83 € ; 49,90 € x 2 / 1,2 = 83,166... ;
     * 4,20 € x 3 / 1,055 = 11,943... ; 3,99 € x 7 = 27,93 € remisés de 10 %
     * (2,79 €), 25,14 / 1,055 = 23,829... ; 12,00 € x 10 x 0,85 / 1,2 =
     * 85,00 €.
     *
     * @return array<string, array{int, int, int, int, int}>
     */
    public static function lignesSeules(): array
    {
        return [
            '1 000 x 1,00 € à 20 %' => [100, 2000, 1000, 0, 83333],
            '2 x 49,90 € à 20 %' => [4990, 2000, 2, 0, 8317],
            '3 x 4,20 € à 5,5 %' => [420, 550, 3, 0, 1194],
            '7 x 3,99 € à 5,5 % remisés de 10 %' => [399, 550, 7, 1000, 2383],
            '10 x 12,00 € à 20 % remisés de 15 %' => [1200, 2000, 10, 1500, 8500],
        ];
    }

    #[DataProvider('lignesSeules')]
    public function test_le_ht_d_une_ligne_seule_s_arrondit_une_seule_fois(int $prixTtc, int $taux, int $quantite, int $remise, int $htAttendu): void
    {
        $this->assertSame($htAttendu, $this->calculateur()->chiffrerUneVente([self::ligneDeVente($prixTtc, $quantite, $taux, $remise)])['lignes'][0]['montant_ht']);
    }

    /**
     * Facture signalée par le magasin : 2 x 40 € TTC (20 %), 10 € sans TVA
     * et 10 € TTC (20 %). À 20 %, 90,00 ÷ 1,2 = 75,00 € HT, répartis 66,67 €
     * et 8,33 € ; sans TVA, 10,00 € : 85,00 € HT, 15,00 € de TVA, 100,00 €
     * TTC, et HT + TVA = TTC.
     */
    public function test_la_facture_du_magasin_totalise_85_ht_15_tva_100_ttc(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(4000, 2), self::ligneDeVente(1000, 1, 0), self::ligneDeVente(1000)]);

        $this->assertSame([[6667, 1333, 8000], [1000, 0, 1000], [833, 167, 1000]], self::triplets($vente['lignes']));
        $this->assertSame([8500, 1500, 10000], self::triplet($vente['total']));
    }

    /**
     * 5 x 9,99 € = 49,95 € remisés de 10 % (4,995 -> 5,00 €) font 44,95 €
     * TTC, et 44,95 ÷ 1,2 = 37,458... -> 37,46 € HT.
     */
    public function test_une_remise_sur_plusieurs_articles_reste_exacte_au_centime(): void
    {
        $this->assertSame([3746, 749, 4495], self::triplet($this->calculateur()->chiffrerUneVente([self::ligneDeVente(999, 5, 2000, 1000)])['total']));
    }

    public function test_une_remise_en_pourcentage_sapplique_a_la_vente_comme_a_lachat(): void
    {
        $this->assertSame([9000, 1800, 10800], self::triplet($this->calculateur()->chiffrerUneVente([self::ligneDeVente(12000, 1, 2000, 1000)])['total']));
        $this->assertSame([9000, 1800, 10800], self::triplet($this->calculateur()->chiffrerUnAchat([self::ligneDAchat(10000, 1, 2000, 1000)])['total']));
    }

    /**
     * Les retours successifs d'une ligne rendent, pour tous les articles
     * rendus jusqu'ici, le calcul de la vente sur cette quantité, moins ce
     * qu'ont rendu les précédents : 2 x 40,00 € (66,67 € HT + 13,33 €) se
     * rendent en 33,33 € + 6,67 €, puis 33,34 € + 6,66 €, 40,00 € chaque
     * fois ; 3 x 0,99 € (2,48 € + 0,49 €) en 0,83 € + 0,16 €, puis 0,82 € +
     * 0,17 € (1,98 € rendus : 1,65 € HT), puis 0,83 € + 0,16 € ; 3 x 9,99 €
     * remisés de 15 % (21,23 € + 4,24 €), un article (9,99 € moins 1,50 € de
     * remise, 8,49 € : 7,075 -> 7,08 € HT), puis les deux autres.
     */
    public function test_les_retours_partiels_rendent_le_calcul_de_la_vente_encadre_par_la_ligne(): void
    {
        $cables = [...self::ligneDeVente(4000, 2), 'montant_ht' => 6667, 'montant_tva' => 1333];
        $stylos = [...self::ligneDeVente(99, 3), 'montant_ht' => 248, 'montant_tva' => 49];
        $piles = [...self::ligneDeVente(999, 3, 2000, 1500), 'montant_ht' => 2123, 'montant_tva' => 424];

        $this->assertSame([[3333, 667, 4000], [3334, 666, 4000]], $this->retoursDeVente($cables, [1, 2]));
        $this->assertSame([[83, 16, 99], [82, 17, 99], [83, 16, 99]], $this->retoursDeVente($stylos, [1, 2, 3]));
        $this->assertSame([[708, 141, 849], [1415, 283, 1698]], $this->retoursDeVente($piles, [1, 3]));
    }

    /**
     * Une ligne de 1 000 x 1,00 € à 20 % (833,33 € HT + 166,67 €) : 500
     * articles rendus font 500,00 € TTC, 416,666... -> 416,67 € HT et
     * 83,33 € de TVA ; un article 0,83 € + 0,17 € ; deux 1,67 € + 0,33 € ; et
     * toute la ligne rendue fait exactement la ligne.
     */
    public function test_un_retour_rend_le_calcul_de_la_vente_sur_les_articles_rendus(): void
    {
        $ligne = [...self::ligneDeVente(100, 1000), 'montant_ht' => 83333, 'montant_tva' => 16667];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $this->assertSame([41667, 8333, 50000], self::triplet($this->calculateur()->retourDeLigne($ligne, 500, $rienRendu)));
        $this->assertSame([83, 17, 100], self::triplet($this->calculateur()->retourDeLigne($ligne, 1, $rienRendu)));
        $this->assertSame([167, 33, 200], self::triplet($this->calculateur()->retourDeLigne($ligne, 2, $rienRendu)));
        $this->assertSame([83333, 16667, 100000], self::triplet($this->calculateur()->retourDeLigne($ligne, 1000, $rienRendu)));
    }

    /**
     * Un article à 0,04 € puis 5 x 0,01 € à 20 % : le HT du taux, 0,09 ÷ 1,2
     * = 0,075 -> 0,08 €, se répartit 0,03 € et 0,05 €, si bien que la
     * seconde ligne n'a aucune TVA. Quatre de ses articles rendus (0,04 €)
     * vaudraient seuls 0,03 € HT et 0,01 € de TVA : le retour ne rend
     * jamais plus de TVA que la ligne, ici 0,04 € HT sans TVA, puis le
     * dernier article 0,01 €.
     */
    public function test_un_retour_ne_rend_jamais_plus_que_la_tva_de_la_ligne(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(4), self::ligneDeVente(1, 5)]);
        $ligne = [...self::ligneDeVente(1, 5), 'montant_ht' => 5, 'montant_tva' => 0];

        $this->assertSame([[3, 1, 4], [5, 0, 5]], self::triplets($vente['lignes']));
        $this->assertSame([[4, 0, 4], [1, 0, 1]], $this->retoursDeVente($ligne, [4, 5]));
    }

    /**
     * 10 articles à 0,02 € remisés de 25 % (0,20 € moins 0,05 €) font une
     * ligne de 0,15 € TTC : 0,13 € HT et 0,02 € de TVA. Rendus un par un,
     * un simple prorata rendrait -0,01 € de TVA au deuxième retour ; le
     * calcul de la vente, encadré par la ligne, ne rend jamais rien de
     * négatif, et les dix retours retombent exactement sur la ligne.
     */
    public function test_aucun_retour_ne_rend_un_montant_negatif(): void
    {
        $ligne = [...self::ligneDeVente(2, 10, 2000, 2500), 'montant_ht' => 13, 'montant_tva' => 2];

        $retours = $this->retoursDeVente($ligne, range(1, 10));

        $this->assertSame([[1, 0, 1], [2, 0, 2], [0, 1, 1], [2, 0, 2], [1, 0, 1], [2, 0, 2], [0, 1, 1], [2, 0, 2], [1, 0, 1], [2, 0, 2]], $retours);
        $this->assertSame([13, 2, 15], [array_sum(array_column($retours, 0)), array_sum(array_column($retours, 1)), array_sum(array_column($retours, 2))]);
    }

    /**
     * Une ligne vendue sans TVA française : 1 000 x 1,00 € TTC du catalogue
     * à 20 % font 833,33 € HT, à 0 %. Ses retours se calculent comme elle,
     * au taux que comprend le prix : 500 articles rendent 416,67 € HT, sans
     * TVA, et toute la ligne rend exactement la ligne.
     */
    public function test_les_retours_d_une_ligne_sans_tva_francaise_se_calculent_au_taux_du_prix(): void
    {
        $ligne = [...self::ligneDeVente(100, 1000, 2000, sansTva: true), 'montant_ht' => 83333, 'montant_tva' => 0];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $this->assertSame([41667, 0, 41667], self::triplet($this->calculateur()->retourDeLigne($ligne, 500, $rienRendu)));
        $this->assertSame([83333, 0, 83333], self::triplet($this->calculateur()->retourDeLigne($ligne, 1000, $rienRendu)));
    }

    public function test_un_retour_hors_de_la_quantite_de_la_ligne_est_refuse(): void
    {
        $vendue = [...self::ligneDeVente(4000, 2), 'montant_ht' => 6667, 'montant_tva' => 1333];
        $achetee = [...self::ligneDAchat(1001, 2), 'montant_ht' => 2002, 'montant_tva' => 400];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $this->assertThrows(fn () => $this->calculateur()->retourDeLigne($vendue, 0, $rienRendu), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertThrows(fn () => $this->calculateur()->retourDeLigne($vendue, 3, $rienRendu), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertThrows(fn () => $this->calculateur()->retourDAchat($achetee, 0, $rienRendu), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertThrows(fn () => $this->calculateur()->retourDAchat($achetee, 3, $rienRendu), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
    }

    /**
     * Trois lignes de 10,01 € HT à 20 % : la TVA du taux se calcule une
     * fois sur 30,03 € (6,006 -> 6,01 €), et se répartit par cumul : 2,00 €
     * (2,002), 2,00 € (4,004 -> 4,00, moins 2,00), puis 2,01 € (6,01 moins
     * 4,00) -- jamais 3 x 2,00 € = 6,00 €. Deux taux mêlés se répartissent
     * chacun à part : à 5,5 %, 2 x 10,00 € prennent 0,55 € chacune. Dans
     * l'ordre des lignes : 0,07 €, 0,03 € puis 0,07 € HT à 20 % (cumuls
     * 0,014 -> 0,01, 0,02 et 0,034 -> 0,03) prennent 0,01 € chacune ; 0,07 €,
     * 0,07 € puis 0,03 € (0,01, 0,028 -> 0,03, puis 0,03) prennent 0,01 €,
     * 0,02 € et rien.
     */
    public function test_la_tva_d_un_achat_se_calcule_sur_le_total_ht_de_chaque_taux(): void
    {
        $calculateur = $this->calculateur();

        $troisLignes = $calculateur->chiffrerUnAchat([self::ligneDAchat(1001), self::ligneDAchat(1001), self::ligneDAchat(1001)]);
        $deuxTaux = $calculateur->chiffrerUnAchat([self::ligneDAchat(1001), self::ligneDAchat(1000, 1, 550), self::ligneDAchat(1001), self::ligneDAchat(1000, 1, 550), self::ligneDAchat(1001)]);

        $this->assertSame([[1001, 200, 1201], [1001, 200, 1201], [1001, 201, 1202]], self::triplets($troisLignes['lignes']));
        $this->assertSame([3003, 601, 3604], self::triplet($troisLignes['total']));
        $this->assertSame([[1001, 200, 1201], [1000, 55, 1055], [1001, 200, 1201], [1000, 55, 1055], [1001, 201, 1202]], self::triplets($deuxTaux['lignes']));
        $this->assertSame([2000 => [3003, 601, 3604], 550 => [2000, 110, 2110]], self::triplets($deuxTaux['taux']));
        $this->assertSame([5003, 711, 5714], self::triplet($deuxTaux['total']));
        $this->assertSame([1, 1, 1], array_column($calculateur->chiffrerUnAchat([self::ligneDAchat(7), self::ligneDAchat(3), self::ligneDAchat(7)])['lignes'], 'montant_tva'));
        $this->assertSame([1, 2, 0], array_column($calculateur->chiffrerUnAchat([self::ligneDAchat(7), self::ligneDAchat(7), self::ligneDAchat(3)])['lignes'], 'montant_tva'));
    }

    /**
     * Facture d'achat signalée par le magasin : 10 x 1,18 € HT font 11,80 €
     * HT et 20 % de TVA, 2,36 € -- et non 10 x 1,42 € (1,18 € x 1,2 =
     * 1,416 € arrondi) = 14,20 € ; avec 5 x 33,65 € HT (168,25 €), la TVA du
     * taux, 20 % de 180,05 € = 36,01 €, laisse 33,65 € aux écrans. 3 x 4,99 €
     * HT font 14,97 € HT et 2,99 € de TVA (2,994).
     */
    public function test_un_achat_part_du_pu_ht_du_fournisseur(): void
    {
        $calculateur = $this->calculateur();

        $facture = $calculateur->chiffrerUnAchat([self::ligneDAchat(118, 10), self::ligneDAchat(3365, 5)]);

        $this->assertSame([[1180, 236, 1416], [16825, 3365, 20190]], self::triplets($facture['lignes']));
        $this->assertSame([18005, 3601, 21606], self::triplet($facture['total']));
        $this->assertSame([1497, 299, 1796], self::triplet($calculateur->chiffrerUnAchat([self::ligneDAchat(499, 3)])['total']));
    }

    /**
     * Une ligne de 3 x 10,01 € HT, troisième d'une facture de trois lignes
     * à 20 % (10,01 €, 10,01 €, puis elle : 6,01 € de TVA au taux, dont
     * 2,01 € pour elle), retournée article par article : 10,01 € + 2,00 €,
     * 10,01 € + 2,00 €, puis 10,01 € + 2,01 €, jamais rien de négatif, et la
     * somme des retours est la ligne. Une ligne de 4 x 0,01 € HT après une
     * ligne de 0,03 € n'a aucune TVA (0,6 -> 0,01 € pour la première, 1,4 ->
     * 0,01 € au cumul) : trois de ses articles retournés, dont la TVA
     * vaudrait 0,01 €, n'en rendent aucune.
     */
    public function test_un_retour_au_fournisseur_rend_sa_part_sans_depasser_la_ligne(): void
    {
        $facture = $this->calculateur()->chiffrerUnAchat([self::ligneDAchat(1001), self::ligneDAchat(1001), self::ligneDAchat(1001, 3)]);
        $ecrans = [...self::ligneDAchat(1001, 3), 'montant_ht' => 3003, 'montant_tva' => 601];
        $vis = [...self::ligneDAchat(1, 4), 'montant_ht' => 4, 'montant_tva' => 0];

        $this->assertSame([3003, 601, 3604], self::triplet($facture['lignes'][2]));
        $this->assertSame([[1001, 200, 1201], [1001, 200, 1201], [1001, 201, 1202]], $this->retoursDAchat($ecrans, [1, 2, 3]));
        $this->assertSame([[3, 0, 3], [1, 0, 1]], $this->retoursDAchat($vis, [3, 4]));
    }

    /**
     * 10 x 0,13 € HT achetés (1,30 € + 0,26 € de TVA), retournés un par un :
     * recalculer chaque retour seul rendrait 0,03 € de TVA neuf fois, et
     * -0,01 € au dernier. En cumulé, la TVA rendue suit 0,03, 0,05, 0,08... :
     * chaque retour vaut 0,02 € ou 0,03 € de TVA, et le dernier retombe
     * exactement sur la ligne.
     */
    public function test_des_retours_dachat_un_par_un_ne_creditent_jamais_de_tva_negative(): void
    {
        $ligne = [...self::ligneDAchat(13, 10), 'montant_ht' => 130, 'montant_tva' => 26];

        $retours = $this->retoursDAchat($ligne, range(1, 10));

        $this->assertSame([3, 2, 3, 2, 3, 3, 2, 3, 2, 3], array_column($retours, 1));
        $this->assertSame([130, 26, 156], [array_sum(array_column($retours, 0)), array_sum(array_column($retours, 1)), array_sum(array_column($retours, 2))]);
    }

    /**
     * Le calcul n'accepte que des entiers : un nombre à virgule flottante,
     * même rond, est refusé plutôt que tronqué en silence.
     */
    public function test_le_calcul_n_accepte_pas_de_flottant(): void
    {
        $calculateur = $this->calculateur();
        $message = 'Le calcul attend des montants en centimes et des taux et remises en centièmes de point, en nombres entiers, jamais un nombre à virgule.';
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $this->assertThrows(fn () => $calculateur->chiffrerUneVente([[...self::ligneDeVente(2500), 'prix_unitaire_ttc' => 25.0]]), InvalidArgumentException::class, $message);
        $this->assertThrows(fn () => $calculateur->chiffrerUnAchat([[...self::ligneDAchat(1001), 'taux_tva' => 20.0]]), InvalidArgumentException::class, $message);
        $this->assertThrows(fn () => $calculateur->retourDeLigne([...self::ligneDeVente(4000, 2), 'montant_ht' => 66.67, 'montant_tva' => 1333], 1, $rienRendu), InvalidArgumentException::class, $message);
        $this->assertThrows(fn () => $calculateur->retourDAchat([...self::ligneDAchat(1001, 2), 'montant_ht' => 2002, 'montant_tva' => 400], 1, ['montant_ht' => 10.01, 'montant_tva' => 0]), InvalidArgumentException::class, $message);
    }

    /**
     * Le prix HT qu'affichent la fiche d'un produit et le barème : 40,00 €
     * TTC = 33,33 € HT ; 10,00 € TTC à 5,5 % = 9,48 € HT (10 / 1,055 =
     * 9,4786...).
     */
    public function test_un_prix_ttc_sans_sa_tva_s_affiche_au_centime(): void
    {
        $this->assertSame(3333, $this->calculateur()->prixUnitaireHtDepuisTtc(4000, 2000));
        $this->assertSame(948, $this->calculateur()->prixUnitaireHtDepuisTtc(1000, 550));
    }

    /**
     * Le PU HT qu'enregistre une ligne de vente est son PU TTC ÷ (1 +
     * taux), au millionième d'euro (Q1-b) : 1,00 € TTC à 20 % = 0,833333 € ;
     * 12,00 € = 10,00 € ; 9,99 € = 8,325 € ; 10,00 € à 5,5 % = 9,478673 €.
     * Un demi-millionième exact s'arrondit au millionième supérieur : 0,01 €
     * à 28 % = 0,0078125 € -> 0,007813 €. Le plus grand prix des colonnes,
     * à 100 %, se convertit sans dépasser les entiers.
     */
    public function test_le_pu_ht_se_calcule_avec_au_plus_six_decimales(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame(
            [833333, 10000000, 8325000, 9478673, 7813, 4999999999995000],
            [
                $calculateur->prixUnitaireHtExact(100, 2000),
                $calculateur->prixUnitaireHtExact(1200, 2000),
                $calculateur->prixUnitaireHtExact(999, 2000),
                $calculateur->prixUnitaireHtExact(1000, 550),
                $calculateur->prixUnitaireHtExact(1, 2800),
                $calculateur->prixUnitaireHtExact(999999999999, 10000),
            ],
        );
    }

    /**
     * Un PU HT au millionième se lit dans sa colonne decimal(18,6), s'écrit
     * comme elle, se montre dans un champ avec ses décimales utiles, deux
     * au moins, et s'imprime en euros de même ; un flottant ou une
     * septième décimale ne se lit pas.
     */
    public function test_un_pu_ht_au_millionieme_se_lit_et_s_ecrit_avec_ses_decimales_utiles(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([833333, 10010000, 12000000], [$calculateur->enMillioniemes('0.833333'), $calculateur->enMillioniemes('10.01'), $calculateur->enMillioniemes(12)]);
        $this->assertSame(['0.833333', '10.000000'], [$calculateur->enDecimalAuMillionieme(833333), $calculateur->enDecimalAuMillionieme(10000000)]);
        $this->assertSame(['0.833333', '8.325', '12.00'], [$calculateur->enDecimalUtile(833333), $calculateur->enDecimalUtile(8325000), $calculateur->enDecimalUtile(12000000)]);
        $this->assertSame(['0,833333 €', '1 000,00 €', '-8,325 €'], [$calculateur->prixUnitaireEnEuros(833333), $calculateur->prixUnitaireEnEuros(1000000000), $calculateur->prixUnitaireEnEuros(-8325000)]);
        foreach ([0.833333, '0.8333333', '1,5'] as $illisible) {
            $this->assertThrows(fn () => $calculateur->enMillioniemes($illisible), InvalidArgumentException::class, 'Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
        }
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33.
     */
    public function test_arrondir_au_centime_ne_tombe_pas_dans_le_piege_du_flottant(): void
    {
        $this->assertSame(8.33, $this->calculateur()->arrondirAuCentime(8.325));
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $total = $this->calculateur()->totaliser(array_fill(0, 1000, ['montant_ht' => '0.08', 'montant_tva' => '0.02', 'montant_ttc' => '0.10']));

        $this->assertSame(100.0, $total['total_ttc']);
    }

    /**
     * Les 10 000 prix TTC de 0,01 € à 100,00 €, pour chaque taux : le PU HT
     * est exactement TTC / (1 + taux), arrondi au centime.
     */
    public function test_la_conversion_ttc_vers_ht_est_exacte_sur_tous_les_prix_de_0_01_a_100_euros(): void
    {
        $verifications = 0;

        foreach (self::TAUX as $taux) {
            for ($ttcCentimes = 1; $ttcCentimes <= 10000; $ttcCentimes++) {
                $ht = $this->calculateur()->prixUnitaireHtDepuisTtc($ttcCentimes, $this->centiemes($taux));

                $this->assertSame($this->htDepuisTtcAttendu($ttcCentimes, $taux), $ht, "TTC {$this->texte($ttcCentimes)} à {$taux} %");
                $verifications++;
            }
        }

        $this->assertSame(70000, $verifications);
    }

    /**
     * Les 10 000 prix de vente HT de 0,01 € à 100,00 €, pour chaque taux :
     * le TTC est exactement HT + HT x taux, la TVA arrondie au centime.
     */
    public function test_la_conversion_ht_vers_ttc_est_exacte_sur_tous_les_prix_de_0_01_a_100_euros(): void
    {
        $verifications = 0;

        foreach (self::TAUX as $taux) {
            for ($htCentimes = 1; $htCentimes <= 10000; $htCentimes++) {
                $ttc = $this->calculateur()->prixUnitaireTtcDepuisHt($htCentimes / 100, (float) $taux);

                $this->assertSame($this->ttcDepuisHtAttendu($htCentimes, $taux), $this->enCentimes($ttc), "HT {$this->texte($htCentimes)} à {$taux} %");
                $verifications++;
            }
        }

        $this->assertSame(70000, $verifications);
    }

    /**
     * Tous les montants à demi-centime de 0,005 € à 100,005 € (le piège du
     * flottant : 8.325 vaut 8.32499999... en binaire), ainsi que leurs
     * voisins juste en dessous et au-dessus.
     */
    public function test_larrondi_au_centime_est_exact_sur_tous_les_demi_centimes_jusqua_100_euros(): void
    {
        $verifications = 0;

        for ($centimes = 0; $centimes <= 10000; $centimes++) {
            foreach ([4 => $centimes, 5 => $centimes + 1, 6 => $centimes + 1] as $milliemeFinal => $attendu) {
                $texte = sprintf('%d.%02d%d', intdiv($centimes, 100), $centimes % 100, $milliemeFinal);

                $this->assertSame($attendu, $this->enCentimes($this->calculateur()->arrondirAuCentime((float) $texte)), $texte);
                $verifications++;
            }
        }

        $this->assertSame(30003, $verifications);
    }

    /**
     * 10 000 lignes seules tirées au hasard (prix de 0,01 € à 9 999,99 €,
     * vente ou achat, de 1 à 999 articles, avec ou sans remise, tous les
     * taux) : une vente a pour TTC son prix x quantité remise déduite et
     * pour HT ce TTC ÷ (1 + taux) ; un achat a pour HT son prix x quantité
     * remise déduite, et pour TVA celle de ce HT ; HT + TVA = TTC.
     */
    public function test_10000_lignes_aleatoires_egalent_le_calcul_exact(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE));

        for ($i = 0; $i < 10000; $i++) {
            $ligne = $this->ligneAleatoire($hasard);
            $net = $this->netAttendu($ligne['prix'], $ligne['quantite'], $ligne['remise']);

            if ($ligne['vente']) {
                $montants = $this->calculateur()->chiffrerUneVente([self::ligneDeVente($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise'])])['lignes'][0];
                $ht = $this->htDepuisTtcAttendu($net, $ligne['taux']);
                $attendus = [$ht, $net - $ht, $net];
            } else {
                $montants = $this->calculateur()->chiffrerUnAchat([self::ligneDAchat($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise'])])['lignes'][0];
                $tva = $this->tvaAttendue($net, $ligne['taux']);
                $attendus = [$net, $tva, $net + $tva];
            }

            $this->assertSame($attendus, self::triplet($montants), $this->decrire($ligne));
        }
    }

    /**
     * 2 000 ventes de 1 à 20 lignes tirées au hasard, de taux mêlés, une
     * sur cinq sans TVA française : pour chaque taux du prix, la somme des
     * HT de ses lignes est exactement le TTC de ses lignes ÷ (1 + taux),
     * arrondi une fois ; chaque ligne a pour TTC son prix (son HT sans TVA
     * française), aucun montant négatif, et un HT à moins d'un centime de
     * sa part exacte ; chaque taux et le document totalisent exactement
     * leurs lignes.
     */
    public function test_2000_ventes_aleatoires_se_calculent_taux_par_taux_sur_leur_ttc(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 1));

        for ($i = 0; $i < 2000; $i++) {
            $sansTva = $hasard->getInt(0, 4) === 0;
            $lignes = [];
            $nets = [];

            for ($n = $hasard->getInt(1, 20); $n > 0; $n--) {
                $ligne = [...$this->ligneAleatoire($hasard), 'vente' => true];
                $lignes[] = self::ligneDeVente($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise'], $sansTva);
                $nets[] = $this->netAttendu($ligne['prix'], $ligne['quantite'], $ligne['remise']);
            }

            $vente = $this->calculateur()->chiffrerUneVente($lignes);
            $cas = "vente n° {$i}";
            $htParTaux = [];
            $ttcParTaux = [];
            $parTauxFacture = [];

            foreach ($vente['lignes'] as $index => $montants) {
                [$ht, $tva, $ttc] = self::triplet($montants);
                $tauxDuPrix = $lignes[$index]['taux_tva_du_prix'];
                $exact = BigDecimal::ofUnscaledValue($nets[$index], 2)->dividedBy(BigDecimal::one()->plus(BigDecimal::ofUnscaledValue($tauxDuPrix, 4)), 6, RoundingMode::HalfUp)->multipliedBy(100);

                $this->assertSame($sansTva ? $ht : $nets[$index], $ttc, "TTC de la ligne {$index} : {$cas}");
                $this->assertSame($ttc - $ht, $tva, "TVA de la ligne {$index} : {$cas}");
                $this->assertGreaterThanOrEqual(0, $ht, "HT de la ligne {$index} : {$cas}");
                $this->assertGreaterThanOrEqual(0, $tva, "TVA de la ligne {$index} : {$cas}");
                $this->assertTrue($exact->minus($ht)->abs()->isLessThan(1), "HT de la ligne {$index} à plus d'un centime de sa part exacte : {$cas}");

                $htParTaux[$tauxDuPrix] = ($htParTaux[$tauxDuPrix] ?? 0) + $ht;
                $ttcParTaux[$tauxDuPrix] = ($ttcParTaux[$tauxDuPrix] ?? 0) + $nets[$index];
                $facture = $lignes[$index]['taux_tva'];
                $parTauxFacture[$facture] = array_map(fn (int $a, int $b): int => $a + $b, $parTauxFacture[$facture] ?? [0, 0, 0], [$ht, $tva, $ttc]);
            }

            foreach ($htParTaux as $tauxDuPrix => $ht) {
                $this->assertSame($this->htDepuisTtcAttendu($ttcParTaux[$tauxDuPrix], (string) BigDecimal::ofUnscaledValue($tauxDuPrix, 2)), $ht, "HT du taux {$tauxDuPrix} : {$cas}");
            }

            $this->assertSame($parTauxFacture, self::triplets($vente['taux']), "montants par taux : {$cas}");
            $this->assertSame([
                array_sum(array_column($parTauxFacture, 0)),
                array_sum(array_column($parTauxFacture, 1)),
                array_sum(array_column($parTauxFacture, 2)),
            ], self::triplet($vente['total']), "total : {$cas}");
        }
    }

    /**
     * 2 000 factures d'achat de 1 à 20 lignes tirées au hasard, de taux
     * mêlés : chaque ligne a pour HT son prix x quantité remise déduite ;
     * pour chaque taux, la somme des parts de TVA des lignes vaut
     * exactement la TVA du total HT du taux, arrondie une fois ; aucune
     * part n'est négative, et chacune s'écarte d'au plus un centime de la
     * TVA du HT de sa ligne.
     */
    public function test_2000_achats_aleatoires_ont_la_tva_du_total_ht_de_chaque_taux(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 4));

        for ($i = 0; $i < 2000; $i++) {
            $lignes = [];
            $taux = [];

            for ($n = $hasard->getInt(1, 20); $n > 0; $n--) {
                $ligne = $this->ligneAleatoire($hasard);
                $lignes[] = self::ligneDAchat($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise']);
                $taux[] = $ligne['taux'];
            }

            $achat = $this->calculateur()->chiffrerUnAchat($lignes);
            $htParTaux = [];
            $tvaParTaux = [];

            foreach ($achat['lignes'] as $index => $montants) {
                $cas = "facture n° {$i}, ligne {$index}";
                $net = $this->netAttendu($lignes[$index]['prix_unitaire_ht'], $lignes[$index]['quantite'], $lignes[$index]['remise_pourcentage']);

                $this->assertSame($net, $montants['montant_ht'], "HT : {$cas}");
                $this->assertSame($montants['montant_ht'] + $montants['montant_tva'], $montants['montant_ttc'], "TTC : {$cas}");
                $this->assertGreaterThanOrEqual(0, $montants['montant_tva'], "TVA : {$cas}");
                $this->assertLessThanOrEqual(1, abs($montants['montant_tva'] - $this->tvaAttendue($net, $taux[$index])), "TVA : {$cas}");

                $htParTaux[$taux[$index]] = ($htParTaux[$taux[$index]] ?? 0) + $net;
                $tvaParTaux[$taux[$index]] = ($tvaParTaux[$taux[$index]] ?? 0) + $montants['montant_tva'];
            }

            foreach ($htParTaux as $tauxDuTotal => $ht) {
                $this->assertSame($this->tvaAttendue($ht, (string) $tauxDuTotal), $tvaParTaux[$tauxDuTotal], "facture n° {$i}, taux {$tauxDuTotal} %");
            }

            $this->assertSame([array_sum($htParTaux), array_sum($tvaParTaux), array_sum($htParTaux) + array_sum($tvaParTaux)], self::triplet($achat['total']), "facture n° {$i}");
        }
    }

    /**
     * 2 000 factures de vente ou d'achat de 1 à 20 lignes tirées au hasard,
     * de taux mêlés, chiffrées par chiffrerUneVente() ou chiffrerUnAchat()
     * puis écrites comme leurs colonnes (« 41.67 ») : totaliser(), qui fait
     * les totaux d'un avoir et, par cumuler() et deduire(), ceux des
     * clôtures, rend exactement la somme de leurs HT, de leurs TVA et de
     * leurs TTC, et HT + TVA = TTC.
     */
    public function test_2000_factures_aleatoires_totalisent_exactement_leurs_lignes(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 3));
        $calculateur = $this->calculateur();

        for ($i = 0; $i < 2000; $i++) {
            $vente = $hasard->getInt(0, 1) === 0;
            $lignes = [];

            for ($n = $hasard->getInt(1, 20); $n > 0; $n--) {
                $ligne = $this->ligneAleatoire($hasard);
                $lignes[] = $vente
                    ? self::ligneDeVente($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise'])
                    : self::ligneDAchat($ligne['prix'], $ligne['quantite'], $this->centiemes($ligne['taux']), $ligne['remise']);
            }

            $montants = ($vente ? $calculateur->chiffrerUneVente($lignes) : $calculateur->chiffrerUnAchat($lignes))['lignes'];
            $cas = ($vente ? 'vente' : 'achat')." n° {$i}";

            $totaux = $calculateur->totaliser(array_map(fn (array $montantsDeLigne): array => array_map($calculateur->enDecimal(...), $montantsDeLigne), $montants));

            $this->assertSame([
                array_sum(array_column($montants, 'montant_ht')),
                array_sum(array_column($montants, 'montant_tva')),
                array_sum(array_column($montants, 'montant_ttc')),
            ], [$this->enCentimes($totaux['total_ht']), $this->enCentimes($totaux['total_tva']), $this->enCentimes($totaux['total_ttc'])], $cas);
            $this->assertSame($this->enCentimes($totaux['total_ttc']), $this->enCentimes($totaux['total_ht']) + $this->enCentimes($totaux['total_tva']), $cas);
        }
    }

    /**
     * 2 000 lignes de ventes (de 1 à 5 lignes du même taux, une vente sur
     * cinq sans TVA française), rendues en retours successifs de quantités
     * tirées au hasard : tant que la ligne n'est pas toute rendue, le TTC
     * rendu en tout est le prix des articles rendus (leur HT sans TVA
     * française), et le HT rendu en tout le TTC ÷ (1 + taux) arrondi une
     * fois, gardé dans la limite du HT et de la TVA de la ligne ; aucun
     * retour ne rend de montant négatif, et la somme des retours retombe
     * exactement sur la ligne.
     */
    public function test_2000_series_de_retours_creditent_exactement_la_ligne(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 2));

        for ($i = 0; $i < 2000; $i++) {
            $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
            $sansTva = $hasard->getInt(0, 4) === 0;
            $lignes = [];

            for ($n = $hasard->getInt(1, 5); $n > 0; $n--) {
                $ligne = $this->ligneAleatoire($hasard);
                $lignes[] = self::ligneDeVente($ligne['prix'], $hasard->getInt(2, 999), $this->centiemes($taux), $ligne['remise'], $sansTva);
            }

            $vente = $this->calculateur()->chiffrerUneVente($lignes);
            $choisie = $hasard->getInt(0, count($lignes) - 1);
            $vendue = [...$lignes[$choisie], 'montant_ht' => $vente['lignes'][$choisie]['montant_ht'], 'montant_tva' => $vente['lignes'][$choisie]['montant_tva']];
            $cas = sprintf('vente n° %d, PU TTC %s (TVA %s %%%s), quantité %d, remise %s %%', $i, $this->texte($vendue['prix_unitaire_ttc']), $taux, $sansTva ? ', retirée' : '', $vendue['quantite'], $this->texte($vendue['remise_pourcentage']));
            $rendu = ['montant_ht' => 0, 'montant_tva' => 0];
            $rendus = 0;

            while ($rendus < $vendue['quantite']) {
                $rendus = $hasard->getInt(0, 3) === 0 ? $vendue['quantite'] : $hasard->getInt($rendus + 1, $vendue['quantite']);
                $retour = $this->calculateur()->retourDeLigne($vendue, $rendus, $rendu);

                foreach ($retour as $montant => $valeur) {
                    $this->assertGreaterThanOrEqual(0, $valeur, "{$montant} négatif : {$cas}");
                }

                $this->assertSame($retour['montant_ht'] + $retour['montant_tva'], $retour['montant_ttc'], "TTC du retour : {$cas}");
                $rendu = ['montant_ht' => $rendu['montant_ht'] + $retour['montant_ht'], 'montant_tva' => $rendu['montant_tva'] + $retour['montant_tva']];

                if ($rendus < $vendue['quantite']) {
                    $net = $this->netAttendu($vendue['prix_unitaire_ttc'], $rendus, $vendue['remise_pourcentage']);
                    $htAuPrix = $this->htDepuisTtcAttendu($net, $taux);

                    $this->assertSame($sansTva
                        ? [min($htAuPrix, $vendue['montant_ht']), 0]
                        : [$ht = min($vendue['montant_ht'], max($htAuPrix, $net - $vendue['montant_tva'])), $net - $ht], [$rendu['montant_ht'], $rendu['montant_tva']], "cumul de {$rendus} : {$cas}");
                }
            }

            $this->assertSame([$vendue['montant_ht'], $vendue['montant_tva']], [$rendu['montant_ht'], $rendu['montant_tva']], "total : {$cas}");
        }
    }

    /**
     * 2 000 lignes d'achats (de 1 à 5 lignes du même taux, dont la TVA se
     * répartit), retournées en retours successifs de quantités tirées au
     * hasard : tant que la ligne n'est pas toute retournée, le HT retourné
     * en tout est celui des articles retournés, et sa TVA celle de ce HT,
     * plafonnée à la ligne ; aucun retour ne rend de montant négatif, et
     * la somme des retours retombe exactement sur la ligne.
     */
    public function test_2000_series_de_retours_au_fournisseur_creditent_exactement_la_ligne(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 5));

        for ($i = 0; $i < 2000; $i++) {
            $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
            $lignes = [];

            for ($n = $hasard->getInt(1, 5); $n > 0; $n--) {
                $ligne = $this->ligneAleatoire($hasard);
                $lignes[] = self::ligneDAchat($ligne['prix'], $hasard->getInt(2, 999), $this->centiemes($taux), $ligne['remise']);
            }

            $achat = $this->calculateur()->chiffrerUnAchat($lignes);
            $choisie = $hasard->getInt(0, count($lignes) - 1);
            $achetee = [...$lignes[$choisie], 'montant_ht' => $achat['lignes'][$choisie]['montant_ht'], 'montant_tva' => $achat['lignes'][$choisie]['montant_tva']];
            $cas = sprintf('achat n° %d, PU HT %s (TVA %s %%), quantité %d, remise %s %%, TVA de la ligne %s', $i, $this->texte($achetee['prix_unitaire_ht']), $taux, $achetee['quantite'], $this->texte($achetee['remise_pourcentage']), $this->texte($achetee['montant_tva']));
            $rendu = ['montant_ht' => 0, 'montant_tva' => 0];
            $rendus = 0;

            while ($rendus < $achetee['quantite']) {
                $rendus = $hasard->getInt(0, 3) === 0 ? $achetee['quantite'] : $hasard->getInt($rendus + 1, $achetee['quantite']);
                $retour = $this->calculateur()->retourDAchat($achetee, $rendus, $rendu);

                foreach ($retour as $montant => $valeur) {
                    $this->assertGreaterThanOrEqual(0, $valeur, "{$montant} négatif : {$cas}");
                }

                $rendu = ['montant_ht' => $rendu['montant_ht'] + $retour['montant_ht'], 'montant_tva' => $rendu['montant_tva'] + $retour['montant_tva']];

                if ($rendus < $achetee['quantite']) {
                    $ht = $this->netAttendu($achetee['prix_unitaire_ht'], $rendus, $achetee['remise_pourcentage']);

                    $this->assertSame([$ht, min($achetee['montant_tva'], $this->tvaAttendue($ht, $taux))], [$rendu['montant_ht'], $rendu['montant_tva']], "cumul de {$rendus} : {$cas}");
                }
            }

            $this->assertSame([$achetee['montant_ht'], $achetee['montant_tva']], [$rendu['montant_ht'], $rendu['montant_tva']], "total : {$cas}");
        }
    }

    /**
     * Ligne tirée au hasard, en unités entières : une vente (PU TTC en
     * centimes) ou un achat (PU HT), de 1 à 999 articles, remise en
     * centièmes de point (nulle sept fois sur dix).
     *
     * @return array{prix: int, quantite: int, remise: int, taux: string, vente: bool}
     */
    private function ligneAleatoire(Randomizer $hasard): array
    {
        return [
            'taux' => self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)],
            'prix' => $hasard->getInt(1, 999999),
            'vente' => $hasard->getInt(0, 1) === 0,
            'quantite' => $hasard->getInt(1, 999),
            'remise' => $hasard->getInt(1, 10) <= 7 ? 0 : $hasard->getInt(0, 10000),
        ];
    }

    /**
     * Référence exacte de prix x quantité, remise arrondie au centime
     * déduite.
     */
    private function netAttendu(int $prixCentimes, int $quantite, int $remise): int
    {
        $brut = $prixCentimes * $quantite;

        return $brut - $this->auCentime(BigDecimal::ofUnscaledValue($brut, 2)->multipliedBy(BigDecimal::ofUnscaledValue($remise, 2))->dividedBy(100, 6));
    }

    private function tvaAttendue(int $htCentimes, string $taux): int
    {
        return $this->auCentime(BigDecimal::ofUnscaledValue($htCentimes, 2)->multipliedBy(BigDecimal::of($taux))->dividedBy(100, 6));
    }

    private function htDepuisTtcAttendu(int $ttcCentimes, string $taux): int
    {
        return BigDecimal::ofUnscaledValue($ttcCentimes, 2)
            ->dividedBy(BigDecimal::one()->plus(BigDecimal::of($taux)->dividedBy(100, 4)), 2, RoundingMode::HalfUp)
            ->getUnscaledValue()
            ->toInt();
    }

    private function ttcDepuisHtAttendu(int $htCentimes, string $taux): int
    {
        return $htCentimes + $this->tvaAttendue($htCentimes, $taux);
    }

    private function auCentime(BigDecimal $montant): int
    {
        return $montant->toScale(2, RoundingMode::HalfUp)->getUnscaledValue()->toInt();
    }

    /**
     * Un taux écrit comme dans TAUX (« 5.5 »), en centièmes de point.
     */
    private function centiemes(string $taux): int
    {
        return BigDecimal::of($taux)->multipliedBy(100)->toInt();
    }

    private function enCentimes(float $montant): int
    {
        return (int) round($montant * 100);
    }

    private function texte(int $centimes): string
    {
        return (string) BigDecimal::ofUnscaledValue($centimes, 2);
    }

    /**
     * @param  array{prix: int, quantite: int, remise: int, taux: string, vente: bool}  $ligne
     */
    private function decrire(array $ligne): string
    {
        return sprintf(
            '%s : PU %s %s (TVA %s %%), quantité %d, remise %s %%',
            $ligne['vente'] ? 'vente' : 'achat',
            $ligne['vente'] ? 'TTC' : 'HT',
            $this->texte($ligne['prix']),
            $ligne['taux'],
            $ligne['quantite'],
            $this->texte($ligne['remise']),
        );
    }

    /**
     * -100 % diviserait par zéro en passant du TTC au HT ; un taux ou une
     * remise hors de 0-100 % n'existe pas.
     *
     * @return array<string, array{Closure(CalculateurLigne): mixed, string}>
     */
    public static function saisiesHorsBornes(): array
    {
        return [
            'taux négatif (TTC vers HT)' => [fn (CalculateurLigne $c) => $c->prixUnitaireHtDepuisTtc(1000, -10000), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux négatif (HT vers TTC)' => [fn (CalculateurLigne $c) => $c->prixUnitaireTtcDepuisHt(10, -5), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (achat)' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(1000, 1, 15000)]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (prix de vente)' => [fn (CalculateurLigne $c) => $c->prixTtcArrondi(10, 150), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise négative' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1200, 1, 2000, -1000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'remise au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(1000, 1, 2000, 15000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'escompte au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->coutUnitaireNet(10, 0, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'montant reçu absurde' => [fn (CalculateurLigne $c) => $c->soustraire('100000000000000000', 40), 'Montant hors limites.'],
            'paiement absurde' => [fn (CalculateurLigne $c) => $c->arrondirAuCentime(1e17), 'Montant hors limites.'],
            'prix x quantité qui déborde (achat)' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(999999999999, 999999)]), 'Montant hors limites.'],
            'taux au-delà de 100 % (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1000, 1, 15000)]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise au-delà de 100 % (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1000, 1, 2000, 15000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'prix x quantité qui déborde (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(999999999999, 999999)]), 'Montant hors limites.'],
            'taux négatif (retour au fournisseur)' => [fn (CalculateurLigne $c) => $c->retourDAchat([...self::ligneDAchat(1000, 2, -500), 'montant_ht' => 2000, 'montant_tva' => 0], 1, ['montant_ht' => 0, 'montant_tva' => 0]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'prix négatif (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(-100)]), 'Un prix unitaire ne peut pas être négatif.'],
            'taux facturé qui n\'est pas celui du prix' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([[...self::ligneDeVente(1000), 'taux_tva' => 550]]), 'Une ligne facture le taux que comprend son prix, ou aucun.'],
        ];
    }

    #[DataProvider('saisiesHorsBornes')]
    public function test_un_taux_ou_une_remise_hors_bornes_est_refuse(Closure $calcul, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calcul($this->calculateur());
    }

    public function test_les_bornes_0_et_100_restent_acceptees(): void
    {
        $this->assertSame(1000, $this->calculateur()->prixUnitaireHtDepuisTtc(1000, 0));
        $this->assertSame([1000, 1000, 2000], self::triplet($this->calculateur()->chiffrerUnAchat([self::ligneDAchat(1000, 1, 10000)])['total']));
        $this->assertSame([0, 0, 0], self::triplet($this->calculateur()->chiffrerUneVente([self::ligneDeVente(1200, 3, 2000, 10000)])['total']));
    }

    /**
     * Le coût d'un article acheté ne s'arrondit pas au centime : 1,18 €
     * remisé de 15 % coûte 1,003 € ; 8,33 € remisés de 10 % puis escomptés
     * de 2 % coûtent 7,34706 €, soit 7,3471 € au dix-millième du CMUP. Il
     * reste exact jusqu'au plus grand prix que les colonnes acceptent.
     */
    public function test_le_cout_net_dun_article_achete_ne_sarrondit_pas_au_centime(): void
    {
        $this->assertSame(1.003, $this->calculateur()->coutUnitaireNet(1.18, 15));
        $this->assertSame(7.3471, $this->calculateur()->coutUnitaireNet(8.33, 10, 2));
        $this->assertSame(88.2, $this->calculateur()->coutUnitaireNet(90, 0, 2));
        $this->assertSame(0.0, $this->calculateur()->coutUnitaireNet(90, 100));
        $this->assertSame(9999999999.99, $this->calculateur()->coutUnitaireNet(9999999999.99, 0));
        $this->assertSame(8074999999.9919, $this->calculateur()->coutUnitaireNet(9999999999.99, 15, 5));
    }

    /**
     * Le bénéfice d'un article se calcule depuis son coût exact et ne
     * s'arrondit qu'une fois : 11,00 € - 1,005 € = 9,995 € -> 10,00 €, là
     * où 11,00 € - 1,01 € donnerait 9,99 €.
     */
    public function test_le_benefice_dun_article_ne_sarrondit_qu_une_fois(): void
    {
        $this->assertSame(10.0, $this->calculateur()->beneficeUnitaire(11.00, 1.005));
        $this->assertSame(9.53, $this->calculateur()->beneficeUnitaire(10.00, 0.47));
    }

    /**
     * Le taux de marque, bénéfice ÷ prix de vente HT, en centièmes de point,
     * arrondi une fois au plus proche : 12,50 € sur 20,83 € font 60,01 %
     * (0,600096...) ; une perte de 1,00 € sur 20,83 €, -4,80 % ; 1,00 €
     * sur 16,00 €, exactement 6,25 % ; un demi-centième de point
     * s'arrondit vers l'extérieur : 0,01 € sur 40,00 € font 0,025 %, soit
     * 0,03 %, et -0,03 % pour une perte ; sans prix de vente, aucun taux.
     */
    public function test_le_taux_de_marque_ne_s_arrondit_qu_une_fois(): void
    {
        $this->assertSame(6001, $this->calculateur()->tauxDeMarque(1250, 2083));
        $this->assertSame(-480, $this->calculateur()->tauxDeMarque(-100, 2083));
        $this->assertSame(625, $this->calculateur()->tauxDeMarque(100, 1600));
        $this->assertSame(3, $this->calculateur()->tauxDeMarque(1, 4000));
        $this->assertSame(-3, $this->calculateur()->tauxDeMarque(-1, 4000));
        $this->assertNull($this->calculateur()->tauxDeMarque(500, 0));
    }

    /**
     * Le prix de vente se calcule depuis le HT exact et ne s'arrondit qu'une
     * fois : 1,0042 € HT x 1,2 = 1,20504 € -> 1,21 € (et non 1,00 € x 1,2 =
     * 1,20 €) ; à la dizaine, 54,1663 € HT x 1,2 = 64,99956 € -> 60 € (et
     * non 65,00 € au centime puis 70 €, ni 54,17 € x 1,2 = 65,004 € ->
     * 70 €), alors que 54,1667 € HT x 1,2 = 65,00004 € -> 70 €.
     */
    public function test_le_prix_de_vente_ttc_ne_sarrondit_qu_une_fois(): void
    {
        $this->assertSame(1.21, $this->calculateur()->prixTtcArrondi(1.0042, 20));
        $this->assertSame(60.0, $this->calculateur()->prixTtcArrondi(54.1663, 20, pasCentimes: 1000));
        $this->assertSame(70.0, $this->calculateur()->prixTtcArrondi(54.1667, 20, pasCentimes: 1000));
    }

    /**
     * Le coût moyen de lots se pondère par leurs quantités, au dix-millième :
     * 10 à 2 € et 20 à 3,5 € coûtent 3 € ; 3 à 1 € et 4 à 2 €, 1,5714 €. Des
     * lots offerts coûtent 0 €, et des lots sans aucun article n'ont pas de
     * coût moyen.
     */
    public function test_le_cout_moyen_pondere_compte_les_lots_offerts_a_zero(): void
    {
        $this->assertSame(3.0, $this->calculateur()->coutMoyenPondere([['quantite' => 10, 'cout_unitaire' => 2], ['quantite' => 20, 'cout_unitaire' => '3.5']]));
        $this->assertSame(1.5714, $this->calculateur()->coutMoyenPondere([['quantite' => 3, 'cout_unitaire' => 1], ['quantite' => 4, 'cout_unitaire' => 2]]));
        $this->assertSame(0.0, $this->calculateur()->coutMoyenPondere([['quantite' => 0, 'cout_unitaire' => 5], ['quantite' => 10, 'cout_unitaire' => 0]]));
        $this->assertNull($this->calculateur()->coutMoyenPondere([['quantite' => 0, 'cout_unitaire' => 5]]));
        $this->assertNull($this->calculateur()->coutMoyenPondere([]));
    }

    public function test_un_lot_de_quantite_ou_de_cout_negatif_est_refuse(): void
    {
        $this->assertThrows(
            fn () => $this->calculateur()->coutMoyenPondere([['quantite' => -1, 'cout_unitaire' => 5], ['quantite' => 10, 'cout_unitaire' => 5]]),
            InvalidArgumentException::class,
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
        $this->assertThrows(
            fn () => $this->calculateur()->coutMoyenPondere([['quantite' => 10, 'cout_unitaire' => -0.5]]),
            InvalidArgumentException::class,
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
    }

    /**
     * Le coût moyen du stock restant après un retour au fournisseur, au
     * coût de la ligne retournée : (stock x coût moyen - quantité x coût) /
     * (stock - quantité), au dix-millième, arrondi une seule fois. Sans
     * stock restant, aucun ; une valeur restante négative vaut 0 €.
     *
     * @return array<string, array{int, float, int, float, float|null}>
     */
    public static function sortiesAUnAutreCout(): array
    {
        return [
            'les 10 articles à 10 € d\'un stock de 20 à 6 €' => [20, 6.0, 10, 10.0, 2.0],
            '4 articles à 10 € d\'un stock de 20 à 6 €' => [20, 6.0, 4, 10.0, 5.0],
            '8,9998 € pour 6 articles' => [7, 1.5714, 1, 2.0, 1.5],
            'tout le stock' => [5, 3.0, 5, 3.0, null],
            'plus que le stock' => [5, 3.0, 6, 3.0, null],
            'une valeur restante de -10 €' => [5, 6.0, 4, 10.0, 0.0],
        ];
    }

    #[DataProvider('sortiesAUnAutreCout')]
    public function test_le_cout_moyen_apres_une_sortie_a_un_autre_cout(int $stock, float $coutMoyen, int $quantite, float $coutUnitaire, ?float $attendu): void
    {
        $this->assertSame($attendu, $this->calculateur()->coutMoyenApresUneSortie($stock, $coutMoyen, $quantite, $coutUnitaire));
    }

    /**
     * @return array<string, array{int, float, int, float}>
     */
    public static function sortiesImpossibles(): array
    {
        return [
            'stock négatif' => [-1, 6.0, 1, 10.0],
            'quantité négative' => [20, 6.0, -1, 10.0],
            'coût moyen négatif' => [20, -6.0, 1, 10.0],
            'coût de sortie négatif' => [20, 6.0, 1, -10.0],
        ];
    }

    #[DataProvider('sortiesImpossibles')]
    public function test_une_sortie_de_quantite_ou_de_cout_negatif_est_refusee(int $stock, float $coutMoyen, int $quantite, float $coutUnitaire): void
    {
        $this->assertThrows(
            fn () => $this->calculateur()->coutMoyenApresUneSortie($stock, $coutMoyen, $quantite, $coutUnitaire),
            InvalidArgumentException::class,
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
    }

    public function test_sommer_et_soustraire_comptent_au_centime_exact(): void
    {
        $this->assertSame(0.3, $this->calculateur()->sommer([0.1, 0.2]));
        $this->assertSame(100.0, $this->calculateur()->sommer(array_fill(0, 1000, '0.10')));
        $this->assertSame(16.67, $this->calculateur()->soustraire(50, 33.33));
        $this->assertSame(-10.0, $this->calculateur()->soustraire('40.00', '30.00', 20));
        $this->assertSame(0.0, $this->calculateur()->sommer([null, '']));
    }

    /**
     * Une colonne decimal(12,2) ou (5,2), lue en chaîne par le cast
     * decimal:2, donne ses centièmes exacts ; un entier compte des unités
     * entières (un taux de 20 % vaut 2000).
     */
    public function test_une_colonne_decimale_se_lit_en_centiemes_exacts(): void
    {
        $this->assertSame(12000, $this->calculateur()->enCentiemes('120.00'));
        $this->assertSame(-5, $this->calculateur()->enCentiemes('-0.05'));
        $this->assertSame(50, $this->calculateur()->enCentiemes('0.5'));
        $this->assertSame(700, $this->calculateur()->enCentiemes('7'));
        $this->assertSame(750, $this->calculateur()->enCentiemes('0007.5'));
        $this->assertSame(0, $this->calculateur()->enCentiemes('-0.00'));
        $this->assertSame(999999999999, $this->calculateur()->enCentiemes('9999999999.99'));
        $this->assertSame(2000, $this->calculateur()->enCentiemes(20));
        $this->assertSame(-300, $this->calculateur()->enCentiemes(-3));
    }

    /**
     * Seule une écriture décimale exacte se lit : jamais un flottant, même
     * entier, ni une saisie à interpréter.
     *
     * @return array<string, array{mixed}>
     */
    public static function ecrituresIllisibles(): array
    {
        return [
            'trois décimales' => ['1.234'],
            'virgule' => ['1,00'],
            'espace avant' => [' 1.00'],
            'espace après' => ['1.00 '],
            'retour à la ligne final' => ["1.00\n"],
            'signe plus' => ['+1.00'],
            'sans partie entière' => ['.50'],
            'point final' => ['1.'],
            'notation scientifique' => ['1e3'],
            'chaîne vide' => [''],
            'signe seul' => ['-'],
            'flottant' => [1.5],
            'flottant entier' => [120.0],
            'flottant imprécis' => [0.1 + 0.2],
            'null' => [null],
        ];
    }

    #[DataProvider('ecrituresIllisibles')]
    public function test_une_ecriture_inexacte_ne_se_lit_pas_en_centiemes(mixed $valeur): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Montant illisible : une chaîne décimale exacte (deux décimales au plus) ou un entier est attendu.');

        $this->calculateur()->enCentiemes($valeur);
    }

    /**
     * La borne est celle de toute la classe (1e14 centièmes), non celle des
     * colonnes : 999 999 999 999,99 se lit encore, le centime suivant non.
     */
    public function test_un_montant_au_dela_de_la_borne_ne_se_lit_pas_en_centiemes(): void
    {
        $this->assertSame(99999999999999, $this->calculateur()->enCentiemes('999999999999.99'));
        $this->assertSame(-99999999999999, $this->calculateur()->enCentiemes('-999999999999.99'));
        $this->assertThrows(fn () => $this->calculateur()->enCentiemes('1000000000000.00'), InvalidArgumentException::class, 'Montant hors limites.');
        $this->assertThrows(fn () => $this->calculateur()->enCentiemes('-99999999999999999999.00'), InvalidArgumentException::class, 'Montant hors limites.');
        $this->assertThrows(fn () => $this->calculateur()->enCentiemes(1_000_000_000_000), InvalidArgumentException::class, 'Montant hors limites.');
        $this->assertThrows(fn () => $this->calculateur()->enCentiemes(PHP_INT_MAX), InvalidArgumentException::class, 'Montant hors limites.');
    }

    public function test_des_centiemes_s_ecrivent_comme_une_colonne_decimale(): void
    {
        $this->assertSame('120.00', $this->calculateur()->enDecimal(12000));
        $this->assertSame('-0.05', $this->calculateur()->enDecimal(-5));
        $this->assertSame('0.07', $this->calculateur()->enDecimal(7));
        $this->assertSame('0.00', $this->calculateur()->enDecimal(0));
        $this->assertSame('-12.30', $this->calculateur()->enDecimal(-1230));
        $this->assertSame('9999999999.99', $this->calculateur()->enDecimal(999999999999));
        $this->assertThrows(fn () => $this->calculateur()->enDecimal(PHP_INT_MIN), InvalidArgumentException::class, 'Montant hors limites.');
    }

    /**
     * L'écriture des montants des écrans en euros (l'alerte des factures à
     * encaisser du magasin et les totaux de l'espace de la société) :
     * milliers séparés par une espace, virgule décimale, toujours deux
     * décimales, le signe devant.
     */
    public function test_des_centiemes_s_ecrivent_en_euros(): void
    {
        $this->assertSame('1 234,50 €', $this->calculateur()->enEuros(123450));
        $this->assertSame('-0,05 €', $this->calculateur()->enEuros(-5));
        $this->assertSame('0,00 €', $this->calculateur()->enEuros(0));
        $this->assertSame('-1 234 567,08 €', $this->calculateur()->enEuros(-123456708));
        $this->assertSame('9 999 999 999,99 €', $this->calculateur()->enEuros(999999999999));
        $this->assertThrows(fn () => $this->calculateur()->enEuros(PHP_INT_MIN), InvalidArgumentException::class, 'Montant hors limites.');
    }
}
