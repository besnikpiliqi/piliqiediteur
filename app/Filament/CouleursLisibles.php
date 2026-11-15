<?php

namespace App\Filament;

use Filament\Support\Colors\Color;

/**
 * Les couleurs des trois panneaux, lisibles en clair comme en sombre
 * (décision de l'éditeur du 15/11/2026, lot DATEOP : contraste AA, 4,5:1
 * pour un texte) : les palettes de Tailwind que Filament prend par défaut
 * mettent un texte blanc sur la teinte 600 d'un bouton en clair et sur la
 * teinte 500 en sombre, et un texte de la teinte 600 sur la teinte 50
 * d'une pastille ou sur le gris 100 de l'entrée choisie du menu ; avec le
 * vert, l'orangé, le rouge, le bleu ou le bleu ciel, ces paires restent
 * sous 4,5:1. Chaque palette garde ses teintes claires et sombres, et ses
 * teintes 500 et 600 deviennent la première teinte plus foncée de la même
 * palette qui tient ces paires à 4,5:1 au moins ; la teinte 400, que le
 * mode sombre écrit sur une pastille de sa couleur à 10 % posée sur un
 * fond foncé (le gris 800), devient de même la première teinte plus claire
 * qui s'y lit à 4,5:1 au moins.
 */
final class CouleursLisibles
{
    /**
     * Le contraste minimal d'un texte (WCAG AA).
     */
    public const SEUIL = 4.5;

    /**
     * Le gris 100 de la palette grise (Zinc), fond de l'entrée choisie du
     * menu de côté.
     */
    private const GRIS_100 = '244, 244, 245';

    /**
     * Le gris 800 de la palette grise (Zinc), le plus clair des fonds
     * foncés où le mode sombre pose une pastille.
     */
    private const GRIS_800 = '39, 39, 42';

    /**
     * Les couleurs d'un panneau : sa couleur principale et celles des
     * pastilles, des boutons et des avis, chacune rendue lisible.
     *
     * @param  array<int, string>  $principale
     * @return array<string, array<int, string>>
     */
    public static function duPanneau(array $principale): array
    {
        return [
            'primary' => self::lisible($principale),
            'danger' => self::lisible(Color::Red),
            'info' => self::lisible(Color::Blue),
            'success' => self::lisible(Color::Green),
            'warning' => self::lisible(Color::Amber),
            'gray' => Color::Zinc,
        ];
    }

    /**
     * Cette palette, ses teintes 500 et 600 remplacées par la première
     * teinte de la palette, de la même ou plus foncée, qui porte un texte
     * blanc à 4,5:1 au moins ; la 600 doit aussi se lire à 4,5:1 sur la
     * teinte 50 et sur le gris 100.
     *
     * @param  array<int, string>  $palette
     * @return array<int, string>
     */
    public static function lisible(array $palette): array
    {
        $teintes = [500, 600, 700, 800, 900, 950];
        $blanc = '255, 255, 255';
        $cinqCents = self::premiere($palette, $teintes, fn (string $teinte): bool => self::rapport($teinte, $blanc) >= self::SEUIL);
        $sixCents = self::premiere($palette, array_slice($teintes, 1), fn (string $teinte): bool => self::rapport($teinte, $blanc) >= self::SEUIL
            && self::rapport($teinte, $palette[50]) >= self::SEUIL
            && self::rapport($teinte, self::GRIS_100) >= self::SEUIL
            && self::luminance($teinte) <= self::luminance($cinqCents));

        $quatreCents = self::premiere($palette, [400, 300, 200, 100, 50], fn (string $teinte): bool => self::rapport($teinte, self::dessus($teinte, 0.1, self::GRIS_800)) >= self::SEUIL);

        return array_replace($palette, [400 => $quatreCents, 500 => $cinqCents, 600 => $sixCents]);
    }

    /**
     * Le rapport de contraste de deux couleurs « r, g, b » (WCAG 2).
     */
    public static function rapport(string $premiere, string $seconde): float
    {
        $luminances = [self::luminance($premiere), self::luminance($seconde)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * La couleur « r, g, b » de cette couleur posée avec cette opacité sur
     * ce fond.
     */
    public static function dessus(string $couleur, float $opacite, string $fond): string
    {
        $haut = array_map(intval(...), explode(',', $couleur));
        $bas = array_map(intval(...), explode(',', $fond));

        return implode(', ', array_map(static fn (int $h, int $b): int => (int) round($h * $opacite + $b * (1 - $opacite)), $haut, $bas));
    }

    /**
     * La première de ces teintes de la palette qui remplit la condition,
     * sinon la dernière.
     *
     * @param  array<int, string>  $palette
     * @param  list<int>  $teintes
     * @param  callable(string): bool  $condition
     */
    private static function premiere(array $palette, array $teintes, callable $condition): string
    {
        foreach ($teintes as $teinte) {
            if ($condition($palette[$teinte])) {
                return $palette[$teinte];
            }
        }

        return $palette[end($teintes)];
    }

    /**
     * La luminance relative d'une couleur « r, g, b » (WCAG 2).
     */
    private static function luminance(string $couleur): float
    {
        $canaux = array_map(static function (string $canal): float {
            $valeur = ((int) trim($canal)) / 255;

            return $valeur <= 0.03928 ? $valeur / 12.92 : (($valeur + 0.055) / 1.055) ** 2.4;
        }, explode(',', $couleur));

        return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
    }
}
