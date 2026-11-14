<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Le calcul des montants (voir CalculateurLigneTest) comparé, sur des
 * dizaines de milliers de cas, à une référence indépendante :
 * l'arithmétique décimale exacte de brick/math, arrondie au centime au
 * demi supérieur -- tous les prix de 0,01 € à 100,00 € à chaque taux, des
 * lignes, des ventes, des achats, des factures et des séries de retours
 * tirées au hasard. Le hasard est tiré d'une graine fixe (GRAINE) : un
 * échec se rejoue toujours à l'identique, et son message donne le cas
 * fautif.
 */
class CalculateurLigneAuHasardTest extends TestCase
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
     * Les 10 000 prix TTC de 0,01 € à 100,00 €, pour chaque taux : le PU HT
     * affiché est exactement le TTC moins la TVA d'un article, TTC x taux ÷
     * (1 + taux), arrondie au centime (décision de l'éditeur du
     * 14/11/2026).
     */
    public function test_le_pu_ht_affiche_est_exact_sur_tous_les_prix_de_0_01_a_100_euros(): void
    {
        $verifications = 0;

        foreach (self::TAUX as $taux) {
            for ($ttcCentimes = 1; $ttcCentimes <= 10000; $ttcCentimes++) {
                $ht = $this->calculateur()->prixUnitaireHtAffiche($ttcCentimes, $this->centiemes($taux));

                $this->assertSame($ttcCentimes - $this->tvaDUnArticleAttendue($ttcCentimes, $taux), $ht, "TTC {$this->texte($ttcCentimes)} à {$taux} %");
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
                $ttc = $this->calculateur()->prixUnitaireTtcDepuisHt($htCentimes, $this->centiemes($taux));

                $this->assertSame($this->ttcDepuisHtAttendu($htCentimes, $taux), $ttc, "HT {$this->texte($htCentimes)} à {$taux} %");
                $verifications++;
            }
        }

        $this->assertSame(70000, $verifications);
    }

    /**
     * Tous les montants à demi-centime de 0,005 € à 100,005 € (le piège du
     * flottant : 8.325 vaut 8.32499999... en binaire), ainsi que leurs
     * voisins juste en dessous et au-dessus, lus au centime comme toute
     * saisie (voir SaisieDecimale::enCentiemes()), écrits en texte comme
     * transmis en flottant par un écran.
     */
    public function test_larrondi_au_centime_est_exact_sur_tous_les_demi_centimes_jusqua_100_euros(): void
    {
        $verifications = 0;

        for ($centimes = 0; $centimes <= 10000; $centimes++) {
            foreach ([4 => $centimes, 5 => $centimes + 1, 6 => $centimes + 1] as $milliemeFinal => $attendu) {
                $texte = sprintf('%d.%02d%d', intdiv($centimes, 100), $centimes % 100, $milliemeFinal);

                $this->assertSame($attendu, SaisieDecimale::enCentiemes($texte), $texte);
                $this->assertSame($attendu, SaisieDecimale::enCentiemes((float) $texte), "{$texte} en flottant");
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
                $net = $this->netAttendu(intdiv($lignes[$index]['prix_unitaire_ht'], 10000), $lignes[$index]['quantite'], $lignes[$index]['remise_pourcentage']);

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
            ], [$totaux['total_ht'], $totaux['total_tva'], $totaux['total_ttc']], $cas);
            $this->assertSame($totaux['total_ttc'], $totaux['total_ht'] + $totaux['total_tva'], $cas);
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
            $cas = sprintf('achat n° %d, PU HT %s (TVA %s %%), quantité %d, remise %s %%, TVA de la ligne %s', $i, $this->texte(intdiv($achetee['prix_unitaire_ht'], 10000)), $taux, $achetee['quantite'], $this->texte($achetee['remise_pourcentage']), $this->texte($achetee['montant_tva']));
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
                    $ht = $this->netAttendu(intdiv($achetee['prix_unitaire_ht'], 10000), $rendus, $achetee['remise_pourcentage']);

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

    /**
     * La TVA comprise dans un prix TTC : TTC x taux ÷ (1 + taux), au centime
     * le plus proche, un demi-centime au centime supérieur.
     */
    private function tvaDUnArticleAttendue(int $ttcCentimes, string $taux): int
    {
        $taux = BigDecimal::of($taux)->dividedBy(100, 4);

        return BigDecimal::ofUnscaledValue($ttcCentimes, 2)->multipliedBy($taux)
            ->dividedBy(BigDecimal::one()->plus($taux), 2, RoundingMode::HalfUp)
            ->getUnscaledValue()
            ->toInt();
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
}
