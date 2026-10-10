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
 * Calcul d'une ligne (voir le commentaire de classe de CalculateurLigne) :
 * une vente à un particulier part de son prix TTC, décomposé article par
 * article (PU HT et PU TTC au centime, multipliés séparément par la
 * quantité) ; une vente à un professionnel, du HT de la ligne, quantité x
 * PU TTC ÷ (1 + taux) arrondi une fois, la TVA de chaque taux se calculant
 * sur le total HT de ses lignes ; un achat part du PU HT du fournisseur,
 * multiplié par la quantité, puis la TVA se calcule une seule fois sur le
 * HT de la ligne. Tout se fait en centimes entiers, jamais en flottant.
 *
 * Au-delà des exemples nommés (cas réels signalés par un magasin), les tests
 * "sur_tous_les" et "aleatoires" comparent le calculateur, sur des dizaines
 * de milliers de cas, à une référence indépendante : l'arithmétique
 * décimale exacte de brick/math, arrondie au centime au demi supérieur. Le
 * hasard est tiré d'une graine fixe (GRAINE) : un échec se rejoue toujours
 * à l'identique, et son message donne le cas fautif.
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

    public function test_la_valeur_d_un_stock_au_cout_moyen_s_arrondit_une_seule_fois(): void
    {
        $calculateur = new CalculateurLigne;

        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $calculateur->valeurAuCoutMoyen(2000, 0.045, 20));
        $this->assertSame(['montant_ht' => 21.45, 'montant_tva' => 4.29, 'montant_ttc' => 25.74], $calculateur->valeurAuCoutMoyen(1, 21.4545, 20));
    }

    /**
     * 40,00 € TTC = 33,33 € HT + 6,67 € de TVA ; 10,00 € TTC à 5,5 % =
     * 9,48 € HT (10 / 1,055 = 9,4786...).
     */
    public function test_le_pu_ht_dune_vente_est_la_conversion_de_son_ttc_au_centime(): void
    {
        $this->assertSame(33.33, $this->calculateur()->prixUnitaireHtDepuisTtc(40, 20));
        $this->assertSame(9.48, $this->calculateur()->prixUnitaireHtDepuisTtc(10, 5.5));
    }

    /**
     * Chaque montant de la ligne se vérifie à la main : PU x quantité, et
     * HT + TVA = TTC.
     */
    public function test_une_vente_multiplie_separement_le_ht_et_le_ttc_par_la_quantite(): void
    {
        $this->assertSame(['montant_ht' => 66.66, 'montant_tva' => 13.34, 'montant_ttc' => 80.0], $this->calculateur()->montantsDeVente(33.33, 40, quantite: 2));
        $this->assertSame(['montant_ht' => 14.97, 'montant_tva' => 3.0, 'montant_ttc' => 17.97], $this->calculateur()->montantsDeVente(4.99, 5.99, quantite: 3));
    }

    /**
     * Facture d'achat signalée par le magasin : 10 x 1,18 € HT font 11,80 €
     * HT et 20 % de TVA sur ce HT, 2,36 € -- et non 10 x 1,42 € (1,18 € x
     * 1,2 = 1,416 € arrondi) = 14,20 €. 5 x 33,65 € HT, dont la TVA tombe
     * juste, ne change pas.
     */
    public function test_un_achat_calcule_sa_tva_sur_le_ht_de_la_ligne(): void
    {
        $calculateur = $this->calculateur();

        $coques = $calculateur->montantsDAchat(1.18, 20, quantite: 10);
        $ecrans = $calculateur->montantsDAchat(33.65, 20, quantite: 5);

        $this->assertSame(['montant_ht' => 11.8, 'montant_tva' => 2.36, 'montant_ttc' => 14.16], $coques);
        $this->assertSame(['montant_ht' => 168.25, 'montant_tva' => 33.65, 'montant_ttc' => 201.9], $ecrans);
        $this->assertSame(['total_ht' => 180.05, 'total_tva' => 36.01, 'total_ttc' => 216.06], $calculateur->totaliser([$coques, $ecrans]));
        $this->assertSame(['montant_ht' => 14.97, 'montant_tva' => 2.99, 'montant_ttc' => 17.96], $calculateur->montantsDAchat(4.99, 20, quantite: 3));
    }

    /**
     * Facture signalée par le magasin : 2 x 40 € TTC (20 %), 10 € sans TVA
     * et 10 € TTC (20 %). Le total HT est la somme visible des PU HT x
     * quantité (84,99 €), et HT + TVA = TTC.
     */
    public function test_la_facture_du_magasin_totalise_84_99_ht_15_01_tva_100_ttc(): void
    {
        $calculateur = $this->calculateur();

        $totaux = $calculateur->totaliser([
            $calculateur->montantsDeVente(33.33, 40, 2),
            $calculateur->montantsDeVente(10, 10, 1),
            $calculateur->montantsDeVente(8.33, 10, 1),
        ]);

        $this->assertSame(['total_ht' => 84.99, 'total_tva' => 15.01, 'total_ttc' => 100.0], $totaux);
    }

    /**
     * Remise : 2 x 10 € TTC (8,33 € HT) remisés de 10 % = 14,99 € HT
     * (1,666 € de remise -> 1,67 €), mais un article seul vaut 7,50 € HT
     * (0,833 € -> 0,83 €). Le second retour vaut la ligne entière moins le
     * premier : 7,49 € HT, et les deux retombent exactement sur la ligne.
     */
    public function test_le_second_retour_dune_vente_remisee_retombe_exactement_sur_la_ligne(): void
    {
        $calculateur = $this->calculateur();
        $premierRetour = $calculateur->retourCumule($calculateur->montantsDeVente(8.33, 10, 1, 10), []);
        $secondRetour = $calculateur->retourCumule($calculateur->montantsDeVente(8.33, 10, 2, 10), [$premierRetour]);

        $this->assertSame(['montant_ht' => 14.99, 'montant_tva' => 3.01, 'montant_ttc' => 18.0], $calculateur->montantsDeVente(8.33, 10, 2, 10));
        $this->assertSame(['montant_ht' => 7.5, 'montant_tva' => 1.5, 'montant_ttc' => 9.0], $premierRetour);
        $this->assertSame(['montant_ht' => 7.49, 'montant_tva' => 1.51, 'montant_ttc' => 9.0], $secondRetour);
    }

    /**
     * 10 x 0,13 € HT achetés (1,30 € + 0,26 € de TVA), retournés un par un :
     * recalculer chaque retour seul créditerait 0,03 € de TVA neuf fois, et
     * -0,01 € au dernier. En cumulé, la TVA créditée suit 0,03, 0,05,
     * 0,08... : chaque retour vaut 0,02 € ou 0,03 € de TVA, et le dernier
     * retombe exactement sur la ligne.
     */
    public function test_des_retours_dachat_un_par_un_ne_creditent_jamais_de_tva_negative(): void
    {
        $calculateur = $this->calculateur();
        $retours = [];

        for ($rendus = 1; $rendus <= 10; $rendus++) {
            $retours[] = $calculateur->retourCumule($calculateur->montantsDAchat(0.13, 20, $rendus), $retours);
        }

        $this->assertSame([0.03, 0.02, 0.03, 0.02, 0.03, 0.03, 0.02, 0.03, 0.02, 0.03], array_column($retours, 'montant_tva'));
        $this->assertSame(['total_ht' => 1.3, 'total_tva' => 0.26, 'total_ttc' => 1.56], $calculateur->totaliser($retours));
    }

    /**
     * Vente à un professionnel (E1) : HT de la ligne = quantité x PU TTC x
     * (1 - remise) ÷ (1 + taux), arrondi une seule fois -- 1 000 x 1,00 €
     * à 20 % = 833,333... -> 833,33 € ; 49,90 € x 2 / 1,2 = 83,166... ;
     * 4,20 € x 3 / 1,055 = 11,943... ; 3,99 € x 7 x 0,9 / 1,055 =
     * 23,826... ; 12,00 € x 10 x 0,85 / 1,2 = 85,00 €.
     *
     * @return array<string, array{float, float, int, float, float}>
     */
    public static function lignesProfessionnelles(): array
    {
        return [
            '1 000 x 1,00 € à 20 %' => [1.00, 20, 1000, 0, 833.33],
            '2 x 49,90 € à 20 %' => [49.90, 20, 2, 0, 83.17],
            '3 x 4,20 € à 5,5 %' => [4.20, 5.5, 3, 0, 11.94],
            '7 x 3,99 € à 5,5 % remisés de 10 %' => [3.99, 5.5, 7, 10, 23.83],
            '10 x 12,00 € à 20 % remisés de 15 %' => [12.00, 20, 10, 15, 85.00],
        ];
    }

    #[DataProvider('lignesProfessionnelles')]
    public function test_le_ht_d_une_ligne_professionnelle_s_arrondit_une_seule_fois(float $prixUnitaireTtc, float $tauxTva, int $quantite, float $remise, float $htAttendu): void
    {
        $this->assertSame($htAttendu, $this->calculateur()->montantHtDeLigneProfessionnelle($prixUnitaireTtc, $tauxTva, $quantite, $remise));
    }

    /**
     * Trois lignes de 0,08 € HT à 20 % : 20 % du cumul vaut 0,016 -> 0,02,
     * puis 0,032 -> 0,03, puis 0,048 -> 0,05 ; chaque ligne prend la
     * différence, et les 0,05 € de 20 % du total de 0,24 €. Une ligne
     * seule prend la TVA de son HT.
     */
    public function test_la_tva_d_un_taux_se_calcule_sur_son_total_ht_et_se_repartit_entre_ses_lignes(): void
    {
        $this->assertSame([0.02, 0.01, 0.02], $this->calculateur()->repartirLaTva([0.08, 0.08, 0.08], 20));
        $this->assertSame([166.67], $this->calculateur()->repartirLaTva([833.33], 20));
    }

    /**
     * Une ligne de 1 000 x 1,00 € à 20 % vendue à un professionnel (833,33
     * € HT + 166,67 €) : le HT de ce qui est rendu se calcule comme la
     * facture -- 500 articles font 416,666... -> 416,67 € HT et 20 % de ce
     * HT, 83,334 -> 83,33 € ; un article 0,83 € + 0,17 € ; deux 1,67 € +
     * 0,33 € -- et toute la ligne rendue fait exactement la ligne.
     */
    public function test_les_retours_d_une_ligne_professionnelle_se_calculent_comme_la_facture(): void
    {
        $ligne = ['prix_unitaire_ttc' => '1.00', 'taux_tva' => '20.00', 'quantite' => 1000, 'remise_pourcentage' => '0.00', 'montant_ht' => '833.33', 'montant_tva' => '166.67'];

        $this->assertSame(['montant_ht' => 416.67, 'montant_tva' => 83.33, 'montant_ttc' => 500.0], $this->calculateur()->retourDeLigneProfessionnelle($ligne, 500));
        $this->assertSame(['montant_ht' => 833.33, 'montant_tva' => 166.67, 'montant_ttc' => 1000.0], $this->calculateur()->retourDeLigneProfessionnelle($ligne, 1000));
        $this->assertSame(['montant_ht' => 0.83, 'montant_tva' => 0.17, 'montant_ttc' => 1.0], $this->calculateur()->retourDeLigneProfessionnelle($ligne, 1));
        $this->assertSame(['montant_ht' => 1.67, 'montant_tva' => 0.33, 'montant_ttc' => 2.0], $this->calculateur()->retourDeLigneProfessionnelle($ligne, 2));
    }

    /**
     * K2 : 2 x 40,00 € TTC à 20 % vendus à un professionnel (66,67 € HT +
     * 13,33 €). Le premier article rendu vaut 33,33 € HT + 6,67 €, le
     * second solde la ligne : 33,34 € + 6,66 €, 40,00 € chacun.
     */
    public function test_deux_retours_d_un_article_a_40_euros_vendu_a_un_professionnel_creditent_40_euros_chacun(): void
    {
        $calculateur = $this->calculateur();
        $ligne = ['prix_unitaire_ttc' => '40.00', 'taux_tva' => '20.00', 'quantite' => 2, 'remise_pourcentage' => '0.00', 'montant_ht' => '66.67', 'montant_tva' => '13.33'];

        $premierRetour = $calculateur->retourCumule($calculateur->retourDeLigneProfessionnelle($ligne, 1), []);
        $secondRetour = $calculateur->retourCumule($calculateur->retourDeLigneProfessionnelle($ligne, 2), [$premierRetour]);

        $this->assertSame(['montant_ht' => 33.33, 'montant_tva' => 6.67, 'montant_ttc' => 40.0], $premierRetour);
        $this->assertSame(['montant_ht' => 33.34, 'montant_tva' => 6.66, 'montant_ttc' => 40.0], $secondRetour);
    }

    /**
     * 10 x 0,01 € à 20 % font 0,08 € HT (0,0833... arrondi) ; deuxième de
     * deux lignes de 0,08 € HT, la ligne n'a pris que 0,01 € de la TVA de
     * son taux. Neuf articles rendus font déjà 0,08 € HT (0,075 arrondi),
     * dont 20 % vaut 0,02 € : le retour ne crédite que les 0,01 € de la
     * ligne, jamais plus qu'elle.
     */
    public function test_un_retour_professionnel_ne_credite_jamais_plus_que_la_tva_de_la_ligne(): void
    {
        $ligne = ['prix_unitaire_ttc' => '0.01', 'taux_tva' => '20.00', 'quantite' => 10, 'remise_pourcentage' => '0.00', 'montant_ht' => '0.08', 'montant_tva' => '0.01'];

        $this->assertSame(['montant_ht' => 0.08, 'montant_tva' => 0.01, 'montant_ttc' => 0.09], $this->calculateur()->retourDeLigneProfessionnelle($ligne, 9));
    }

    public function test_un_retour_professionnel_hors_de_la_quantite_de_la_ligne_est_refuse(): void
    {
        $ligne = ['prix_unitaire_ttc' => '40.00', 'taux_tva' => '20.00', 'quantite' => 2, 'remise_pourcentage' => '0.00', 'montant_ht' => '66.67', 'montant_tva' => '13.33'];

        $this->assertThrows(fn () => $this->calculateur()->retourDeLigneProfessionnelle($ligne, 0), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
        $this->assertThrows(fn () => $this->calculateur()->retourDeLigneProfessionnelle($ligne, 3), InvalidArgumentException::class, 'Un retour rend entre 1 article et la quantité de la ligne.');
    }

    public function test_une_remise_sur_plusieurs_articles_reste_exacte_au_centime(): void
    {
        $ligne = $this->calculateur()->montantsDeVente(8.33, 9.99, quantite: 5, remisePourcentage: 10);

        // HT : 8,33 x 5 = 41,65, remise 4,165 -> 4,17 -> 37,48 ;
        // TTC : 9,99 x 5 = 49,95, remise 4,995 -> 5,00 -> 44,95.
        $this->assertSame(['montant_ht' => 37.48, 'montant_tva' => 7.47, 'montant_ttc' => 44.95], $ligne);
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33.
     */
    public function test_arrondir_au_centime_ne_tombe_pas_dans_le_piege_du_flottant(): void
    {
        $this->assertSame(8.33, $this->calculateur()->arrondirAuCentime(8.325));
    }

    public function test_une_remise_en_pourcentage_sapplique_a_la_vente_comme_a_lachat(): void
    {
        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $this->calculateur()->montantsDeVente(100, 120, quantite: 1, remisePourcentage: 10));
        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $this->calculateur()->montantsDAchat(100, 20, quantite: 1, remisePourcentage: 10));
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $ligne = $this->calculateur()->montantsDeVente(0.1, 0.1, 1);

        $total = $this->calculateur()->totaliser(array_fill(0, 1000, $ligne));

        $this->assertSame(100.0, $total['total_ttc']);
    }

    /**
     * Les 10 000 prix TTC de 0,01 € à 100,00 €, pour chaque taux : le HT est
     * exactement TTC / (1 + taux), arrondi au centime.
     */
    public function test_la_conversion_ttc_vers_ht_est_exacte_sur_tous_les_prix_de_0_01_a_100_euros(): void
    {
        $verifications = 0;

        foreach (self::TAUX as $taux) {
            for ($ttcCentimes = 1; $ttcCentimes <= 10000; $ttcCentimes++) {
                $ht = $this->calculateur()->prixUnitaireHtDepuisTtc($ttcCentimes / 100, (float) $taux);

                $this->assertSame($this->htDepuisTtcAttendu($ttcCentimes, $taux), $this->enCentimes($ht), "TTC {$this->texte($ttcCentimes)} à {$taux} %");
                $verifications++;
            }
        }

        $this->assertSame(70000, $verifications);
    }

    /**
     * Les 10 000 prix de vente HT de 0,01 € à 100,00 € (fiche d'un produit
     * dont on saisit le HT), pour chaque taux : le TTC est exactement HT +
     * HT x taux, la TVA arrondie au centime.
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
     * 10 000 lignes tirées au hasard (prix de 0,01 € à 9 999,99 €, vente ou
     * achat, de 1 à 999 articles, avec ou sans remise, tous les taux) : HT,
     * TVA et TTC égalent la référence exacte et HT + TVA = TTC. Sans
     * remise, le HT est exactement le PU HT x quantité ; une vente donne
     * aussi la TVA et le TTC de l'article x quantité, un achat la TVA du HT
     * de la ligne.
     */
    public function test_10000_lignes_aleatoires_egalent_le_calcul_exact(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE));

        for ($i = 0; $i < 10000; $i++) {
            $ligne = $this->ligneAleatoire($hasard);
            $montants = $this->calculerLigne($ligne);
            $attendus = $this->montantsAttendus($ligne);
            $cas = $this->decrire($ligne);

            $this->assertSame($attendus['ht'], $this->enCentimes($montants['montant_ht']), "HT : {$cas}");
            $this->assertSame($attendus['ttc'], $this->enCentimes($montants['montant_ttc']), "TTC : {$cas}");
            $this->assertSame($attendus['ttc'] - $attendus['ht'], $this->enCentimes($montants['montant_tva']), "TVA : {$cas}");

            if ($ligne['remise'] === 0) {
                $quantite = $ligne['quantite'];

                $this->assertSame($ligne['ht'] * $quantite, $this->enCentimes($montants['montant_ht']), "HT x quantité : {$cas}");

                if ($ligne['ttc'] !== null) {
                    $this->assertSame(($ligne['ttc'] - $ligne['ht']) * $quantite, $this->enCentimes($montants['montant_tva']), "TVA x quantité : {$cas}");
                    $this->assertSame($ligne['ttc'] * $quantite, $this->enCentimes($montants['montant_ttc']), "TTC x quantité : {$cas}");
                } else {
                    $this->assertSame($this->tvaAttendue($ligne['ht'] * $quantite, $ligne['taux']), $this->enCentimes($montants['montant_tva']), "TVA du HT : {$cas}");
                }
            }
        }
    }

    /**
     * 2 000 factures de 1 à 20 lignes tirées au hasard : chaque total est la
     * somme exacte des lignes, et total HT + total TVA = total TTC.
     */
    public function test_2000_factures_aleatoires_totalisent_exactement_leurs_lignes(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 1));

        for ($i = 0; $i < 2000; $i++) {
            $lignes = [];
            $attendu = ['ht' => 0, 'tva' => 0, 'ttc' => 0];

            for ($n = $hasard->getInt(1, 20); $n > 0; $n--) {
                $ligne = $this->ligneAleatoire($hasard);
                $lignes[] = $this->calculerLigne($ligne);
                $montants = $this->montantsAttendus($ligne);
                $attendu['ht'] += $montants['ht'];
                $attendu['ttc'] += $montants['ttc'];
                $attendu['tva'] += $montants['ttc'] - $montants['ht'];
            }

            $totaux = $this->calculateur()->totaliser($lignes);

            $this->assertSame($attendu['ht'], $this->enCentimes($totaux['total_ht']), "facture n° {$i}");
            $this->assertSame($attendu['tva'], $this->enCentimes($totaux['total_tva']), "facture n° {$i}");
            $this->assertSame($attendu['ttc'], $this->enCentimes($totaux['total_ttc']), "facture n° {$i}");
            $this->assertSame($this->enCentimes($totaux['total_ttc']), $this->enCentimes($totaux['total_ht']) + $this->enCentimes($totaux['total_tva']), "facture n° {$i}");
        }
    }

    /**
     * 2 000 lignes rendues en 2 à 5 retours successifs de quantités tirées
     * au hasard : chaque retour vaut exactement la ligne calculée pour tout
     * ce qui a été rendu jusqu'ici moins les retours précédents, aucun ne
     * crédite de HT, de TVA ou de TTC négatif, et la somme des retours
     * retombe exactement sur la ligne vendue ou achetée.
     */
    public function test_2000_series_de_retours_creditent_exactement_la_ligne(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 2));

        for ($i = 0; $i < 2000; $i++) {
            $ligne = $this->ligneAleatoire($hasard);
            $ligne['quantite'] = max($ligne['quantite'], 2);
            $vendu = $this->calculerLigne($ligne);
            $cas = $this->decrire($ligne);

            $retours = [];
            $rendus = 0;

            while ($rendus < $ligne['quantite']) {
                $rendus = $hasard->getInt(0, 3) === 0 ? $ligne['quantite'] : $hasard->getInt($rendus + 1, $ligne['quantite']);
                $cumul = $this->calculerLigne([...$ligne, 'quantite' => $rendus]);
                $retour = $this->calculateur()->retourCumule($cumul, $retours);
                $dejaCredite = $this->calculateur()->totaliser($retours);

                $this->assertSame($this->enCentimes($cumul['montant_ht']) - $this->enCentimes($dejaCredite['total_ht']), $this->enCentimes($retour['montant_ht']), "retour HT : {$cas}");
                $this->assertSame($this->enCentimes($cumul['montant_tva']) - $this->enCentimes($dejaCredite['total_tva']), $this->enCentimes($retour['montant_tva']), "retour TVA : {$cas}");

                foreach ($retour as $montant => $valeur) {
                    $this->assertGreaterThanOrEqual(0, $this->enCentimes($valeur), "{$montant} négatif : {$cas}");
                }

                $retours[] = $retour;
            }

            $credite = $this->calculateur()->totaliser($retours);

            $this->assertSame($this->enCentimes($vendu['montant_ht']), $this->enCentimes($credite['total_ht']), "total HT : {$cas}");
            $this->assertSame($this->enCentimes($vendu['montant_tva']), $this->enCentimes($credite['total_tva']), "total TVA : {$cas}");
            $this->assertSame($this->enCentimes($vendu['montant_ttc']), $this->enCentimes($credite['total_ttc']), "total TTC : {$cas}");
        }
    }

    /**
     * 10 000 lignes vendues à un professionnel, tirées au hasard (PU TTC de
     * 0,01 € à 9 999,99 €, de 1 à 999 articles, avec ou sans remise, tous
     * les taux) : le HT est exactement quantité x PU TTC x (1 - remise) ÷
     * (1 + taux), arrondi une fois au centime.
     */
    public function test_10000_lignes_professionnelles_aleatoires_egalent_le_calcul_exact(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 3));

        for ($i = 0; $i < 10000; $i++) {
            $ligne = $this->ligneProfessionnelleAleatoire($hasard, self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)]);

            $this->assertSame(
                $this->htProfessionnelAttendu($ligne, $ligne['quantite']),
                $this->enCentimes($this->calculateur()->montantHtDeLigneProfessionnelle($ligne['ttc'] / 100, (float) $ligne['taux'], $ligne['quantite'], $ligne['remise'] / 100)),
                $this->decrireProfessionnelle($ligne),
            );
        }
    }

    /**
     * 2 000 factures à un professionnel de 1 à 20 lignes tirées au hasard,
     * de taux mélangés : pour chaque taux, la somme des parts de TVA des
     * lignes vaut exactement la TVA du total HT du taux, arrondie une
     * fois ; aucune part n'est négative, et chacune s'écarte d'au plus un
     * centime de la TVA du HT de sa ligne.
     */
    public function test_2000_factures_professionnelles_aleatoires_ont_la_tva_du_total_ht_de_chaque_taux(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 4));

        for ($i = 0; $i < 2000; $i++) {
            $htParTaux = [];

            for ($n = $hasard->getInt(1, 20); $n > 0; $n--) {
                $ligne = $this->ligneProfessionnelleAleatoire($hasard, self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)]);
                $htParTaux[$ligne['taux']][] = $this->htProfessionnelAttendu($ligne, $ligne['quantite']);
            }

            foreach ($htParTaux as $taux => $hts) {
                $parts = array_map($this->enCentimes(...), $this->calculateur()->repartirLaTva(array_map(fn (int $ht): float => $ht / 100, $hts), (float) $taux));

                $this->assertSame($this->tvaAttendue(array_sum($hts), (string) $taux), array_sum($parts), "facture n° {$i}, taux {$taux} %");

                foreach ($parts as $index => $part) {
                    $this->assertGreaterThanOrEqual(0, $part, "facture n° {$i}, taux {$taux} %, ligne {$index}");
                    $this->assertLessThanOrEqual(1, abs($part - $this->tvaAttendue($hts[$index], (string) $taux)), "facture n° {$i}, taux {$taux} %, ligne {$index}");
                }
            }
        }
    }

    /**
     * 2 000 lignes de factures à un professionnel (de 1 à 5 lignes du même
     * taux, dont la TVA se répartit), rendues en retours successifs de
     * quantités tirées au hasard : tant que la ligne n'est pas toute
     * rendue, le HT cumulé est celui de la facture pour ces articles et sa
     * TVA celle de ce HT, plafonnée à la ligne ; aucun retour ne crédite de
     * montant négatif, et la somme des retours retombe exactement sur la
     * ligne.
     */
    public function test_2000_series_de_retours_professionnels_creditent_exactement_la_ligne(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 5));

        for ($i = 0; $i < 2000; $i++) {
            $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
            $lignes = [];

            for ($n = $hasard->getInt(1, 5); $n > 0; $n--) {
                $lignes[] = [...$this->ligneProfessionnelleAleatoire($hasard, $taux), 'quantite' => $hasard->getInt(2, 999)];
            }

            $hts = array_map(fn (array $ligne): int => $this->htProfessionnelAttendu($ligne, $ligne['quantite']), $lignes);
            $parts = array_map($this->enCentimes(...), $this->calculateur()->repartirLaTva(array_map(fn (int $ht): float => $ht / 100, $hts), (float) $taux));
            $choisie = $hasard->getInt(0, count($lignes) - 1);
            $ligne = $lignes[$choisie];
            $vendue = [
                'prix_unitaire_ttc' => $ligne['ttc'] / 100,
                'taux_tva' => (float) $taux,
                'quantite' => $ligne['quantite'],
                'remise_pourcentage' => $ligne['remise'] / 100,
                'montant_ht' => $hts[$choisie] / 100,
                'montant_tva' => $parts[$choisie] / 100,
            ];
            $cas = $this->decrireProfessionnelle($ligne)." (TVA de la ligne {$this->texte($parts[$choisie])})";

            $retours = [];
            $rendus = 0;

            while ($rendus < $ligne['quantite']) {
                $rendus = $hasard->getInt(0, 3) === 0 ? $ligne['quantite'] : $hasard->getInt($rendus + 1, $ligne['quantite']);
                $cumul = $this->calculateur()->retourDeLigneProfessionnelle($vendue, $rendus);

                if ($rendus < $ligne['quantite']) {
                    $htCumule = $this->htProfessionnelAttendu($ligne, $rendus);

                    $this->assertSame($htCumule, $this->enCentimes($cumul['montant_ht']), "HT cumulé de {$rendus} : {$cas}");
                    $this->assertSame(min($parts[$choisie], $this->tvaAttendue($htCumule, $taux)), $this->enCentimes($cumul['montant_tva']), "TVA cumulée de {$rendus} : {$cas}");
                }

                $retour = $this->calculateur()->retourCumule($cumul, $retours);

                foreach ($retour as $montant => $valeur) {
                    $this->assertGreaterThanOrEqual(0, $this->enCentimes($valeur), "{$montant} négatif : {$cas}");
                }

                $retours[] = $retour;
            }

            $credite = $this->calculateur()->totaliser($retours);

            $this->assertSame([$hts[$choisie], $parts[$choisie], $hts[$choisie] + $parts[$choisie]], [
                $this->enCentimes($credite['total_ht']),
                $this->enCentimes($credite['total_tva']),
                $this->enCentimes($credite['total_ttc']),
            ], "total : {$cas}");
        }
    }

    /**
     * Ligne vendue à un professionnel tirée au hasard, en unités entières :
     * PU TTC en centimes, de 1 à 999 articles, remise en centièmes de point
     * (nulle sept fois sur dix).
     *
     * @return array{ttc: int, quantite: int, remise: int, taux: string}
     */
    private function ligneProfessionnelleAleatoire(Randomizer $hasard, string $taux): array
    {
        return [
            'ttc' => $hasard->getInt(1, 999999),
            'quantite' => $hasard->getInt(1, 999),
            'remise' => $hasard->getInt(1, 10) <= 7 ? 0 : $hasard->getInt(0, 10000),
            'taux' => $taux,
        ];
    }

    /**
     * Référence exacte du HT de $quantite articles d'une ligne vendue à un
     * professionnel : quantité x PU TTC x (1 - remise) ÷ (1 + taux),
     * arrondi une fois au centime, au demi supérieur.
     *
     * @param  array{ttc: int, quantite: int, remise: int, taux: string}  $ligne
     */
    private function htProfessionnelAttendu(array $ligne, int $quantite): int
    {
        return BigDecimal::ofUnscaledValue($ligne['ttc'] * $quantite, 2)
            ->multipliedBy(BigDecimal::one()->minus(BigDecimal::ofUnscaledValue($ligne['remise'], 4)))
            ->dividedBy(BigDecimal::one()->plus(BigDecimal::of($ligne['taux'])->dividedBy(100, 4)), 2, RoundingMode::HalfUp)
            ->getUnscaledValue()
            ->toInt();
    }

    /**
     * @param  array{ttc: int, quantite: int, remise: int, taux: string}  $ligne
     */
    private function decrireProfessionnelle(array $ligne): string
    {
        return sprintf(
            'vente professionnelle : PU TTC %s (TVA %s %%), quantité %d, remise %s %%',
            $this->texte($ligne['ttc']),
            $ligne['taux'],
            $ligne['quantite'],
            $this->texte($ligne['remise']),
        );
    }

    /**
     * Ligne tirée au hasard, en unités entières : une vente (PU TTC en
     * centimes et son PU HT de référence) ou un achat (PU HT seul, ttc
     * null), quantité de 1 à 999 articles, remise en centièmes de point
     * (nulle sept fois sur dix).
     *
     * @return array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string}
     */
    private function ligneAleatoire(Randomizer $hasard): array
    {
        $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
        $prixCentimes = $hasard->getInt(1, 999999);
        $estUneVente = $hasard->getInt(0, 1) === 0;

        return [
            'ht' => $estUneVente ? $this->htDepuisTtcAttendu($prixCentimes, $taux) : $prixCentimes,
            'ttc' => $estUneVente ? $prixCentimes : null,
            'quantite' => $hasard->getInt(1, 999),
            'remise' => $hasard->getInt(1, 10) <= 7 ? 0 : $hasard->getInt(0, 10000),
            'taux' => $taux,
        ];
    }

    /**
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string}  $ligne
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function calculerLigne(array $ligne): array
    {
        return $ligne['ttc'] !== null
            ? $this->calculateur()->montantsDeVente($ligne['ht'] / 100, $ligne['ttc'] / 100, $ligne['quantite'], $ligne['remise'] / 100)
            : $this->calculateur()->montantsDAchat($ligne['ht'] / 100, (float) $ligne['taux'], $ligne['quantite'], $ligne['remise'] / 100);
    }

    /**
     * Référence exacte d'une ligne : PU x quantité, puis remise arrondie au
     * centime -- séparément pour le HT et le TTC d'une vente ; pour un
     * achat, puis la TVA du HT de la ligne arrondie au centime.
     *
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string}  $ligne
     * @return array{ht: int, ttc: int}
     */
    private function montantsAttendus(array $ligne): array
    {
        $net = function (int $prixCentimes) use ($ligne): int {
            $brut = $prixCentimes * $ligne['quantite'];
            $remise = $this->auCentime(BigDecimal::ofUnscaledValue($brut, 2)->multipliedBy(BigDecimal::ofUnscaledValue($ligne['remise'], 2))->dividedBy(100, 6));

            return $brut - $remise;
        };

        $ht = $net($ligne['ht']);

        return [
            'ht' => $ht,
            'ttc' => $ligne['ttc'] !== null ? $net($ligne['ttc']) : $ht + $this->tvaAttendue($ht, $ligne['taux']),
        ];
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

    private function enCentimes(float $montant): int
    {
        return (int) round($montant * 100);
    }

    private function texte(int $centimes): string
    {
        return (string) BigDecimal::ofUnscaledValue($centimes, 2);
    }

    /**
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string}  $ligne
     */
    private function decrire(array $ligne): string
    {
        return sprintf(
            '%s : PU HT %s / PU TTC %s (TVA %s %%), quantité %d, remise %s %%',
            $ligne['ttc'] !== null ? 'vente' : 'achat',
            $this->texte($ligne['ht']),
            $ligne['ttc'] !== null ? $this->texte($ligne['ttc']) : '—',
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
            'taux négatif (TTC vers HT)' => [fn (CalculateurLigne $c) => $c->prixUnitaireHtDepuisTtc(10, -100), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux négatif (HT vers TTC)' => [fn (CalculateurLigne $c) => $c->prixUnitaireTtcDepuisHt(10, -5), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (achat)' => [fn (CalculateurLigne $c) => $c->montantsDAchat(10, 150, 1), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (prix de vente)' => [fn (CalculateurLigne $c) => $c->prixTtcArrondi(10, 150), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise négative' => [fn (CalculateurLigne $c) => $c->montantsDeVente(10, 12, 1, -10), 'La remise doit être comprise entre 0 et 100 %.'],
            'remise au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->montantsDAchat(10, 20, 1, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'escompte au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->coutUnitaireNet(10, 0, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'montant reçu absurde' => [fn (CalculateurLigne $c) => $c->soustraire('100000000000000000', 40), 'Montant hors limites.'],
            'paiement absurde' => [fn (CalculateurLigne $c) => $c->arrondirAuCentime(1e17), 'Montant hors limites.'],
            'prix x quantité qui déborde' => [fn (CalculateurLigne $c) => $c->montantsDAchat(9999999999.99, 20, 999999), 'Montant hors limites.'],
            'taux au-delà de 100 % (ligne professionnelle)' => [fn (CalculateurLigne $c) => $c->montantHtDeLigneProfessionnelle(10, 150, 1), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise au-delà de 100 % (ligne professionnelle)' => [fn (CalculateurLigne $c) => $c->montantHtDeLigneProfessionnelle(10, 20, 1, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'prix x quantité qui déborde (ligne professionnelle)' => [fn (CalculateurLigne $c) => $c->montantHtDeLigneProfessionnelle(9999999999.99, 20, 999999), 'Montant hors limites.'],
            'taux négatif (répartition de la TVA)' => [fn (CalculateurLigne $c) => $c->repartirLaTva([10], -5), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
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
        $this->assertSame(10.0, $this->calculateur()->prixUnitaireHtDepuisTtc(10, 0));
        $this->assertSame(['montant_ht' => 10.0, 'montant_tva' => 10.0, 'montant_ttc' => 20.0], $this->calculateur()->montantsDAchat(10, 100, 1));
        $this->assertSame(['montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0], $this->calculateur()->montantsDeVente(10, 12, 3, 100));
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
}
