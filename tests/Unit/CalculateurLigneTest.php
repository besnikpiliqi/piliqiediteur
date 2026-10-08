<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ResolveurPrixLigne;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/**
 * Calcul d'une ligne depuis le prix réellement saisi (voir le commentaire
 * de classe de CalculateurLigne) : un prix TTC se décompose article par
 * article (PU HT, TVA et PU TTC au centime, multipliés séparément par la
 * quantité) ; un prix HT se multiplie par la quantité, puis la TVA se
 * calcule une seule fois sur le HT de la ligne. Tout se fait en centimes
 * entiers, jamais en flottant.
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
     * 40,00 € TTC = 33,33 € HT + 6,67 € de TVA. Un prix HT reste seul : il
     * n'a pas de PU TTC arrondi (1,18 € x 1,2 = 1,416 €, qui n'est le prix
     * de rien).
     */
    public function test_un_prix_ttc_a_son_ht_au_centime_et_un_prix_ht_na_pas_de_ttc(): void
    {
        $this->assertSame(['prix_unitaire_ht' => 33.33, 'prix_unitaire_ttc' => 40.0], $this->calculateur()->prixUnitaires(PrixReference::Ttc, 40, 20));
        $this->assertSame(['prix_unitaire_ht' => 1.18, 'prix_unitaire_ttc' => null], $this->calculateur()->prixUnitaires(PrixReference::Ht, 1.18, 20));
    }

    /**
     * Chaque montant de la ligne se vérifie à la main : PU x quantité, et
     * HT + TVA = TTC.
     */
    public function test_une_ligne_au_prix_ttc_multiplie_separement_le_ht_et_le_ttc_par_la_quantite(): void
    {
        $this->assertSame(['montant_ht' => 66.66, 'montant_tva' => 13.34, 'montant_ttc' => 80.0], $this->calculateur()->calculer(PrixReference::Ttc, 33.33, 40, 20, quantite: 2));
    }

    /**
     * Facture d'achat signalée par le magasin : 10 x 1,18 € HT font 11,80 €
     * HT et 20 % de TVA sur ce HT, 2,36 € -- et non 10 x 1,42 € (1,18 € x
     * 1,2 = 1,416 € arrondi) = 14,20 €. 5 x 33,65 € HT, dont la TVA tombe
     * juste, ne change pas.
     */
    public function test_une_ligne_au_prix_ht_calcule_sa_tva_sur_son_total_ht(): void
    {
        $calculateur = $this->calculateur();

        $coques = $calculateur->calculer(PrixReference::Ht, 1.18, null, 20, quantite: 10);
        $ecrans = $calculateur->calculer(PrixReference::Ht, 33.65, null, 20, quantite: 5);

        $this->assertSame(['montant_ht' => 11.8, 'montant_tva' => 2.36, 'montant_ttc' => 14.16], $coques);
        $this->assertSame(['montant_ht' => 168.25, 'montant_tva' => 33.65, 'montant_ttc' => 201.9], $ecrans);
        $this->assertSame(['total_ht' => 180.05, 'total_tva' => 36.01, 'total_ttc' => 216.06], $calculateur->totaliser([$coques, $ecrans]));
        $this->assertSame(['montant_ht' => 24.99, 'montant_tva' => 5.0, 'montant_ttc' => 29.99], $calculateur->calculer(PrixReference::Ht, 8.33, null, 20, quantite: 3));
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
            $calculateur->calculer(PrixReference::Ttc, 33.33, 40, 20, 2),
            $calculateur->calculer(PrixReference::Ttc, 10, 10, 0, 1),
            $calculateur->calculer(PrixReference::Ttc, 8.33, 10, 20, 1),
        ]);

        $this->assertSame(['total_ht' => 84.99, 'total_tva' => 15.01, 'total_ttc' => 100.0], $totaux);
    }

    /**
     * Rendre 1 article puis le second d'une ligne de 2 crédite exactement
     * la ligne vendue, HT, TVA et TTC compris.
     */
    public function test_deux_retours_dun_article_retombent_exactement_sur_la_ligne_vendue(): void
    {
        $ligne = $this->calculateur()->calculer(PrixReference::Ttc, 33.33, 40, 20, 2);
        $retour = $this->calculateur()->calculer(PrixReference::Ttc, 33.33, 40, 20, 1);

        $this->assertSame(['montant_ht' => 33.33, 'montant_tva' => 6.67, 'montant_ttc' => 40.0], $retour);
        $this->assertSame(
            ['total_ht' => $ligne['montant_ht'], 'total_tva' => $ligne['montant_tva'], 'total_ttc' => $ligne['montant_ttc']],
            $this->calculateur()->totaliser([$retour, $retour]),
        );
    }

    /**
     * Remise : 2 x 10 € TTC (8,33 € HT) remisés de 10 % = 14,99 € HT
     * (1,666 € de remise -> 1,67 €), mais un article seul vaut 7,50 € HT
     * (0,833 € -> 0,83 €) ; recalculer deux retours d'un article
     * créditerait 15,00 € HT. Le dernier retour prend donc le solde exact
     * de la ligne.
     */
    public function test_le_solde_dune_ligne_retombe_exactement_sur_la_ligne_facturee(): void
    {
        $ligne = $this->calculateur()->calculer(PrixReference::Ttc, 8.33, 10, 20, 2, 10);
        $premierRetour = $this->calculateur()->calculer(PrixReference::Ttc, 8.33, 10, 20, 1, 10);

        $solde = $this->calculateur()->solder($ligne, [$premierRetour]);

        $this->assertSame(['montant_ht' => 14.99, 'montant_tva' => 3.01, 'montant_ttc' => 18.0], $ligne);
        $this->assertSame(['montant_ht' => 7.5, 'montant_tva' => 1.5, 'montant_ttc' => 9.0], $premierRetour);
        $this->assertSame(['montant_ht' => 7.49, 'montant_tva' => 1.51, 'montant_ttc' => 9.0], $solde);
    }

    public function test_une_remise_sur_plusieurs_articles_reste_exacte_au_centime(): void
    {
        $ligne = $this->calculateur()->calculer(PrixReference::Ttc, 8.33, 9.99, 20, quantite: 5, remisePourcentage: 10);

        // HT : 8,33 x 5 = 41,65, remise 4,165 -> 4,17 -> 37,48 ;
        // TTC : 9,99 x 5 = 49,95, remise 4,995 -> 5,00 -> 44,95.
        $this->assertSame(['montant_ht' => 37.48, 'montant_tva' => 7.47, 'montant_ttc' => 44.95], $ligne);
    }

    public function test_un_taux_reduit_decimal_reste_exact(): void
    {
        $prix = $this->calculateur()->prixUnitaires(PrixReference::Ttc, 10, 5.5);

        // 10,00 / 1,055 = 9,4786... -> 9,48 HT.
        $this->assertSame(['prix_unitaire_ht' => 9.48, 'prix_unitaire_ttc' => 10.0], $prix);
        $this->assertSame(['montant_ht' => 9.48, 'montant_tva' => 0.52, 'montant_ttc' => 10.0], $this->calculateur()->calculer(PrixReference::Ttc, 9.48, 10, 5.5, 1));
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33.
     */
    public function test_arrondir_au_centime_ne_tombe_pas_dans_le_piege_du_flottant(): void
    {
        $this->assertSame(8.33, $this->calculateur()->arrondirAuCentime(8.325));
    }

    public function test_deux_lignes_a_8_33_ht_totalisent_exactement_20_euros(): void
    {
        $ligne = $this->calculateur()->calculer(PrixReference::Ht, 8.33, null, 20, 1);

        $this->assertSame(['montant_ht' => 8.33, 'montant_tva' => 1.67, 'montant_ttc' => 10.0], $ligne);
        $this->assertSame(20.0, $this->calculateur()->totaliser([$ligne, $ligne])['total_ttc']);
    }

    public function test_une_remise_en_pourcentage_sapplique_au_ht_comme_au_ttc(): void
    {
        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $this->calculateur()->calculer(PrixReference::Ttc, 100, 120, 20, quantite: 1, remisePourcentage: 10));
        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $this->calculateur()->calculer(PrixReference::Ht, 100, null, 20, quantite: 1, remisePourcentage: 10));
    }

    /**
     * 5,99 € TTC (4,99 € HT) x 3 = 14,97 € HT + 3,00 € de TVA = 17,97 €
     * TTC ; mais 4,99 € HT x 3 = 14,97 € HT + 2,99 € de TVA (20 % de
     * 14,97 €) = 17,96 € TTC.
     */
    public function test_la_quantite_multiplie_exactement_le_prix_saisi(): void
    {
        $this->assertSame(['montant_ht' => 14.97, 'montant_tva' => 3.0, 'montant_ttc' => 17.97], $this->calculateur()->calculer(PrixReference::Ttc, 4.99, 5.99, 20, 3));
        $this->assertSame(['montant_ht' => 14.97, 'montant_tva' => 2.99, 'montant_ttc' => 17.96], $this->calculateur()->calculer(PrixReference::Ht, 4.99, null, 20, 3));
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $ligne = $this->calculateur()->calculer(PrixReference::Ttc, 0.1, 0.1, 0, 1);

        $total = $this->calculateur()->totaliser(array_fill(0, 1000, $ligne));

        $this->assertSame(100.0, $total['total_ttc']);
    }

    /**
     * Les 10 000 prix TTC de 0,01 € à 100,00 €, pour chaque taux : le TTC
     * saisi reste tel quel, et le HT est exactement TTC / (1 + taux),
     * arrondi au centime.
     */
    public function test_la_conversion_ttc_vers_ht_est_exacte_sur_tous_les_prix_de_0_01_a_100_euros(): void
    {
        $verifications = 0;

        foreach (self::TAUX as $taux) {
            for ($ttcCentimes = 1; $ttcCentimes <= 10000; $ttcCentimes++) {
                $prix = $this->calculateur()->prixUnitaires(PrixReference::Ttc, $ttcCentimes / 100, (float) $taux);
                $cas = "TTC {$this->texte($ttcCentimes)} à {$taux} %";

                $this->assertSame($ttcCentimes, $this->enCentimes($prix['prix_unitaire_ttc']), $cas);
                $this->assertSame($this->htDepuisTtcAttendu($ttcCentimes, $taux), $this->enCentimes($prix['prix_unitaire_ht']), $cas);
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
     * 10 000 lignes tirées au hasard (prix de 0,01 € à 9 999,99 €, saisi en
     * HT ou en TTC, de 1 à 999 articles, avec ou sans remise, tous les
     * taux) : HT, TVA et TTC égalent la référence exacte et HT + TVA = TTC.
     * Sans remise, le HT est exactement le PU HT x quantité ; au prix TTC,
     * la TVA et le TTC sont aussi ceux de l'article x quantité, et au prix
     * HT, la TVA est le taux appliqué au HT de la ligne.
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

                if ($ligne['reference'] === PrixReference::Ttc) {
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
     * au hasard : chaque retour partiel égale la référence exacte pour sa
     * quantité, et le dernier retour (le solde) fait retomber HT, TVA et
     * TTC crédités exactement sur la ligne vendue -- y compris quand une
     * remise fait que recalculer ce dernier retour aurait donné un centime
     * d'écart (cas comptés, pour que le test ne devienne jamais trivial).
     */
    public function test_2000_series_de_retours_creditent_exactement_la_ligne_vendue(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 2));
        $soldesDifferentsDUnRecalcul = 0;

        for ($i = 0; $i < 2000; $i++) {
            $ligne = $this->ligneAleatoire($hasard);
            $ligne['quantite'] = max($ligne['quantite'], 2);
            $vendu = $this->calculerLigne($ligne);
            $cas = $this->decrire($ligne);

            $retours = [];
            $restant = $ligne['quantite'];

            for ($k = $hasard->getInt(2, 5); $k > 1 && $restant > 1; $k--) {
                $quantiteRendue = $hasard->getInt(1, $restant - 1);
                $restant -= $quantiteRendue;

                $retour = $this->calculerLigne([...$ligne, 'quantite' => $quantiteRendue]);
                $attendu = $this->montantsAttendus([...$ligne, 'quantite' => $quantiteRendue]);
                $this->assertSame($attendu['ht'], $this->enCentimes($retour['montant_ht']), "retour partiel : {$cas}");
                $this->assertSame($attendu['ttc'], $this->enCentimes($retour['montant_ttc']), "retour partiel : {$cas}");
                $retours[] = $retour;
            }

            $solde = $this->calculateur()->solder($vendu, $retours);

            if ($solde !== $this->calculerLigne([...$ligne, 'quantite' => $restant])) {
                $soldesDifferentsDUnRecalcul++;
            }

            $retours[] = $solde;
            $credite = $this->calculateur()->totaliser($retours);

            $this->assertSame($this->enCentimes($vendu['montant_ht']), $this->enCentimes($credite['total_ht']), "solde HT : {$cas}");
            $this->assertSame($this->enCentimes($vendu['montant_tva']), $this->enCentimes($credite['total_tva']), "solde TVA : {$cas}");
            $this->assertSame($this->enCentimes($vendu['montant_ttc']), $this->enCentimes($credite['total_ttc']), "solde TTC : {$cas}");
        }

        $this->assertGreaterThan(0, $soldesDifferentsDUnRecalcul);
    }

    /**
     * 5 000 saisies tirées au hasard, telles qu'elles arrivent du
     * formulaire ou d'une API (un seul prix fourni, ou les deux avec le
     * prix de référence) : le prix saisi est toujours gardé au centime ; un
     * prix TTC a pour PU HT sa conversion exacte, un prix HT n'a pas de PU
     * TTC.
     */
    public function test_5000_saisies_aleatoires_gardent_le_prix_saisi_et_convertissent_lautre(): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + 3));
        $resolveur = new ResolveurPrixLigne($this->calculateur());

        for ($i = 0; $i < 5000; $i++) {
            $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
            $prixCentimes = $hasard->getInt(1, 999999);
            $reference = $hasard->getInt(0, 1) === 0 ? PrixReference::Ht : PrixReference::Ttc;
            $champ = $reference === PrixReference::Ht ? 'prix_unitaire_ht' : 'prix_unitaire_ttc';
            $donnees = $hasard->getInt(0, 1) === 0
                ? [$champ => $this->texte($prixCentimes)]
                : ['prix_reference' => $reference->value, 'prix_unitaire_ht' => '1.23', 'prix_unitaire_ttc' => '4.56', $champ => $this->texte($prixCentimes)];
            $cas = "{$reference->getLabel()} {$this->texte($prixCentimes)} à {$taux} %";

            $prix = $resolveur->resoudre($donnees, PrixReference::Ttc, (float) $taux);

            $this->assertSame($reference, $prix['prix_reference'], $cas);

            if ($reference === PrixReference::Ttc) {
                $this->assertSame($prixCentimes, $this->enCentimes($prix['prix_unitaire_ttc']), $cas);
                $this->assertSame($this->htDepuisTtcAttendu($prixCentimes, $taux), $this->enCentimes($prix['prix_unitaire_ht']), $cas);
            } else {
                $this->assertSame($prixCentimes, $this->enCentimes($prix['prix_unitaire_ht']), $cas);
                $this->assertNull($prix['prix_unitaire_ttc'], $cas);
            }
        }
    }

    /**
     * Ligne tirée au hasard, en unités entières : prix en centimes (saisi
     * en TTC avec son PU HT de référence, ou en HT seul), quantité de 1 à
     * 999 articles, remise en centièmes de point (nulle sept fois sur dix).
     *
     * @return array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string, reference: PrixReference}
     */
    private function ligneAleatoire(Randomizer $hasard): array
    {
        $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
        $prixCentimes = $hasard->getInt(1, 999999);
        $reference = $hasard->getInt(0, 1) === 0 ? PrixReference::Ht : PrixReference::Ttc;

        return [
            'ht' => $reference === PrixReference::Ht ? $prixCentimes : $this->htDepuisTtcAttendu($prixCentimes, $taux),
            'ttc' => $reference === PrixReference::Ttc ? $prixCentimes : null,
            'quantite' => $hasard->getInt(1, 999),
            'remise' => $hasard->getInt(1, 10) <= 7 ? 0 : $hasard->getInt(0, 10000),
            'taux' => $taux,
            'reference' => $reference,
        ];
    }

    /**
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string, reference: PrixReference}  $ligne
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function calculerLigne(array $ligne): array
    {
        return $this->calculateur()->calculer(
            $ligne['reference'],
            $ligne['ht'] / 100,
            $ligne['ttc'] === null ? null : $ligne['ttc'] / 100,
            (float) $ligne['taux'],
            $ligne['quantite'],
            $ligne['remise'] / 100,
        );
    }

    /**
     * Référence exacte d'une ligne : PU x quantité, puis remise arrondie au
     * centime -- séparément pour le HT et le TTC d'un prix TTC ; pour un
     * prix HT, puis la TVA du HT de la ligne arrondie au centime.
     *
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string, reference: PrixReference}  $ligne
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
            'ttc' => $ligne['reference'] === PrixReference::Ttc ? $net($ligne['ttc']) : $ht + $this->tvaAttendue($ht, $ligne['taux']),
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
     * @param  array{ht: int, ttc: int|null, quantite: int, remise: int, taux: string, reference: PrixReference}  $ligne
     */
    private function decrire(array $ligne): string
    {
        return sprintf(
            'PU HT %s / PU TTC %s (%s saisi, TVA %s %%), quantité %d, remise %s %%',
            $this->texte($ligne['ht']),
            $ligne['ttc'] === null ? '—' : $this->texte($ligne['ttc']),
            $ligne['reference']->getLabel(),
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
            'taux au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->prixUnitaires(PrixReference::Ttc, 10, 150), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'taux au-delà de 100 % (ligne)' => [fn (CalculateurLigne $c) => $c->calculer(PrixReference::Ht, 10, null, 150, 1), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'remise négative' => [fn (CalculateurLigne $c) => $c->calculer(PrixReference::Ttc, 10, 12, 20, 1, -10), 'La remise doit être comprise entre 0 et 100 %.'],
            'remise au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->calculer(PrixReference::Ht, 10, null, 20, 1, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'ligne au prix TTC sans PU TTC' => [fn (CalculateurLigne $c) => $c->calculer(PrixReference::Ttc, 10, null, 20, 1), 'Une ligne au prix TTC doit fournir son PU TTC.'],
            'escompte au-delà de 100 %' => [fn (CalculateurLigne $c) => $c->coutUnitaireNet(10, 0, 150), 'La remise doit être comprise entre 0 et 100 %.'],
            'taux au-delà de 100 % (prix de vente)' => [fn (CalculateurLigne $c) => $c->prixTtcArrondi(10, 150), 'Le taux de TVA doit être compris entre 0 et 100 %.'],
            'montant reçu absurde' => [fn (CalculateurLigne $c) => $c->soustraire('100000000000000000', 40), 'Montant hors limites.'],
            'paiement absurde' => [fn (CalculateurLigne $c) => $c->arrondirAuCentime(1e17), 'Montant hors limites.'],
            'prix x quantité qui déborde' => [fn (CalculateurLigne $c) => $c->calculer(PrixReference::Ht, 9999999999.99, null, 20, 999999), 'Montant hors limites.'],
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
        $this->assertSame(['prix_unitaire_ht' => 10.0, 'prix_unitaire_ttc' => 10.0], $this->calculateur()->prixUnitaires(PrixReference::Ttc, 10, 0));
        $this->assertSame(['montant_ht' => 10.0, 'montant_tva' => 10.0, 'montant_ttc' => 20.0], $this->calculateur()->calculer(PrixReference::Ht, 10, null, 100, 1));
        $this->assertSame(['montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0], $this->calculateur()->calculer(PrixReference::Ttc, 10, 12, 20, 3, 100));
    }

    /**
     * Le coût d'un article acheté ne s'arrondit pas au centime : 1,18 €
     * remisé de 15 % coûte 1,003 € ; 8,33 € remisés de 10 % puis escomptés
     * de 2 % coûtent 7,34706 €, soit 7,3471 € au dix-millième du CMUP.
     */
    public function test_le_cout_net_dun_article_achete_ne_sarrondit_pas_au_centime(): void
    {
        $this->assertSame(1.003, $this->calculateur()->coutUnitaireNet(1.18, 15));
        $this->assertSame(7.3471, $this->calculateur()->coutUnitaireNet(8.33, 10, 2));
        $this->assertSame(88.2, $this->calculateur()->coutUnitaireNet(90, 0, 2));
        $this->assertSame(0.0, $this->calculateur()->coutUnitaireNet(90, 100));
    }

    /**
     * Le prix de vente se calcule depuis le HT exact et ne s'arrondit qu'une
     * fois : (1,003 € + 10 €) x 1,2 = 13,2036 € -> 13,20 € ; à la dizaine,
     * 64,6668 € (53,889 € HT) -> 60 €, 65,00004 € (54,1667 € HT) -> 70 €.
     */
    public function test_le_prix_de_vente_ttc_ne_sarrondit_qu_une_fois(): void
    {
        $this->assertSame(13.2, $this->calculateur()->prixTtcArrondi(11.003, 20));
        $this->assertSame(60.0, $this->calculateur()->prixTtcArrondi(53.889, 20, pasCentimes: 1000));
        $this->assertSame(70.0, $this->calculateur()->prixTtcArrondi(54.1667, 20, pasCentimes: 1000));
    }

    public function test_sommer_et_soustraire_comptent_au_centime_exact(): void
    {
        $this->assertSame(0.3, $this->calculateur()->sommer([0.1, 0.2]));
        $this->assertSame(100.0, $this->calculateur()->sommer(array_fill(0, 1000, '0.10')));
        $this->assertSame(16.67, $this->calculateur()->soustraire(50, 33.33));
        $this->assertSame(-10.0, $this->calculateur()->soustraire('40.00', '30.00', 20));
        $this->assertSame(0.0, $this->calculateur()->sommer([null, '']));
    }
}
