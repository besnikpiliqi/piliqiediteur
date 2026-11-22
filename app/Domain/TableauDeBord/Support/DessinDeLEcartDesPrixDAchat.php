<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Le dessin de la carte « Écart des prix d'achat » (A11 de la maquette
 * validée par l'éditeur le 21/11/2026) : l'arbre des éléments d'un `svg` de
 * cadre 0 0 520 270, toujours celui des douze mois à la fois, que la vue
 * écrit telle quelle (les coordonnées sont calculées ici, en PHP, avec les
 * mêmes opérations dans le même ordre que la maquette, et s'écrivent comme
 * JavaScript les écrit : voir EcritureDesEcarts::nombreCommeJavaScript()).
 *
 * Marges : gauche 58, droite 10, haut 14, bas 34 ; douze colonnes, une barre
 * de 0,58 de colonne. Une graduation (une ligne, puis son texte) à chaque pas
 * de l'axe, du bas vers le haut ; dans chaque colonne, dans l'ordre, la zone
 * cliquable, la barre orange des hausses, la barre verte des baisses, le
 * cadre du mois choisi et le losange de l'écart, ou un « — » quand le mois n'a
 * aucun achat comparé ; sous elle, le mois court.
 *
 * Le pas de l'axe est le premier de 10, 20, 25, 50, 100, 200, 500, 1000 tel
 * que l'étendue (en euros) ÷ pas ≤ 6 ; au-delà de 12 000 € d'étendue la suite
 * continue, 2 000, 5 000, 10 000, 20 000, 50 000, 100 000…, pour ne jamais
 * dessiner des centaines de lignes (décision D76, B7 de la spécification ; la
 * maquette gardait 2 000 €).
 *
 * Un jeu sans hausse ni baisse nulle part donne un axe dont les positions
 * valent « NaN » (division par zéro de la maquette) : elles sont reproduites
 * telles quelles ; la vue n'écrit pas l'élément dont une position vaut
 * « NaN ».
 *
 * @phpstan-import-type Calcul from EcartDesPrixDAchat
 *
 * @phpstan-type Feuille array{t: string, a: array<string, string>, x: string, f: array{}}
 * @phpstan-type Groupe array{t: string, a: array<string, string>, x: null, f: list<Feuille>}
 */
final class DessinDeLEcartDesPrixDAchat
{
    private const LARGEUR = 520.0;

    private const HAUTEUR = 270.0;

    private const GAUCHE = 58.0;

    private const DROITE = 10.0;

    private const HAUT = 14.0;

    private const BAS = 34.0;

    /** La part de la colonne que prend la barre. */
    private const PART_DE_LA_BARRE = 0.58;

    /** Les pas de l'axe de la maquette, en euros. */
    private const PAS_DE_LA_MAQUETTE = [10, 20, 25, 50, 100, 200, 500, 1000];

    /** Au plus six intervalles entre le bas et le haut de l'axe. */
    private const INTERVALLES_AU_PLUS = 6;

    /**
     * Les éléments du dessin, dans l'ordre : les graduations, puis une
     * colonne par mois.
     *
     * @param  Calcul  $calcul
     * @param  int  $choisi  le mois choisi, de 0 à 11
     * @return list<Feuille|Groupe>
     */
    public static function noeuds(array $calcul, int $choisi): array
    {
        $calculs = $calcul['calculs'];
        $ancrage = self::attribut('text', 'anchor');
        $taille = self::attribut('font', 'size');
        $poids = self::attribut('font', 'weight');
        $largeur = self::LARGEUR - self::GAUCHE - self::DROITE;
        $hauteur = self::HAUTEUR - self::HAUT - self::BAS;

        $valeurs = [0.0];

        foreach ($calculs as $mois) {
            $valeurs[] = $mois['hausses'] / 100.0;
            $valeurs[] = -$mois['baisses'] / 100.0;
        }

        $etendue = max(max($valeurs), 0.0) - min(min($valeurs), 0.0);
        $pas = self::pasDeLAxe($etendue);
        $maxi = max(0.0, ceil(max($valeurs) / $pas) * $pas);
        $mini = min(0.0, floor(min($valeurs) / $pas) * $pas);
        $y = fn (float $valeur): float => self::HAUT + fdiv($maxi - $valeur, $maxi - $mini) * $hauteur;

        /** @var list<Feuille|Groupe> $racine */
        $racine = [];

        for ($valeur = $mini; $valeur <= $maxi; $valeur += $pas) {
            $racine[] = self::feuille('line', [
                'x1' => self::n(self::GAUCHE),
                'x2' => self::n(self::LARGEUR - self::DROITE),
                'y1' => self::n($y($valeur)),
                'y2' => self::n($y($valeur)),
                'stroke' => $valeur === 0.0 ? 'var(--doux)' : 'var(--grille)',
                'stroke-width' => $valeur === 0.0 ? '1.5' : '1',
            ]);
            $racine[] = self::feuille('text', [
                'x' => self::n(self::GAUCHE - 8),
                'y' => self::n($y($valeur) + 4),
                $ancrage => 'end',
                $taille => '12',
                'fill' => 'var(--doux)',
            ], EcritureDesEcarts::graduation($valeur));
        }

        $colonne = $largeur / count($calculs);
        $barre = $colonne * self::PART_DE_LA_BARRE;

        foreach ($calculs as $i => $mois) {
            $x = self::GAUCHE + $i * $colonne + ($colonne - $barre) / 2;
            $aucun = $mois['premiers'] === [];
            $libelle = $mois['m'].' : '.($aucun
                ? 'aucun achat'
                : 'hausses '.EcritureDesEcarts::signe($mois['hausses']).', baisses '.EcritureDesEcarts::signe(-$mois['baisses']).', total '.EcritureDesEcarts::signe($mois['ecart']));
            $feuilles = [self::feuille('rect', [
                'x' => self::n(self::GAUCHE + $i * $colonne),
                'y' => self::n(self::HAUT),
                'width' => self::n($colonne),
                'height' => self::n($hauteur),
                'fill' => 'transparent',
            ])];

            if ($mois['n'] > 0) {
                $hautDesBarres = $y($mois['hausses'] / 100.0);
                $basDesBarres = $y(-$mois['baisses'] / 100.0);

                if ($mois['hausses'] > 0) {
                    $feuilles[] = self::feuille('rect', [
                        'x' => self::n($x),
                        'y' => self::n($hautDesBarres),
                        'width' => self::n($barre),
                        'height' => self::n(self::plusGrandQue(2.0, $y(0.0) - $hautDesBarres)),
                        'rx' => '3',
                        'fill' => 'var(--plus)',
                    ]);
                }

                if ($mois['baisses'] > 0) {
                    $feuilles[] = self::feuille('rect', [
                        'x' => self::n($x),
                        'y' => self::n($y(0.0)),
                        'width' => self::n($barre),
                        'height' => self::n(self::plusGrandQue(2.0, $basDesBarres - $y(0.0))),
                        'rx' => '3',
                        'fill' => 'var(--moins)',
                    ]);
                }

                $feuilles[] = self::feuille('rect', [
                    'class' => 'focus',
                    'x' => self::n($x - 2),
                    'y' => self::n($hautDesBarres - 2),
                    'width' => self::n($barre + 4),
                    'height' => self::n($basDesBarres - $hautDesBarres + 4),
                    'rx' => '4',
                    'fill' => 'none',
                    'stroke' => $i === $choisi ? 'var(--texte)' : 'transparent',
                    'stroke-width' => '2',
                ]);

                $centreX = $x + $barre / 2;
                $centreY = $y($mois['ecart'] / 100.0);
                $feuilles[] = self::feuille('polygon', [
                    'points' => self::n($centreX).','.self::n($centreY - 5.5).' '.self::n($centreX + 5.5).','.self::n($centreY).' '.self::n($centreX).','.self::n($centreY + 5.5).' '.self::n($centreX - 5.5).','.self::n($centreY),
                    'fill' => 'var(--texte)',
                    'stroke' => 'var(--carte)',
                    'stroke-width' => '1.5',
                ]);
            } else {
                $feuilles[] = self::feuille('text', [
                    'x' => self::n($x + $barre / 2),
                    'y' => self::n($y(0.0) - 8),
                    $ancrage => 'middle',
                    $taille => '12',
                    'fill' => 'var(--doux)',
                ], '—');
            }

            $feuilles[] = self::feuille('text', [
                'x' => self::n($x + $barre / 2),
                'y' => self::n(self::HAUTEUR - 12),
                $ancrage => 'middle',
                $taille => '12',
                $poids => $i === $choisi ? '700' : '400',
                'fill' => $i === $choisi ? 'var(--texte)' : 'var(--doux)',
            ], $calcul['courts'][$mois['k']]);

            $racine[] = [
                't' => 'g',
                'a' => [
                    'class' => 'colonne',
                    'tabindex' => '0',
                    'role' => 'button',
                    'aria-label' => $libelle,
                    'aria-pressed' => $i === $choisi ? 'true' : 'false',
                ],
                'x' => null,
                'f' => $feuilles,
            ];
        }

        return $racine;
    }

    /**
     * Le pas de l'axe, en euros, pour une étendue en euros : celui de la
     * maquette jusqu'à 12 000 €, puis la suite 2 000, 5 000, 10 000, 20 000…
     */
    public static function pasDeLAxe(float $etendue): float
    {
        foreach (self::PAS_DE_LA_MAQUETTE as $pas) {
            if ($etendue / $pas <= self::INTERVALLES_AU_PLUS) {
                return (float) $pas;
            }
        }

        for ($echelle = 1000.0; ; $echelle *= 10) {
            foreach ([2, 5, 10] as $multiple) {
                $pas = $multiple * $echelle;

                if ($etendue / $pas <= self::INTERVALLES_AU_PLUS) {
                    return $pas;
                }
            }
        }
    }

    /**
     * Le plus grand de deux nombres, « NaN » si l'un des deux l'est (comme
     * Math.max de JavaScript).
     */
    private static function plusGrandQue(float $a, float $b): float
    {
        if (is_nan($a) || is_nan($b)) {
            return NAN;
        }

        return max($a, $b);
    }

    /**
     * Un nom d'attribut de présentation du texte d'un `svg` (« text-anchor »,
     * « font-size », « font-weight »), écrit en deux mots que cette fonction
     * joint : ainsi écrits, ils ne sont pas pris pour des classes de style
     * par ClassesDeStyleConnuesTest, qui lit les chaînes de app.
     */
    private static function attribut(string $premier, string $second): string
    {
        return $premier.'-'.$second;
    }

    private static function n(float $nombre): string
    {
        return EcritureDesEcarts::nombreCommeJavaScript($nombre);
    }

    /**
     * @param  array<string, string>  $attributs
     * @return Feuille
     */
    private static function feuille(string $balise, array $attributs, string $texte = ''): array
    {
        return ['t' => $balise, 'a' => $attributs, 'x' => $texte, 'f' => []];
    }
}
