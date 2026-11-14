<?php

namespace App\Domain\Tenancy\Support;

use UnexpectedValueException;

/**
 * Un réglage de l'application (config()), lu pour ce que son fichier de
 * configuration garantit : un texte, un texte facultatif (une variable
 * d'environnement qui peut manquer), un entier (une durée, une limite que
 * le fichier convertit lui-même) ou un tableau. Un réglage d'un autre type
 * (absent, ou mal écrit dans le fichier ou dans l'environnement) est
 * refusé par une exception qui le nomme, au lieu d'être converti ou
 * utilisé pour ce qu'il n'est pas.
 */
final class Reglage
{
    public const REFUS_TEXTE = 'Le réglage « %s » n\'est pas un texte : %s.';

    public const REFUS_TEXTE_OU_NUL = 'Le réglage « %s » n\'est ni un texte ni vide : %s.';

    public const REFUS_ENTIER = 'Le réglage « %s » n\'est pas un entier : %s.';

    public const REFUS_TABLEAU = 'Le réglage « %s » n\'est pas un tableau : %s.';

    /**
     * @throws UnexpectedValueException un réglage qui n'est pas un texte
     */
    public static function texte(string $cle): string
    {
        $valeur = config($cle);

        return is_string($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TEXTE, $cle, get_debug_type($valeur)));
    }

    /**
     * @throws UnexpectedValueException un réglage ni texte ni nul
     */
    public static function texteOuNul(string $cle): ?string
    {
        $valeur = config($cle);

        return $valeur === null || is_string($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TEXTE_OU_NUL, $cle, get_debug_type($valeur)));
    }

    /**
     * @throws UnexpectedValueException un réglage qui n'est pas un entier
     */
    public static function entier(string $cle): int
    {
        $valeur = config($cle);

        return is_int($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_ENTIER, $cle, get_debug_type($valeur)));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException un réglage qui n'est pas un tableau
     */
    public static function tableau(string $cle): array
    {
        $valeur = config($cle);

        return is_array($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TABLEAU, $cle, get_debug_type($valeur)));
    }
}
