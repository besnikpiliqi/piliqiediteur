<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\DessinDeLEcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * Le dessin de la carte « Écart des prix d'achat » (A11 de la maquette) : pour
 * chacun des douze mois choisis, l'arbre est celui du relevé de la maquette
 * (verite.json), nœud pour nœud ; ici, en détail, les graduations, la hauteur
 * minimale de 2 d'une barre, le losange, l'étiquette en gras du seul mois
 * choisi, les libellés accessibles des colonnes, les positions « NaN » d'un
 * jeu sans hausse ni baisse et le pas de l'axe, celui de la maquette jusqu'à
 * 12 000 € d'étendue puis la suite 2 000, 5 000, 10 000… (D76, B7).
 */
class DessinDeLEcartDesPrixDAchatTest extends TestCase
{
    private const INSECABLE = "\u{00A0}";

    private const MOINS = "\u{2212}";

    /**
     * @return array<string, mixed>
     */
    private static function calculDeLaMaquette(): array
    {
        return EcartDesPrixDAchat::calculer(LectureDesFixtures::verite()['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());
    }

    /**
     * Un calcul à partir de produits écrits (nom, [[mois, jour, quantité, prix en centimes]…]).
     *
     * @param  list<array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>  $produits
     * @return array<string, mixed>
     */
    private static function calculDe(array $produits): array
    {
        return EcartDesPrixDAchat::calculer(
            array_map(fn (array $produit): array => ['nom' => $produit[0], 'achats' => array_map(fn (array $achat): array => ['k' => $achat[0], 'j' => $achat[1], 'q' => $achat[2], 'c' => $achat[3]], $produit[1])], $produits),
            LectureDesFixtures::moisDeLaMaquette(),
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function moisChoisis(): array
    {
        $cas = [];

        for ($mois = 0; $mois < 12; $mois++) {
            $cas["mois choisi {$mois}"] = [$mois];
        }

        return $cas;
    }

    #[DataProvider('moisChoisis')]
    public function test_l_arbre_est_celui_de_la_maquette_pour_chaque_mois_choisi(int $choisi): void
    {
        $attendu = LectureDesFixtures::verite()['etats']['avant'][$choisi]['dessin']['enfants'];

        $obtenu = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), $choisi);

        $ecart = LectureDesFixtures::premierEcart($obtenu, $attendu);
        $this->assertNull($ecart, 'Premier écart avec le dessin de la maquette : '.$ecart);
        $this->assertSame($attendu, $obtenu);
    }

    public function test_les_graduations_de_la_maquette(): void
    {
        $noeuds = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), 11);
        $attendu = [
            [self::MOINS.'20 €', '236', '240', '1', 'var(--grille)'],
            [self::MOINS.'10 €', '191.60000000000002', '195.60000000000002', '1', 'var(--grille)'],
            ['0 €', '147.2', '151.2', '1.5', 'var(--doux)'],
            ['+10 €', '102.80000000000001', '106.80000000000001', '1', 'var(--grille)'],
            ['+20 €', '58.400000000000006', '62.400000000000006', '1', 'var(--grille)'],
            ['+30 €', '14', '18', '1', 'var(--grille)'],
        ];

        foreach ($attendu as $rang => [$texte, $yLigne, $yTexte, $epaisseur, $couleur]) {
            $ligne = $noeuds[$rang * 2];
            $legende = $noeuds[$rang * 2 + 1];

            $this->assertSame('line', $ligne['t']);
            $this->assertSame(['x1' => '58', 'x2' => '510', 'y1' => $yLigne, 'y2' => $yLigne, 'stroke' => $couleur, 'stroke-width' => $epaisseur], $ligne['a']);
            $this->assertSame('text', $legende['t']);
            $this->assertSame(['x' => '50', 'y' => $yTexte, 'text-anchor' => 'end', 'font-size' => '12', 'fill' => 'var(--doux)'], $legende['a']);
            $this->assertSame($texte, $legende['x']);
        }

        $this->assertSame('g', $noeuds[12]['t']);
        $this->assertCount(12 + 12, $noeuds);
    }

    public function test_une_colonne_a_ses_elements_dans_l_ordre_de_la_maquette(): void
    {
        $noeuds = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), 0);
        $colonne = $noeuds[12];

        $this->assertSame(
            ['class' => 'colonne', 'tabindex' => '0', 'role' => 'button', 'aria-label' => 'déc. 2025 : hausses +8,65'.self::INSECABLE.'€, baisses '.self::MOINS.'13,22'.self::INSECABLE.'€, total '.self::MOINS.'4,57'.self::INSECABLE.'€', 'aria-pressed' => 'true'],
            $colonne['a'],
        );
        $this->assertNull($colonne['x']);
        $this->assertSame(['rect', 'rect', 'rect', 'rect', 'polygon', 'text'], array_column($colonne['f'], 't'));
        $this->assertSame(['x' => '58', 'y' => '14', 'width' => '37.666666666666664', 'height' => '222', 'fill' => 'transparent'], $colonne['f'][0]['a']);
        $this->assertSame(['x' => '65.91', 'y' => '108.79400000000001', 'width' => '21.846666666666664', 'height' => '38.40599999999998', 'rx' => '3', 'fill' => 'var(--plus)'], $colonne['f'][1]['a']);
        $this->assertSame(['x' => '65.91', 'y' => '147.2', 'width' => '21.846666666666664', 'height' => '58.696799999999996', 'rx' => '3', 'fill' => 'var(--moins)'], $colonne['f'][2]['a']);
        $this->assertSame(['class' => 'focus', 'x' => '63.91', 'y' => '106.79400000000001', 'width' => '25.846666666666664', 'height' => '101.10279999999997', 'rx' => '4', 'fill' => 'none', 'stroke' => 'var(--texte)', 'stroke-width' => '2'], $colonne['f'][3]['a']);
        $this->assertSame(['points' => '76.83333333333333,161.9908 82.33333333333333,167.4908 76.83333333333333,172.9908 71.33333333333333,167.4908', 'fill' => 'var(--texte)', 'stroke' => 'var(--carte)', 'stroke-width' => '1.5'], $colonne['f'][4]['a']);
        $this->assertSame(['x' => '76.83333333333333', 'y' => '258', 'text-anchor' => 'middle', 'font-size' => '12', 'font-weight' => '700', 'fill' => 'var(--texte)'], $colonne['f'][5]['a']);
        $this->assertSame('déc.', $colonne['f'][5]['x']);
    }

    public function test_seul_le_mois_choisi_a_son_cadre_son_etiquette_en_gras_et_aria_pressed(): void
    {
        foreach ([0, 5, 11] as $choisi) {
            $noeuds = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), $choisi);
            $colonnes = array_slice($noeuds, 12);
            $this->assertCount(12, $colonnes);

            $pressees = array_map(fn (array $colonne): string => $colonne['a']['aria-pressed'], $colonnes);
            $attendu = array_fill(0, 12, 'false');
            $attendu[$choisi] = 'true';
            $this->assertSame($attendu, $pressees);

            foreach ($colonnes as $rang => $colonne) {
                $etiquette = $colonne['f'][array_key_last($colonne['f'])];
                $this->assertSame($rang === $choisi ? '700' : '400', $etiquette['a']['font-weight']);
                $this->assertSame($rang === $choisi ? 'var(--texte)' : 'var(--doux)', $etiquette['a']['fill']);

                foreach ($colonne['f'] as $element) {
                    if (($element['a']['class'] ?? '') === 'focus') {
                        $this->assertSame($rang === $choisi ? 'var(--texte)' : 'transparent', $element['a']['stroke']);
                    }
                }
            }
        }
    }

    public function test_le_mois_sans_achat_a_un_tiret_et_ni_barre_ni_cadre_ni_losange(): void
    {
        $colonne = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), 11)[12 + 8];

        $this->assertSame('août 2026 : aucun achat', $colonne['a']['aria-label']);
        $this->assertSame(['rect', 'text', 'text'], array_column($colonne['f'], 't'));
        $this->assertSame('—', $colonne['f'][1]['x']);
        $this->assertSame(['x' => '378.1666666666667', 'y' => '139.2', 'text-anchor' => 'middle', 'font-size' => '12', 'fill' => 'var(--doux)'], $colonne['f'][1]['a']);
        $this->assertSame('août', $colonne['f'][2]['x']);
    }

    public function test_un_mois_sans_hausses_n_a_pas_de_barre_orange_et_sans_baisses_pas_de_barre_verte(): void
    {
        $colonnes = array_slice(DessinDeLEcartDesPrixDAchat::noeuds(self::calculDeLaMaquette(), 11), 12);

        $fevrier = array_column($colonnes[2]['f'], 'a');
        $this->assertSame([], array_filter($fevrier, fn (array $a): bool => ($a['fill'] ?? '') === 'var(--moins)'));
        $this->assertCount(1, array_filter($fevrier, fn (array $a): bool => ($a['fill'] ?? '') === 'var(--plus)'));

        $mai = array_column($colonnes[5]['f'], 'a');
        $this->assertSame([], array_filter($mai, fn (array $a): bool => ($a['fill'] ?? '') === 'var(--plus)'));
        $this->assertCount(1, array_filter($mai, fn (array $a): bool => ($a['fill'] ?? '') === 'var(--moins)'));
    }

    public function test_un_mois_ou_l_on_a_achete_sans_achat_comparable_garde_son_libelle_a_zero_et_un_tiret(): void
    {
        $calcul = self::calculDe([['A', [[2, 5, 4, 100]]], ['B', [[2, 9, 2, 250]]]]);

        // L'axe a une seule graduation (deux éléments) : la colonne du mois 2 est le quatrième élément.
        $colonne = DessinDeLEcartDesPrixDAchat::noeuds($calcul, 2)[2 + 2];

        $this->assertSame('févr. 2026 : hausses 0,00'.self::INSECABLE.'€, baisses 0,00'.self::INSECABLE.'€, total 0,00'.self::INSECABLE.'€', $colonne['a']['aria-label']);
        $this->assertSame(['rect', 'text', 'text'], array_column($colonne['f'], 't'));
        $this->assertSame('—', $colonne['f'][1]['x']);
    }

    public function test_une_barre_a_au_moins_deux_de_hauteur(): void
    {
        $calcul = self::calculDe([
            ['A', [[0, 1, 1, 10000], [0, 2, 1, 10001]]],
            ['B', [[1, 1, 1, 20000], [1, 2, 1, 10000]]],
        ]);

        // L'axe va de −100 € à +20 € par pas de 20 € : sept graduations, soit quatorze éléments avant la première colonne.
        $colonne = DessinDeLEcartDesPrixDAchat::noeuds($calcul, 0)[14];
        $barre = $colonne['f'][1];

        $this->assertSame('var(--plus)', $barre['a']['fill']);
        $this->assertSame('2', $barre['a']['height']);
    }

    public function test_un_jeu_sans_hausse_ni_baisse_donne_des_positions_nan_comme_la_maquette(): void
    {
        $noeuds = DessinDeLEcartDesPrixDAchat::noeuds(self::calculDe([]), 11);

        $this->assertSame('line', $noeuds[0]['t']);
        $this->assertSame(['x1' => '58', 'x2' => '510', 'y1' => 'NaN', 'y2' => 'NaN', 'stroke' => 'var(--doux)', 'stroke-width' => '1.5'], $noeuds[0]['a']);
        $this->assertSame(['x' => '50', 'y' => 'NaN', 'text-anchor' => 'end', 'font-size' => '12', 'fill' => 'var(--doux)'], $noeuds[1]['a']);
        $this->assertSame('0 €', $noeuds[1]['x']);
        $this->assertCount(2 + 12, $noeuds);

        $colonne = $noeuds[2];
        $this->assertSame('déc. 2025 : aucun achat', $colonne['a']['aria-label']);
        $this->assertSame('NaN', $colonne['f'][1]['a']['y']);
        $this->assertSame('déc.', $colonne['f'][2]['x']);
    }

    public function test_des_achats_comparables_tous_au_meme_prix_donnent_des_positions_nan_mais_gardent_leur_cadre_et_leur_losange(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 4, 100], [0, 2, 4, 100]]]]);

        $colonne = DessinDeLEcartDesPrixDAchat::noeuds($calcul, 0)[2];

        $this->assertSame(['rect', 'rect', 'polygon', 'text'], array_column($colonne['f'], 't'));
        $this->assertSame('NaN', $colonne['f'][1]['a']['y']);
        $this->assertSame('NaN,NaN NaN,NaN NaN,NaN NaN,NaN', preg_replace('/-?\d[\d.]*/', 'NaN', $colonne['f'][2]['a']['points']));
    }

    /**
     * Le pas de l'axe selon l'étendue en euros : [étendue, pas].
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public static function pas(): array
    {
        return [
            'zéro' => [0.0, 10.0],
            'l’étendue de la maquette (41,21 €)' => [41.21, 10.0],
            '60 €, six intervalles de 10' => [60.0, 10.0],
            '61 €' => [61.0, 20.0],
            '150 €, six intervalles de 25' => [150.0, 25.0],
            '151 €' => [151.0, 50.0],
            '600 €' => [600.0, 100.0],
            '601 €' => [601.0, 200.0],
            '3 000 €' => [3000.0, 500.0],
            '6 000 €, six intervalles de 1 000' => [6000.0, 1000.0],
            '6 001 €' => [6001.0, 2000.0],
            '12 000 €, la dernière étendue de la maquette' => [12000.0, 2000.0],
            '12 001 €, la suite continue' => [12001.0, 5000.0],
            '20 000 €' => [20000.0, 5000.0],
            '30 000 €' => [30000.0, 5000.0],
            '30 001 €' => [30001.0, 10000.0],
            '60 000 €' => [60000.0, 10000.0],
            '60 001 €' => [60001.0, 20000.0],
            '120 000 €' => [120000.0, 20000.0],
            '120 001 €' => [120001.0, 50000.0],
            '300 000 €' => [300000.0, 50000.0],
            '300 001 €' => [300001.0, 100000.0],
            '3 758 570,05 €' => [3758570.05, 1000000.0],
            'dix milliards d’euros' => [1e10, 2000000000.0],
        ];
    }

    #[DataProvider('pas')]
    public function test_le_pas_de_l_axe(float $etendue, float $attendu): void
    {
        $this->assertSame($attendu, DessinDeLEcartDesPrixDAchat::pasDeLAxe($etendue));
    }

    public function test_un_jeu_de_vingt_mille_euros_d_etendue_a_au_plus_douze_graduations_et_un_pas_de_la_suite(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 1, 100], [0, 2, 1, 2000100]]]]);

        $noeuds = DessinDeLEcartDesPrixDAchat::noeuds($calcul, 11);
        $graduations = array_values(array_filter($noeuds, fn (array $noeud): bool => $noeud['t'] === 'text' && ($noeud['a']['text-anchor'] ?? '') === 'end'));

        $this->assertLessThanOrEqual(12, count($graduations));
        $this->assertSame(['0 €', '+5000 €', '+10000 €', '+15000 €', '+20000 €'], array_column($graduations, 'x'));
    }

    public function test_aucun_jeu_ne_depasse_douze_graduations(): void
    {
        foreach ([0.01, 7.0, 99.0, 1234.5, 11999.0, 12001.0, 2.5e5, 8.3e7, 4.9e9] as $etendue) {
            $pas = DessinDeLEcartDesPrixDAchat::pasDeLAxe($etendue);

            $this->assertLessThanOrEqual(6, $etendue / $pas);
            $this->assertLessThanOrEqual(12, (int) ceil($etendue / $pas) + 2, 'Étendue '.$etendue.' : trop de graduations.');
        }
    }
}
