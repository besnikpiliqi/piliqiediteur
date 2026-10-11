<?php

namespace App\Domain\Tenancy\Services;

use Illuminate\Support\Facades\Hash;

/**
 * Les codes de confirmation des courriers recommandés (voir
 * EnvoyerCodeDeConfirmationAction) : douze caractères tirés par un
 * générateur cryptographique dans l'alphabet de Crockford, sans I, L, O ni
 * U (60 bits), lus « XXXX-XXXX-XXXX ». Seule leur empreinte (Hash::make,
 * salée) se garde ; la saisie se compare à elle sans tenir compte de la
 * casse, des espaces ni des tirets, I et L valant 1 et O valant 0, comme le
 * veut l'alphabet de Crockford.
 */
class CodesDeConfirmation
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LONGUEUR = 12;

    /**
     * Un nouveau code, tel que le pli l'imprime : XXXX-XXXX-XXXX.
     */
    public function generer(): string
    {
        $code = '';

        for ($rang = 0; $rang < self::LONGUEUR; $rang++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 4));
    }

    public function hacher(string $code): string
    {
        return Hash::make(self::normaliser($code));
    }

    public function correspond(string $saisie, string $empreinte): bool
    {
        $normalisee = self::normaliser($saisie);

        return strlen($normalisee) === self::LONGUEUR && Hash::check($normalisee, $empreinte);
    }

    /**
     * La saisie telle qu'elle se compare : en majuscules, sans espace ni
     * tiret, I et L lus 1, O lu 0.
     */
    public static function normaliser(string $saisie): string
    {
        return strtr((string) preg_replace('/[^0-9A-Z]/', '', strtoupper($saisie)), ['I' => '1', 'L' => '1', 'O' => '0']);
    }
}
