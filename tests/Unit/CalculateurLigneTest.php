<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calcul des montants (voir le commentaire de classe de CalculateurLigne),
 * en centimes entiers : toute vente, à un particulier comme à un
 * professionnel, part de ses prix TTC, ligne par ligne -- la TVA de
 * chaque ligne est son TTC x taux ÷ (1 + taux), arrondie une fois au
 * centime le plus proche, un demi-centime au centime supérieur, son HT est
 * le TTC moins cette TVA, et un centime par ligne au plus ramène la TVA de
 * chaque taux à celle du TTC de ses lignes --, et un retour rend le calcul
 * de la vente sur les articles rendus, dans la limite de la ligne ; un achat
 * part du PU HT du fournisseur, la TVA de chaque taux se calculant sur le
 * total HT du taux, arrondie une fois et répartie par cumul.
 *
 * Les exemples nommés (cas obligatoire de l'éditeur, cas réels signalés
 * par un magasin) ; les comparaisons sur des dizaines de milliers de cas
 * sont dans CalculateurLigneAuHasardTest. Sans l'application Laravel,
 * chaque test ne dure que son calcul : les tests de mutation le lancent
 * parmi les premiers (voir le README).
 */
class CalculateurLigneTest extends TestCase
{
    private function calculateur(): CalculateurLigne
    {
        return new CalculateurLigne;
    }

    /**
     * Le calcul est refusé, avec ce message exactement.
     */
    private function assertRefuse(Closure $calcul, string $message): void
    {
        try {
            $calcul();
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());

            return;
        }

        $this->fail("Le calcul devait être refusé : {$message}");
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
     * Une ligne d'achat : PU HT en centimes, que le calcul lit en
     * millionièmes d'euro (six décimales au plus, T2), taux et remise en
     * centièmes de point.
     *
     * @return array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int}
     */
    private static function ligneDAchat(int $prixHt, int $quantite = 1, int $taux = 2000, int $remise = 0): array
    {
        return ['prix_unitaire_ht' => $prixHt * 10000, 'quantite' => $quantite, 'remise_pourcentage' => $remise, 'taux_tva' => $taux];
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

        // Coût moyen en dix-millièmes d'euro, taux en centièmes de point,
        // valeur en centimes : 2 000 x 0,045 € = 90,00 € HT.
        $this->assertSame(['montant_ht' => 9000, 'montant_tva' => 1800, 'montant_ttc' => 10800], $calculateur->valeurAuCoutMoyen(2000, 450, 2000));
        $this->assertSame(['montant_ht' => 2145, 'montant_tva' => 429, 'montant_ttc' => 2574], $calculateur->valeurAuCoutMoyen(1, 214545, 2000));
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
     * TVA en une ligne, en 2 x 25,00 € ou en deux lignes de 25,00 €
     * (décision de l'éditeur du 14/11/2026) : la TVA de chaque ligne de
     * 25,00 €, 25,00 x 20 ÷ 120 = 4,1666... -> 4,17 €, fait 8,34 € pour une
     * TVA du taux de 50,00 x 20 ÷ 120 = 8,333... -> 8,33 € ; les deux
     * lignes ont le même écart d'arrondi et le même TTC, la première rend
     * le centime : 20,84 € HT et 4,16 € de TVA, puis 20,83 € et 4,17 €.
     */
    public function test_le_meme_total_donne_les_memes_montants_quel_que_soit_le_decoupage(): void
    {
        $enUneLigne = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(5000)]);
        $enDeuxArticles = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(2500, 2)]);
        $enDeuxLignes = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(2500), self::ligneDeVente(2500)]);

        $this->assertSame([4167, 833, 5000], self::triplet($enUneLigne['total']));
        $this->assertSame([4167, 833, 5000], self::triplet($enDeuxArticles['total']));
        $this->assertSame([4167, 833, 5000], self::triplet($enDeuxLignes['total']));
        $this->assertSame([[2084, 416, 2500], [2083, 417, 2500]], self::triplets($enDeuxLignes['lignes']));
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
     * Le client paie toujours la somme des prix affichés : 9,99 € (TVA
     * 1,665 -> 1,67 €, 8,32 € HT), 3 x 0,99 € = 2,97 € (TVA 0,495 -> 0,50 €,
     * 2,47 € HT), et trois lignes de 0,10 €, 0,30 € : la TVA de chaque ligne,
     * 0,0166... -> 0,02 €, fait 0,06 € pour une TVA du taux de 0,05 €, et la
     * première ligne rend le centime (0,09 € HT et 0,01 € de TVA), soit
     * 0,25 € HT et 0,05 € de TVA.
     */
    public function test_le_ttc_paye_est_toujours_la_somme_des_prix(): void
    {
        $calculateur = $this->calculateur();
        $troisLignes = $calculateur->chiffrerUneVente([self::ligneDeVente(10), self::ligneDeVente(10), self::ligneDeVente(10)]);

        $this->assertSame([832, 167, 999], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(999)])['total']));
        $this->assertSame([247, 50, 297], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(99, 3)])['total']));
        $this->assertSame([[9, 1, 10], [8, 2, 10], [8, 2, 10]], self::triplets($troisLignes['lignes']));
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
     * remisés de 15 % (4,4955 -> 4,50 €) font 25,47 € TTC, dont 25,47 x 20 ÷
     * 120 = 4,245 -> 4,25 € de TVA, et 21,22 € HT.
     */
    public function test_une_remise_s_arrondit_une_fois_sur_le_ttc_de_la_ligne(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([3750, 750, 4500], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(5000, 1, 2000, 1000)])['total']));
        $this->assertSame([2122, 425, 2547], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(999, 3, 2000, 1500)])['total']));
    }

    /**
     * « L'arrondi classique au centime » de l'éditeur, sur la TVA de la
     * ligne (décision de l'éditeur du 14/11/2026) : 2,97 € TTC à 20 %
     * comprennent 2,97 x 20 ÷ 120 = 0,495 € de TVA, arrondie au centime
     * supérieur (0,50 €, 2,47 € HT) ; 1,05 € en comprennent 0,175 € ->
     * 0,18 € (0,87 € HT) ; 9,99 €, 1,665 € -> 1,67 € (8,32 € HT, le PU HT
     * d'un article, voir prixUnitaireHtAffiche()).
     */
    public function test_un_demi_centime_exact_arrondit_la_tva_au_centime_superieur(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([247, 50, 297], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(297)])['total']));
        $this->assertSame([87, 18, 105], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(105)])['total']));
        $this->assertSame([832, 167, 999], self::triplet($calculateur->chiffrerUneVente([self::ligneDeVente(999)])['total']));
    }

    /**
     * Quand la somme des TVA des lignes d'un taux dépasse la TVA du taux, le
     * centime va à la ligne dont la TVA arrondie dépasse le plus sa TVA
     * exacte (décision de l'éditeur du 14/11/2026) : 0,10 €, 0,05 € puis
     * 0,10 € à 20 % ont pour TVA 0,0166... -> 0,02 €, 0,0083... -> 0,01 € et
     * 0,02 €, soit 0,05 € pour une TVA du taux de 0,25 x 20 ÷ 120 =
     * 0,0416... -> 0,04 € ; les lignes de 0,10 € dépassent leur TVA exacte de
     * 0,0033 € et celle de 0,05 € de 0,0017 € : la première ligne de 0,10 €
     * rend le centime (0,09 € HT, 0,01 € de TVA), la ligne de 0,05 € garde
     * 0,04 € HT et 0,01 € de TVA. Dans l'ordre 0,05 €, 0,10 €, 0,10 €, c'est
     * la deuxième ligne. Le taux fait toujours 0,21 € HT et 0,04 € de TVA.
     */
    public function test_le_centime_d_ecart_d_un_taux_va_a_la_ligne_dont_la_tva_est_la_plus_arrondie(): void
    {
        $calculateur = $this->calculateur();

        $dixCinqDix = $calculateur->chiffrerUneVente([self::ligneDeVente(10), self::ligneDeVente(5), self::ligneDeVente(10)]);
        $cinqDixDix = $calculateur->chiffrerUneVente([self::ligneDeVente(5), self::ligneDeVente(10), self::ligneDeVente(10)]);

        $this->assertSame([[9, 1, 10], [4, 1, 5], [8, 2, 10]], self::triplets($dixCinqDix['lignes']));
        $this->assertSame([[4, 1, 5], [9, 1, 10], [8, 2, 10]], self::triplets($cinqDixDix['lignes']));
        $this->assertSame([21, 4, 25], self::triplet($dixCinqDix['total']));
        $this->assertSame([21, 4, 25], self::triplet($cinqDixDix['total']));
    }

    /**
     * À égalité d'écart d'arrondi, le centime va à la ligne au plus gros
     * TTC, même si elle vient après : 0,10 € (TVA 0,0166... -> 0,02 €) et
     * 0,40 € (0,0666... -> 0,07 €) à 20 % dépassent tous deux leur TVA
     * exacte de 0,0033 € ; leurs 0,09 € de TVA dépassent celle du taux,
     * 0,50 x 20 ÷ 120 = 0,0833... -> 0,08 € : la ligne de 0,40 € rend le
     * centime (0,34 € HT, 0,06 € de TVA).
     */
    public function test_a_egalite_le_centime_va_a_la_ligne_au_plus_gros_ttc(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(10), self::ligneDeVente(40)]);

        $this->assertSame([[8, 2, 10], [34, 6, 40]], self::triplets($vente['lignes']));
        $this->assertSame([42, 8, 50], self::triplet($vente['total']));
    }

    /**
     * Quand la somme des TVA des lignes est sous la TVA du taux, la ligne
     * dont la TVA arrondie est la plus en dessous de sa TVA exacte reçoit
     * un centime : trois lignes de 1,03 € à 20 % ont chacune 1,03 x 20 ÷
     * 120 = 0,1716... -> 0,17 € de TVA, soit 0,51 €, pour une TVA du taux de
     * 3,09 x 20 ÷ 120 = 0,515 -> 0,52 € ; à égalité de tout, la première
     * reçoit le centime (0,85 € HT et 0,18 € de TVA).
     */
    public function test_une_somme_trop_basse_ajoute_un_centime_de_tva(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(103), self::ligneDeVente(103), self::ligneDeVente(103)]);

        $this->assertSame([[85, 18, 103], [86, 17, 103], [86, 17, 103]], self::triplets($vente['lignes']));
        $this->assertSame([257, 52, 309], self::triplet($vente['total']));
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
     * fois ; 3 x 0,99 € (2,47 € + 0,50 €) en 0,82 € + 0,17 € (TVA 0,165 ->
     * 0,17 €), puis 0,83 € + 0,16 € (1,98 € rendus : 0,33 € de TVA, 1,65 €
     * HT), puis 0,82 € + 0,17 € ; 3 x 9,99 € remisés de 15 % (21,22 € +
     * 4,25 €), un article (9,99 € moins 1,50 € de remise, 8,49 € : TVA
     * 1,415 -> 1,42 €, 7,07 € HT), puis les deux autres (décision de
     * l'éditeur du 14/11/2026).
     */
    public function test_les_retours_partiels_rendent_le_calcul_de_la_vente_encadre_par_la_ligne(): void
    {
        $cables = [...self::ligneDeVente(4000, 2), 'montant_ht' => 6667, 'montant_tva' => 1333];
        $stylos = [...self::ligneDeVente(99, 3), 'montant_ht' => 247, 'montant_tva' => 50];
        $piles = [...self::ligneDeVente(999, 3, 2000, 1500), 'montant_ht' => 2122, 'montant_tva' => 425];

        $this->assertSame([[3333, 667, 4000], [3334, 666, 4000]], $this->retoursDeVente($cables, [1, 2]));
        $this->assertSame([[82, 17, 99], [83, 16, 99], [82, 17, 99]], $this->retoursDeVente($stylos, [1, 2, 3]));
        $this->assertSame([[707, 142, 849], [1415, 283, 1698]], $this->retoursDeVente($piles, [1, 3]));
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
     * 4 x 0,01 € puis un article à 0,04 € à 20 % : chaque ligne a pour TVA
     * 0,04 x 20 ÷ 120 = 0,0066... -> 0,01 €, soit 0,02 € pour une TVA du
     * taux de 0,08 x 20 ÷ 120 = 0,0133... -> 0,01 € ; à égalité, la
     * première ligne rend le centime et n'a plus aucune TVA (décision de
     * l'éditeur du 14/11/2026). Trois de ses articles rendus (0,03 €)
     * vaudraient seuls 0,005 -> 0,01 € de TVA : le retour ne rend jamais
     * plus de TVA que la ligne, ici 0,03 € HT sans TVA, puis le dernier
     * article 0,01 €.
     */
    public function test_un_retour_ne_rend_jamais_plus_que_la_tva_de_la_ligne(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([self::ligneDeVente(1, 4), self::ligneDeVente(4)]);
        $ligne = [...self::ligneDeVente(1, 4), 'montant_ht' => 4, 'montant_tva' => 0];

        $this->assertSame([[4, 0, 4], [3, 1, 4]], self::triplets($vente['lignes']));
        $this->assertSame([[3, 0, 3], [1, 0, 1]], $this->retoursDeVente($ligne, [3, 4]));
    }

    /**
     * 10 articles à 0,02 € remisés de 25 % (0,20 € moins 0,05 €) font une
     * ligne de 0,15 € TTC : 0,025 -> 0,03 € de TVA et 0,12 € HT. Rendus un
     * par un, les articles rendus jusqu'ici coûtent 0,01 €, 0,03 €, 0,04 €,
     * 0,06 €... (la remise de chaque cumul arrondie une fois), dont la TVA,
     * le TTC ÷ 6, vaut 0,00 €, 0,005 -> 0,01 €, 0,0066... -> 0,01 €, 0,01
     * €... ; chaque retour en rend la différence avec les précédents :
     * jamais rien de négatif, et les dix retours retombent exactement sur
     * la ligne (décision de l'éditeur du 14/11/2026).
     */
    public function test_aucun_retour_ne_rend_un_montant_negatif(): void
    {
        $ligne = [...self::ligneDeVente(2, 10, 2000, 2500), 'montant_ht' => 12, 'montant_tva' => 3];

        $retours = $this->retoursDeVente($ligne, range(1, 10));

        $this->assertSame([[1, 0, 1], [1, 1, 2], [1, 0, 1], [2, 0, 2], [1, 0, 1], [1, 1, 2], [1, 0, 1], [2, 0, 2], [1, 0, 1], [1, 1, 2]], $retours);
        $this->assertSame([12, 3, 15], [array_sum(array_column($retours, 0)), array_sum(array_column($retours, 1)), array_sum(array_column($retours, 2))]);
    }

    /**
     * Un avoir ramène la TVA de chaque taux à celle de son TTC, d'un
     * centime sur une ligne rendue en partie (décision de Claude, D76) :
     * une facture de 2 x 25,00 € (41,67 € HT + 8,33 €) et d'un article à
     * 25,00 € (20,83 € + 4,17 €). Un avoir rend un des deux articles à
     * 25,00 € (TVA 4,1666... -> 4,17 €, 20,83 € HT) et l'article seul
     * (20,83 € + 4,17 €) : 8,34 € de TVA pour une TVA du taux de 50,00 x 20
     * ÷ 120 = 8,333... -> 8,33 € ; seule la ligne rendue en partie peut
     * recevoir le centime : 20,84 € HT et 4,16 €. Le second article de la
     * ligne, rendu ensuite, en solde le reste : 20,83 € + 4,17 €.
     */
    public function test_un_avoir_ramene_la_tva_de_son_taux_sur_une_ligne_rendue_en_partie(): void
    {
        $deuxArticles = [...self::ligneDeVente(2500, 2), 'montant_ht' => 4167, 'montant_tva' => 833];
        $unArticle = [...self::ligneDeVente(2500), 'montant_ht' => 2083, 'montant_tva' => 417];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $premier = $this->calculateur()->chiffrerUnAvoir([
            ['ligne' => $deuxArticles, 'quantite_rendue' => 1, 'deja_rendu' => $rienRendu],
            ['ligne' => $unArticle, 'quantite_rendue' => 1, 'deja_rendu' => $rienRendu],
        ]);
        $second = $this->calculateur()->chiffrerUnAvoir([
            ['ligne' => $deuxArticles, 'quantite_rendue' => 2, 'deja_rendu' => ['montant_ht' => 2084, 'montant_tva' => 416]],
        ]);

        $this->assertSame([[2084, 416, 2500], [2083, 417, 2500]], self::triplets($premier['lignes']));
        $this->assertSame([2000 => [4167, 833, 5000]], self::triplets($premier['taux']));
        $this->assertSame([[2083, 417, 2500]], self::triplets($second['lignes']));
    }

    /**
     * Un avoir qui ne rend que des lignes entières en garde les montants
     * (décision de Claude, D76) : deux lignes de 25,00 € à 20 % ont été
     * vendues 20,84 € + 4,16 € et 20,83 € + 4,17 €. L'avoir de la première
     * rend 20,84 € + 4,16 €, quand la TVA de ses 25,00 € serait 4,17 € :
     * aucune ligne rendue en partie ne peut recevoir le centime, l'avoir
     * garde cet écart d'un centime. L'avoir des deux lignes rend exactement
     * la facture : 41,67 € + 8,33 €.
     */
    public function test_un_avoir_qui_ne_rend_que_des_lignes_entieres_en_garde_les_montants(): void
    {
        $premiere = [...self::ligneDeVente(2500), 'montant_ht' => 2084, 'montant_tva' => 416];
        $seconde = [...self::ligneDeVente(2500), 'montant_ht' => 2083, 'montant_tva' => 417];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $dUneLigne = $this->calculateur()->chiffrerUnAvoir([['ligne' => $premiere, 'quantite_rendue' => 1, 'deja_rendu' => $rienRendu]]);
        $deToutLaFacture = $this->calculateur()->chiffrerUnAvoir([
            ['ligne' => $premiere, 'quantite_rendue' => 1, 'deja_rendu' => $rienRendu],
            ['ligne' => $seconde, 'quantite_rendue' => 1, 'deja_rendu' => $rienRendu],
        ]);

        $this->assertSame([2084, 416, 2500], self::triplet($dUneLigne['total']));
        $this->assertSame([[2084, 416, 2500], [2083, 417, 2500]], self::triplets($deToutLaFacture['lignes']));
        $this->assertSame([4167, 833, 5000], self::triplet($deToutLaFacture['total']));
    }

    /**
     * Après un centime d'ajustement, aucun retour n'est négatif : une
     * facture de 3 x 0,01 € (0,02 € HT + 0,01 € de TVA), 0,02 € (0,02 € HT,
     * sans TVA) et 0,01 € à 20 %. Un avoir rend un article de la première
     * ligne (0,01 € : 0,0016... -> 0,00 € de TVA) et la deuxième ligne :
     * aucune TVA pour une TVA du taux de 0,03 x 20 ÷ 120 = 0,005 -> 0,01 € ;
     * la ligne rendue en partie reçoit le centime (0,01 € de TVA, sans HT).
     * Le deuxième article rendu ensuite (0,02 € en tout : 0,0033... -> 0,00 €
     * de TVA) rendrait -0,01 € de TVA : il rend 0,01 € HT sans TVA, et le
     * dernier article solde la ligne.
     */
    public function test_apres_un_centime_d_ajustement_aucun_retour_ne_rend_un_montant_negatif(): void
    {
        $troisArticles = [...self::ligneDeVente(1, 3), 'montant_ht' => 2, 'montant_tva' => 1];
        $deuxCentimes = [...self::ligneDeVente(2), 'montant_ht' => 2, 'montant_tva' => 0];

        $avoir = $this->calculateur()->chiffrerUnAvoir([
            ['ligne' => $troisArticles, 'quantite_rendue' => 1, 'deja_rendu' => ['montant_ht' => 0, 'montant_tva' => 0]],
            ['ligne' => $deuxCentimes, 'quantite_rendue' => 1, 'deja_rendu' => ['montant_ht' => 0, 'montant_tva' => 0]],
        ]);

        $this->assertSame([[0, 1, 1], [2, 0, 2]], self::triplets($avoir['lignes']));
        $this->assertSame([1, 0, 1], self::triplet($this->calculateur()->retourDeLigne($troisArticles, 2, ['montant_ht' => 0, 'montant_tva' => 1])));
        $this->assertSame([1, 0, 1], self::triplet($this->calculateur()->retourDeLigne($troisArticles, 3, ['montant_ht' => 1, 'montant_tva' => 1])));
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

        $this->assertRefuse(fn () => $this->calculateur()->retourDeLigne($vendue, 0, $rienRendu), 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertRefuse(fn () => $this->calculateur()->retourDeLigne($vendue, 3, $rienRendu), 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertRefuse(fn () => $this->calculateur()->retourDAchat($achetee, 0, $rienRendu), 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertRefuse(fn () => $this->calculateur()->retourDAchat($achetee, 3, $rienRendu), 'Un retour rend entre 1 article et la quantité de la ligne.');
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
     * L'exemple du commentaire de classe (point 4) : la TVA qu'écrit le
     * fournisseur pour trois lignes de 10,01 € HT à 20 % (6,00 € au lieu
     * des 6,01 € proposés) se répartit au prorata de leur HT, par cumul :
     * l'écart de -1 centime vaut -0,33, -0,67 puis -1 centime au cumul,
     * arrondis à 0, -1 puis -1, et seule la deuxième ligne perd un centime
     * (2,00 € + 1,99 € + 2,01 €). La TVA proposée du taux reste pour la
     * preuve.
     */
    public function test_la_tva_ecrite_par_le_fournisseur_se_repartit_par_cumul_au_prorata_du_ht(): void
    {
        $achat = $this->calculateur()->chiffrerUnAchat([self::ligneDAchat(1001), self::ligneDAchat(1001), self::ligneDAchat(1001)], [2000 => 600]);

        $this->assertSame([[1001, 200, 1201], [1001, 199, 1200], [1001, 201, 1202]], self::triplets($achat['lignes']));
        $this->assertSame([3003, 600, 3603, 601], [...self::triplet($achat['taux'][2000]), $achat['taux'][2000]['montant_tva_calcule']]);
    }

    /**
     * Ce que la dernière ligne d'un taux ne peut pas recevoir remonte sur
     * les précédentes : deux lignes de 10,00 € HT, la première proposée
     * sans TVA, la seconde avec 10,00 € (tout son HT, ce qu'un retour d'une
     * ligne d'achat ainsi retenue propose), et 15,00 € écrits sur la note
     * d'avoir. L'écart de 5,00 € donne 2,50 € à chacune ; la seconde, déjà
     * à son HT, n'en prend rien, et ses 2,50 € reviennent à la première :
     * 5,00 € + 10,00 €. Aucune ligne ne dépasse son HT, et le taux garde
     * exactement la TVA écrite.
     */
    public function test_ce_que_la_derniere_ligne_ne_peut_recevoir_remonte_sur_les_precedentes(): void
    {
        $lignes = $this->calculateur()->repartirUneTvaRetenue([
            'premiere' => ['taux' => 2000, 'montant_ht' => 1000, 'montant_tva' => 0],
            'seconde' => ['taux' => 2000, 'montant_ht' => 1000, 'montant_tva' => 1000],
            'autre taux' => ['taux' => 550, 'montant_ht' => 1000, 'montant_tva' => 55],
        ], 2000, 1500);

        $this->assertSame(['premiere' => [1000, 500, 1500], 'seconde' => [1000, 1000, 2000], 'autre taux' => [1000, 55, 1055]], array_map(self::triplet(...), $lignes));
    }

    /**
     * Le HT qu'écrit la note d'avoir du fournisseur remplace la proposition
     * d'un retour, gardée à côté, et la TVA proposée suit ce HT : 2 des 5
     * articles d'une ligne de 100,00 € HT et 20,00 € de TVA, proposés
     * 40,00 € + 8,00 €, écrits 5,00 € HT, proposent 1,00 € de TVA, écrits
     * 45,00 €, 9,00 € ; un article de plus, écrit 20,00 €, part du HT déjà
     * retenu (25,00 € au cumul : 5,00 € de TVA, moins 1,00 € déjà rendu).
     * Cette TVA ne dépasse jamais celle de la ligne (150,00 € écrits en
     * proposent 20,00 €), ni le HT du retour quand un retour précédent a
     * rendu peu de TVA (2,00 € écrits après 59,00 € rendus sans TVA en
     * proposent 2,00 €). Un HT écrit égal à la proposition la garde : la
     * dernière de trois lignes de 10,01 € HT, qui a reçu 2,01 € de TVA, la
     * rend tout entière avec 10,01 € écrits, et 2,00 € avec 10,00 €. Un HT
     * écrit négatif est refusé.
     */
    public function test_le_ht_qu_ecrit_la_note_d_avoir_fait_la_tva_proposee_d_un_retour_au_fournisseur(): void
    {
        $calculateur = $this->calculateur();
        $cables = [...self::ligneDAchat(2000, 5), 'montant_ht' => 10000, 'montant_tva' => 2000];
        $rienRendu = ['montant_ht' => 0, 'montant_tva' => 0];

        $this->assertSame(['montant_ht' => 4000, 'montant_tva' => 800, 'montant_ttc' => 4800, 'montant_ht_calcule' => 4000], $calculateur->retourDAchat($cables, 2, $rienRendu));
        $this->assertSame(['montant_ht' => 500, 'montant_tva' => 100, 'montant_ttc' => 600, 'montant_ht_calcule' => 4000], $calculateur->retourDAchat($cables, 2, $rienRendu, 500));
        $this->assertSame(['montant_ht' => 4500, 'montant_tva' => 900, 'montant_ttc' => 5400, 'montant_ht_calcule' => 4000], $calculateur->retourDAchat($cables, 2, $rienRendu, 4500));
        $this->assertSame([2000, 400, 2400], self::triplet($calculateur->retourDAchat($cables, 3, ['montant_ht' => 500, 'montant_tva' => 100], 2000)));
        $this->assertSame([15000, 2000, 17000], self::triplet($calculateur->retourDAchat($cables, 2, $rienRendu, 15000)));
        $this->assertSame([200, 200, 400], self::triplet($calculateur->retourDAchat($cables, 3, ['montant_ht' => 5900, 'montant_tva' => 0], 200)));

        $derniere = [...self::ligneDAchat(1001), 'montant_ht' => 1001, 'montant_tva' => 201];
        $this->assertSame([1001, 201, 1202], self::triplet($calculateur->retourDAchat($derniere, 1, $rienRendu, 1001)));
        $this->assertSame([1000, 200, 1200], self::triplet($calculateur->retourDAchat($derniere, 1, $rienRendu, 1000)));

        $this->assertRefuse(fn () => $calculateur->retourDAchat($cables, 2, $rienRendu, -100), CalculateurLigne::REFUS_HT_NEGATIF);
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

        $this->assertRefuse(fn () => $calculateur->chiffrerUneVente([[...self::ligneDeVente(2500), 'prix_unitaire_ttc' => 25.0]]), $message);
        $this->assertRefuse(fn () => $calculateur->chiffrerUnAchat([[...self::ligneDAchat(1001), 'taux_tva' => 20.0]]), $message);
        $this->assertRefuse(fn () => $calculateur->retourDeLigne([...self::ligneDeVente(4000, 2), 'montant_ht' => 66.67, 'montant_tva' => 1333], 1, $rienRendu), $message);
        $this->assertRefuse(fn () => $calculateur->retourDAchat([...self::ligneDAchat(1001, 2), 'montant_ht' => 2002, 'montant_tva' => 400], 1, ['montant_ht' => 10.01, 'montant_tva' => 0]), $message);
    }

    /**
     * Le PU HT d'un article vendu, que PILIQI affiche partout (décision de
     * l'éditeur du 14/11/2026) : son PU TTC moins sa TVA arrondie une fois
     * au centime, un demi-centime au centime supérieur. 9,99 € TTC à 20 % :
     * TVA 1,665 €, soit 1,67 €, PU HT 8,32 € (et non 8,325 € arrondi à
     * 8,33 €) ; 10,00 € : 8,33 € ; 0,99 € : 0,82 € ; 40,00 € : 33,33 € ;
     * 10,00 € à 5,5 % : TVA 0,5213... €, 9,48 € ; 10,00 € à 10 % : TVA
     * 0,909... €, 9,09 € ; 0,35 € à 5,5 % : TVA 0,01824... €, 0,33 €.
     */
    public function test_le_pu_ht_affiche_d_une_vente_est_le_pu_ttc_moins_la_tva_d_un_article_arrondie(): void
    {
        $this->assertSame([832, 833, 82, 3333], [
            $this->calculateur()->prixUnitaireHtAffiche(999, 2000),
            $this->calculateur()->prixUnitaireHtAffiche(1000, 2000),
            $this->calculateur()->prixUnitaireHtAffiche(99, 2000),
            $this->calculateur()->prixUnitaireHtAffiche(4000, 2000),
        ]);
        $this->assertSame([948, 909, 33], [
            $this->calculateur()->prixUnitaireHtAffiche(1000, 550),
            $this->calculateur()->prixUnitaireHtAffiche(1000, 1000),
            $this->calculateur()->prixUnitaireHtAffiche(35, 550),
        ]);
        $this->assertSame('8,32 €', $this->calculateur()->prixUnitaireHtAfficheEnEuros(999, 2000));
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
     * Un PU HT au millionième se lit dans sa colonne decimal(18,6) et
     * s'écrit comme elle ; le champ d'une ligne de vente, où il ne se saisit
     * pas, le montre arrondi au centime, comme tout prix unitaire montré en
     * lecture (décision de l'éditeur du 25/10/2026), un demi-centime exact
     * au centime supérieur : 54.17 pour 54,166667 €, 8.33 pour 8,325 € ;
     * un flottant ou une septième décimale ne se lit pas.
     */
    public function test_un_pu_ht_au_millionieme_se_lit_et_s_ecrit_comme_sa_colonne_et_se_montre_au_centime_dans_un_champ(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame([833333, 10010000, 12000000], [$calculateur->enMillioniemes('0.833333'), $calculateur->enMillioniemes('10.01'), $calculateur->enMillioniemes(12)]);
        $this->assertSame(['0.833333', '10.000000'], [$calculateur->enDecimalAuMillionieme(833333), $calculateur->enDecimalAuMillionieme(10000000)]);
        $this->assertSame(
            ['0.83', '8.33', '8.32', '12.00', '54.17'],
            array_map($calculateur->prixUnitaireDansUnChamp(...), [833333, 8325000, 8324999, 12000000, 54166667]),
        );
        foreach ([0.833333, '0.8333333', '1,5'] as $illisible) {
            $this->assertRefuse(fn () => $calculateur->enMillioniemes($illisible), 'Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
        }
    }

    /**
     * Tout prix unitaire s'affiche arrondi au centime le plus proche, un
     * demi-centime exact au centime supérieur (décision de l'éditeur du
     * 25/10/2026) : 54,166667 € (65,00 € TTC à 20 %) s'affiche 54,17 € ;
     * 8,325 €, 8,33 € ; 8,324999 €, 8,32 € ; 0,833333 €, 0,83 € ; un coût
     * moyen de 1,0050 €, 1,01 €. Un prix au centime reste le même, et un
     * prix négatif s'arrondit comme un montant, symétriquement : -8,325 €
     * s'affiche -8,33 €.
     */
    public function test_un_prix_unitaire_s_affiche_au_centime_un_demi_centime_au_centime_superieur(): void
    {
        $calculateur = $this->calculateur();

        $this->assertSame(
            ['54,17 €', '8,33 €', '8,32 €', '0,83 €', '1,01 €', '1 000,00 €', '-8,33 €'],
            array_map($calculateur->prixUnitaireAffiche(...), [54166667, 8325000, 8324999, 833333, 1005000, 1000000000, -8325000]),
        );
        $this->assertSame([5417, 833, 833], [$calculateur->prixUnitaireAuCentime(54166667), $calculateur->prixUnitaireAuCentime(8325000), $calculateur->prixUnitaireAuCentime(8334999)]);
    }

    /**
     * Un PU HT au millionième s'écrit tel qu'il est enregistré, jusqu'à six
     * décimales, sans zéro inutile au-delà de deux, les milliers séparés
     * par une espace comme tout montant : le PU HT d'un achat, que le
     * fournisseur écrit, et le coût unitaire d'un inventaire d'ouverture
     * (le PU HT d'une vente, lui, s'affiche comme celui d'un article, voir
     * prixUnitaireHtAffiche(), décision de l'éditeur du 14/11/2026). Les
     * sept prix exemples, de 10,00 €, 9,99 €, 12,66 €, 1 249,00 € et
     * 54,00 € TTC à 20 %, de 15,90 € TTC à 5,5 % et de 0,99 € TTC à 20 %,
     * donnent des millionièmes de toutes les formes. Un champ qui le montre
     * l'écrit de même, avec un point et sans espace.
     */
    public function test_un_pu_ht_s_affiche_tel_qu_il_est_enregistre_sans_zero_inutile_au_dela_de_deux_decimales(): void
    {
        $calculateur = $this->calculateur();
        $millioniemes = [
            $calculateur->prixUnitaireHtExact(1000, 2000),
            $calculateur->prixUnitaireHtExact(999, 2000),
            $calculateur->prixUnitaireHtExact(1266, 2000),
            $calculateur->prixUnitaireHtExact(1590, 550),
            $calculateur->prixUnitaireHtExact(99, 2000),
            $calculateur->prixUnitaireHtExact(124900, 2000),
            $calculateur->prixUnitaireHtExact(5400, 2000),
        ];

        $this->assertSame([8333333, 8325000, 10550000, 15071090, 825000, 1040833333, 45000000], $millioniemes);
        $this->assertSame(
            ['8,333333 €', '8,325 €', '10,55 €', '15,07109 €', '0,825 €', '1 040,833333 €', '45,00 €'],
            array_map($calculateur->prixUnitaireExact(...), $millioniemes),
        );
        $this->assertSame(
            ['8.333333', '8.325', '10.55', '15.07109', '0.825', '1040.833333', '45.00'],
            array_map($calculateur->prixUnitaireExactEnDecimal(...), $millioniemes),
        );
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33. Un montant saisi se lit au centime
     * par ses chiffres (voir SaisieDecimale), écrit en texte comme transmis
     * en flottant par un écran.
     */
    public function test_un_montant_saisi_s_arrondit_au_centime_sans_le_piege_du_flottant(): void
    {
        $this->assertSame(833, SaisieDecimale::enCentiemes('8.325'));
        $this->assertSame(833, SaisieDecimale::enCentiemes(8.325));
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $total = $this->calculateur()->totaliser(array_fill(0, 1000, ['montant_ht' => '0.08', 'montant_tva' => '0.02', 'montant_ttc' => '0.10']));

        $this->assertSame(10000, $total['total_ttc']);
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
            'taux négatif (TTC vers HT)' => [fn (CalculateurLigne $c) => $c->prixUnitaireHtAffiche(1000, -10000), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux négatif (HT vers TTC)' => [fn (CalculateurLigne $c) => $c->prixUnitaireTtcDepuisHt(1000, -500), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (achat)' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(1000, 1, 15000)]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (prix de vente)' => [fn (CalculateurLigne $c) => $c->prixTtcArrondi(100000, 15000), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise négative' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1200, 1, 2000, -1000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'remise au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(1000, 1, 2000, 15000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'escompte au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->coutUnitaireNet(10000000, 0, 15000), 'La remise doit être comprise entre 0 et 100 %.'],
            'montant reçu absurde' => [fn (CalculateurLigne $c) => $c->sommer(['100000000000000000', '40']), 'Montant hors limites.'],
            'paiement absurde' => [fn (CalculateurLigne $c) => SaisieDecimale::enCentiemes(1e17), 'Montant hors limites.'],
            'prix x quantité qui déborde (achat)' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(999999999999, 999999)]), 'Montant hors limites.'],
            'taux au-delà de 100 % (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1000, 1, 15000)]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise au-delà de 100 % (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1000, 1, 2000, 15000)]), 'La remise doit être comprise entre 0 et 100 %.'],
            'prix x quantité qui déborde (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(999999999999, 999999)]), 'Montant hors limites.'],
            'taux négatif (retour au fournisseur)' => [fn (CalculateurLigne $c) => $c->retourDAchat([...self::ligneDAchat(1000, 2, -500), 'montant_ht' => 2000, 'montant_tva' => 0], 1, ['montant_ht' => 0, 'montant_tva' => 0]), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'prix négatif (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(-100)]), 'Un prix unitaire ne peut pas être négatif.'],
            'taux facturé qui n\'est pas celui du prix' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([[...self::ligneDeVente(1000), 'taux_tva' => 550]]), 'Une ligne facture le taux que comprend son prix, ou aucun.'],
            'quantité nulle (vente)' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(1000, 0)]), 'La quantité doit être un nombre entier d\'articles, au moins 1.'],
            'prix de vente hors limites' => [fn (CalculateurLigne $c) => $c->chiffrerUneVente([self::ligneDeVente(100_000_000_000_000)]), 'Montant hors limites.'],
            'prix d\'achat à virgule' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([[...self::ligneDAchat(1000), 'prix_unitaire_ht' => 10000000.5]]), 'jamais un nombre à virgule'],
            'prix d\'achat négatif' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([self::ligneDAchat(-1000)]), 'Un prix unitaire ne peut pas être négatif.'],
            'prix d\'achat hors limites' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([[...self::ligneDAchat(1000), 'prix_unitaire_ht' => 1_000_000_000_000_000_000]]), 'Montant hors limites.'],
            'prix d\'achat x quantité au-delà des millionièmes' => [fn (CalculateurLigne $c) => $c->chiffrerUnAchat([[...self::ligneDAchat(1000, 20), 'prix_unitaire_ht' => 100_000_000_000_000_000]]), 'Montant hors limites.'],
            'millionièmes d\'un entier hors limites' => [fn (CalculateurLigne $c) => $c->enMillioniemes(1_000_000_000_000), 'Montant hors limites.'],
            'millionièmes d\'une écriture de treize chiffres' => [fn (CalculateurLigne $c) => $c->enMillioniemes('1000000000000.5'), 'Montant hors limites.'],
            'décimal au millionième hors limites' => [fn (CalculateurLigne $c) => $c->enDecimalAuMillionieme(1_000_000_000_000_000_000), 'Montant hors limites.'],
            'prix au centime hors limites' => [fn (CalculateurLigne $c) => $c->prixUnitaireAuCentime(-1_000_000_000_000_000_000), 'Montant hors limites.'],
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
        $this->assertSame(1000, $this->calculateur()->prixUnitaireHtAffiche(1000, 0));
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
        // PU HT en millionièmes d'euro, remise et escompte en centièmes de
        // point, coût en dix-millièmes d'euro.
        $this->assertSame(10030, $this->calculateur()->coutUnitaireNet(1180000, 1500));
        $this->assertSame(73471, $this->calculateur()->coutUnitaireNet(8330000, 1000, 200));
        $this->assertSame(882000, $this->calculateur()->coutUnitaireNet(90000000, 0, 200));
        $this->assertSame(0, $this->calculateur()->coutUnitaireNet(90000000, 10000));
        $this->assertSame(99999999999900, $this->calculateur()->coutUnitaireNet(9999999999990000, 0));
        $this->assertSame(80749999999919, $this->calculateur()->coutUnitaireNet(9999999999990000, 1500, 500));
    }

    /**
     * Le bénéfice d'un article se calcule depuis son coût exact et ne
     * s'arrondit qu'une fois : 11,00 € - 1,005 € = 9,995 € -> 10,00 €, là
     * où 11,00 € - 1,01 € donnerait 9,99 €.
     */
    public function test_le_benefice_dun_article_ne_sarrondit_qu_une_fois(): void
    {
        // Prix de vente HT en centimes, coût en dix-millièmes d'euro,
        // bénéfice en centimes.
        $this->assertSame(1000, $this->calculateur()->beneficeUnitaire(1100, 10050));
        $this->assertSame(953, $this->calculateur()->beneficeUnitaire(1000, 4700));
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
        // Prix HT en dix-millièmes d'euro, taux en centièmes de point, prix
        // TTC en centimes.
        $this->assertSame(121, $this->calculateur()->prixTtcArrondi(10042, 2000));
        $this->assertSame(6000, $this->calculateur()->prixTtcArrondi(541663, 2000, pasCentimes: 1000));
        $this->assertSame(7000, $this->calculateur()->prixTtcArrondi(541667, 2000, pasCentimes: 1000));
    }

    /**
     * Le coût moyen de lots se pondère par leurs quantités, au dix-millième :
     * 10 à 2 € et 20 à 3,5 € coûtent 3 € ; 3 à 1 € et 4 à 2 €, 1,5714 €. Des
     * lots offerts coûtent 0 €, et des lots sans aucun article n'ont pas de
     * coût moyen.
     */
    public function test_le_cout_moyen_pondere_compte_les_lots_offerts_a_zero(): void
    {
        // Coûts en dix-millièmes d'euro.
        $this->assertSame(30000, $this->calculateur()->coutMoyenPondere([['quantite' => 10, 'cout_unitaire' => 20000], ['quantite' => 20, 'cout_unitaire' => 35000]]));
        $this->assertSame(15714, $this->calculateur()->coutMoyenPondere([['quantite' => 3, 'cout_unitaire' => 10000], ['quantite' => 4, 'cout_unitaire' => 20000]]));
        $this->assertSame(0, $this->calculateur()->coutMoyenPondere([['quantite' => 0, 'cout_unitaire' => 50000], ['quantite' => 10, 'cout_unitaire' => 0]]));
        $this->assertNull($this->calculateur()->coutMoyenPondere([['quantite' => 0, 'cout_unitaire' => 50000]]));
        $this->assertNull($this->calculateur()->coutMoyenPondere([]));
    }

    public function test_un_lot_de_quantite_ou_de_cout_negatif_est_refuse(): void
    {
        $this->assertRefuse(
            fn () => $this->calculateur()->coutMoyenPondere([['quantite' => -1, 'cout_unitaire' => 50000], ['quantite' => 10, 'cout_unitaire' => 50000]]),
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
        $this->assertRefuse(
            fn () => $this->calculateur()->coutMoyenPondere([['quantite' => 10, 'cout_unitaire' => -5000]]),
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
    }

    /**
     * Le coût moyen du stock restant après un retour au fournisseur, au
     * coût de la ligne retournée : (stock x coût moyen - quantité x coût) /
     * (stock - quantité), au dix-millième, arrondi une seule fois. Sans
     * stock restant, aucun ; une valeur restante négative vaut 0 €. Coûts
     * en dix-millièmes d'euro.
     *
     * @return array<string, array{int, int, int, int, int|null}>
     */
    public static function sortiesAUnAutreCout(): array
    {
        return [
            'les 10 articles à 10 € d\'un stock de 20 à 6 €' => [20, 60000, 10, 100000, 20000],
            '4 articles à 10 € d\'un stock de 20 à 6 €' => [20, 60000, 4, 100000, 50000],
            '8,9998 € pour 6 articles' => [7, 15714, 1, 20000, 15000],
            'tout le stock' => [5, 30000, 5, 30000, null],
            'plus que le stock' => [5, 30000, 6, 30000, null],
            'une valeur restante de -10 €' => [5, 60000, 4, 100000, 0],
        ];
    }

    #[DataProvider('sortiesAUnAutreCout')]
    public function test_le_cout_moyen_apres_une_sortie_a_un_autre_cout(int $stock, int $coutMoyen, int $quantite, int $coutUnitaire, ?int $attendu): void
    {
        $this->assertSame($attendu, $this->calculateur()->coutMoyenApresUneSortie($stock, $coutMoyen, $quantite, $coutUnitaire));
    }

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function sortiesImpossibles(): array
    {
        return [
            'stock négatif' => [-1, 60000, 1, 100000],
            'quantité négative' => [20, 60000, -1, 100000],
            'coût moyen négatif' => [20, -60000, 1, 100000],
            'coût de sortie négatif' => [20, 60000, 1, -100000],
        ];
    }

    #[DataProvider('sortiesImpossibles')]
    public function test_une_sortie_de_quantite_ou_de_cout_negatif_est_refusee(int $stock, int $coutMoyen, int $quantite, int $coutUnitaire): void
    {
        $this->assertRefuse(
            fn () => $this->calculateur()->coutMoyenApresUneSortie($stock, $coutMoyen, $quantite, $coutUnitaire),
            'Un lot de stock ne peut avoir ni quantité ni coût négatif.',
        );
    }

    /**
     * Une somme de montants écrits comme leurs colonnes se fait en centimes
     * entiers : 0,10 + 0,20 font exactement 0,30 € (0.1 + 0.2 vaudrait
     * 0.30000000000000004 en flottants) ; un entier compte des euros ; rien
     * ou un texte vide comptent zéro. Une différence se fait sur ces
     * centimes.
     */
    public function test_sommer_compte_au_centime_exact(): void
    {
        $this->assertSame(30, $this->calculateur()->sommer(['0.10', '0.20']));
        $this->assertSame(10000, $this->calculateur()->sommer(array_fill(0, 1000, '0.10')));
        $this->assertSame(1667, $this->calculateur()->sommer([50]) - $this->calculateur()->sommer(['33.33']));
        $this->assertSame(-1000, $this->calculateur()->sommer(['40.00']) - $this->calculateur()->sommer(['30.00', 20]));
        $this->assertSame(0, $this->calculateur()->sommer([null, '']));
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
        $this->assertRefuse(fn () => $this->calculateur()->enCentiemes('1000000000000.00'), 'Montant hors limites.');
        $this->assertRefuse(fn () => $this->calculateur()->enCentiemes('-99999999999999999999.00'), 'Montant hors limites.');
        $this->assertRefuse(fn () => $this->calculateur()->enCentiemes(1_000_000_000_000), 'Montant hors limites.');
        $this->assertRefuse(fn () => $this->calculateur()->enCentiemes(PHP_INT_MAX), 'Montant hors limites.');
    }

    public function test_des_centiemes_s_ecrivent_comme_une_colonne_decimale(): void
    {
        $this->assertSame('120.00', $this->calculateur()->enDecimal(12000));
        $this->assertSame('-0.05', $this->calculateur()->enDecimal(-5));
        $this->assertSame('0.07', $this->calculateur()->enDecimal(7));
        $this->assertSame('0.00', $this->calculateur()->enDecimal(0));
        $this->assertSame('-12.30', $this->calculateur()->enDecimal(-1230));
        $this->assertSame('9999999999.99', $this->calculateur()->enDecimal(999999999999));
        $this->assertRefuse(fn () => $this->calculateur()->enDecimal(PHP_INT_MIN), 'Montant hors limites.');
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
        $this->assertRefuse(fn () => $this->calculateur()->enEuros(PHP_INT_MIN), 'Montant hors limites.');
    }

    /**
     * T107.1 : un montant se répartit au prorata des poids, en cumul dans
     * leur ordre, chaque part arrondie au centime le plus proche ; 50,02 €
     * sur 120,00 € puis 40,00 € font 37,52 € (37,515 au centime supérieur)
     * et 12,50 €, et la somme des parts égale toujours le montant, même
     * quand chaque part arrondie seule la dépasserait (3 x 33,33 % de
     * 1,00 € font 0,33 + 0,34 + 0,33).
     */
    public function test_un_montant_se_repartit_au_prorata_en_cumul(): void
    {
        $this->assertSame(['service_20' => 3752, 'service_10' => 1250], $this->calculateur()->repartirAuProrata(5002, ['service_20' => 12000, 'service_10' => 4000]));
        $this->assertSame([33, 34, 33], $this->calculateur()->repartirAuProrata(100, [1, 1, 1]));
        $this->assertSame([2551, 449], $this->calculateur()->repartirAuProrata(3000, [6000, 1055]));
    }

    /**
     * Sans poids, aucune part ; un poids négatif est refusé.
     */
    public function test_une_repartition_sans_poids_ne_donne_rien_et_un_poids_negatif_est_refuse(): void
    {
        $this->assertSame(['a' => 0, 'b' => 0], $this->calculateur()->repartirAuProrata(5000, ['a' => 0, 'b' => 0]));
        $this->assertSame([], $this->calculateur()->repartirAuProrata(5000, []));
        $this->assertRefuse(fn () => $this->calculateur()->repartirAuProrata(100, [100, -1]), 'Un poids de la répartition ne peut pas être négatif.');
    }

    /**
     * Les 15 lignes de l'exemple montré à l'éditeur (exemple-calcul-des-prix-15-lignes-regle-b2.pdf
     * et modèle M6-b, décision de l'éditeur du 14/11/2026) : la TVA de
     * chaque ligne est son TTC x taux ÷ (1 + taux), arrondie une fois.
     *
     * - À 20 %, les TVA arrondies des lignes font 3,33 + 1,67 + 6,33 +
     *   166,67 + 3,00 + 27,00 + 43,33 + 4,13 + 23,73 + 208,17 = 487,36 €,
     *   pour une TVA du taux de 2 924,12 x 20 ÷ 120 = 487,353... -> 487,35 € :
     *   le savon (1,665 -> 1,67 €) et les stylos (4,125 -> 4,13 €) dépassent
     *   le plus leur TVA exacte, d'un demi-centime chacun ; à égalité, les
     *   stylos, au plus gros TTC (24,75 € contre 9,99 €), rendent le
     *   centime : 4,12 € de TVA et 20,63 € HT.
     * - À 10 %, 6,73 + 2,10 + 2,03 = 10,86 €, la TVA de 119,45 € (10,859...
     *   -> 10,86 €) : rien à ajuster.
     * - À 5,5 %, 1,66 + 0,84 = 2,50 €, la TVA de 48,00 € (2,502... -> 2,50 €) :
     *   rien à ajuster.
     *
     * Le document fait 2 590,86 € HT, 500,71 € de TVA et 3 091,57 € TTC.
     */
    public function test_les_quinze_lignes_de_l_exemple_montre_a_l_editeur(): void
    {
        $vente = $this->calculateur()->chiffrerUneVente([
            self::ligneDeVente(1000, 2),
            self::ligneDeVente(999),
            self::ligneDeVente(1266, 3),
            self::ligneDeVente(1000, 100),
            self::ligneDeVente(1590, 2, 550),
            self::ligneDeVente(1850, 4, 1000),
            self::ligneDeVente(1000, 2, 2000, 1000),
            self::ligneDeVente(5400, 3),
            self::ligneDeVente(6500, 4),
            self::ligneDeVente(135, 12, 550),
            self::ligneDeVente(330, 7, 1000),
            self::ligneDeVente(99, 25),
            self::ligneDeVente(14990, 1, 2000, 500),
            self::ligneDeVente(124900),
            self::ligneDeVente(745, 3, 1000),
        ]);

        $this->assertSame([
            [1667, 333, 2000],
            [832, 167, 999],
            [3165, 633, 3798],
            [83333, 16667, 100000],
            [3014, 166, 3180],
            [6727, 673, 7400],
            [1500, 300, 1800],
            [13500, 2700, 16200],
            [21667, 4333, 26000],
            [1536, 84, 1620],
            [2100, 210, 2310],
            [2063, 412, 2475],
            [11867, 2373, 14240],
            [104083, 20817, 124900],
            [2032, 203, 2235],
        ], self::triplets($vente['lignes']));
        $this->assertSame([2000 => [243677, 48735, 292412], 550 => [4550, 250, 4800], 1000 => [10859, 1086, 11945]], self::triplets($vente['taux']));
        $this->assertSame([259086, 50071, 309157], self::triplet($vente['total']));
    }

    /**
     * Le PU HT enregistré, PU TTC ÷ (1 + taux) au millionième, reste celui
     * de la facture électronique (décision de l'éditeur du 14/11/2026) :
     * 100 x 10,00 € à 20 % font 166,67 € de TVA (166,666...) et 833,33 € HT,
     * et 100 x 8,333333 € = 833,3333 € ; même sur une ligne qui reçoit le
     * centime d'ajustement, la quantité x ce PU HT reste à moins de deux
     * centimes de son HT : 10 000 x 0,01 € (TVA 16,666... -> 16,67 €) et
     * 0,04 € (0,0066... -> 0,01 €) font 16,68 € de TVA pour une TVA du taux
     * de 100,04 x 20 ÷ 120 = 16,673... -> 16,67 € ; à égalité d'écart
     * d'arrondi, la ligne au plus gros TTC rend le centime : 83,34 € HT,
     * quand 10 000 x 0,008333 € font 83,33 €.
     */
    public function test_le_pu_ht_exact_retombe_a_moins_de_deux_centimes_meme_sur_une_ligne_ajustee(): void
    {
        $calculateur = $this->calculateur();
        $centBougies = $calculateur->chiffrerUneVente([self::ligneDeVente(1000, 100)]);
        $ajustee = $calculateur->chiffrerUneVente([self::ligneDeVente(1, 10000), self::ligneDeVente(4)]);

        $this->assertSame([83333, 16667, 100000], self::triplet($centBougies['lignes'][0]));
        $this->assertSame(8333333, $calculateur->prixUnitaireHtExact(1000, 2000));
        $this->assertTrue($calculateur->puHtRetombeSurLeHt(100, 8333333, 0, 83333));
        $this->assertSame([[8334, 1666, 10000], [3, 1, 4]], self::triplets($ajustee['lignes']));
        $this->assertSame(8333, $calculateur->prixUnitaireHtExact(1, 2000));
        $this->assertTrue($calculateur->puHtRetombeSurLeHt(10000, 8333, 0, 8334));
    }

    /**
     * T105 : la quantité x le PU HT, remise déduite, calculée exactement,
     * retombe-t-elle à moins de deux centimes du HT de la ligne ? 1 000 x
     * 0,833333 € = 833,333 € pour 833,33 € HT : oui ; 100 000 x 0,833333 €
     * = 83 333,30 € pour 83 333,33 € HT : non (3 centimes) ; 4 x 54,166667 €
     * moins 10 % = 195,0000012 € pour 195,00 € HT : oui ; la limite, 0,02 €
     * d'écart, n'y retombe plus.
     */
    public function test_le_pu_ht_retombe_sur_le_ht_de_la_ligne_a_moins_de_deux_centimes(): void
    {
        $this->assertTrue($this->calculateur()->puHtRetombeSurLeHt(1000, 833333, 0, 83333));
        $this->assertFalse($this->calculateur()->puHtRetombeSurLeHt(100000, 833333, 0, 8333333));
        $this->assertTrue($this->calculateur()->puHtRetombeSurLeHt(4, 54166667, 1000, 19500));
        $this->assertTrue($this->calculateur()->puHtRetombeSurLeHt(1, 1000000, 0, 101));
        $this->assertFalse($this->calculateur()->puHtRetombeSurLeHt(1, 1000000, 0, 102));
    }

    /**
     * Le PU HT qui retombe sur le HT d'une ligne : 694,44 € HT pour 100
     * articles font 6,9444 € ; 69 444,44 € pour 10 000, 6,944444 € ; 195,00
     * € HT pour 4 articles remisés de 10 %, 54,166667 € (54,1666...
     * arrondi au millionième), qui retombe sur la ligne ; 0,01 € pour 32
     * articles, 0,0003125 € arrondi au millionième supérieur ; une remise
     * de 100 % ne laisse aucun HT à diviser.
     */
    public function test_le_pu_ht_du_montant_d_une_ligne_retombe_sur_son_ht(): void
    {
        $this->assertSame([6944400, 6944444, 54166667, 313], [
            $this->calculateur()->prixUnitaireHtDuMontant(69444, 100, 0),
            $this->calculateur()->prixUnitaireHtDuMontant(6944444, 10000, 0),
            $this->calculateur()->prixUnitaireHtDuMontant(19500, 4, 1000),
            $this->calculateur()->prixUnitaireHtDuMontant(1, 32, 0),
        ]);
        $this->assertTrue($this->calculateur()->puHtRetombeSurLeHt(4, 54166667, 1000, 19500));
        $this->assertNull($this->calculateur()->prixUnitaireHtDuMontant(0, 3, 10000));
    }
}
