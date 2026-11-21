<?php

namespace App\Domain\Tenancy\Services;

/**
 * Les identifiants qu'attribue l'INSEE à une entreprise et à ses
 * établissements, contrôlés sans rien interroger : le SIREN, 9 chiffres
 * dont le dernier est la clé de Luhn ; le NIC, 5 chiffres, qui complète le
 * SIREN en SIRET de 14 chiffres, clé de Luhn comprise, sauf pour les
 * établissements de La Poste (SIREN 356000000), dont la somme des chiffres
 * est un multiple de 5, hors son siège 35600000000048 (définitions INSEE
 * c2047, c1981 et c1841 ; python-stdnum, siren.py et siret.py). Le SIREN
 * fait uniquement de zéros n'est jamais valide.
 *
 * Ces contrôles ne s'appliquent qu'aux sociétés et à leurs magasins : les
 * fiches des clients et des fournisseurs gardent leurs propres règles.
 */
final class IdentifiantsInsee
{
    private const SIREN_DE_LA_POSTE = '356000000';

    private const SIRET_DU_SIEGE_DE_LA_POSTE = '35600000000048';

    public static function sirenValide(mixed $siren): bool
    {
        return is_string($siren)
            && preg_match('/^\d{9}$/D', $siren) === 1
            && $siren !== '000000000'
            && self::cleDeLuhnValide($siren);
    }

    public static function nicValide(mixed $nic): bool
    {
        return is_string($nic) && preg_match('/^\d{5}$/D', $nic) === 1;
    }

    public static function siretValide(mixed $siret): bool
    {
        if (! is_string($siret) || preg_match('/^\d{14}$/D', $siret) !== 1 || ! self::sirenValide(substr($siret, 0, 9))) {
            return false;
        }

        if (str_starts_with($siret, self::SIREN_DE_LA_POSTE) && $siret !== self::SIRET_DU_SIEGE_DE_LA_POSTE) {
            return array_sum(str_split($siret)) % 5 === 0;
        }

        return self::cleDeLuhnValide($siret);
    }

    /**
     * Le n° de TVA intracommunautaire français que forme un SIREN : FR, la
     * clé (12 + 3 × (SIREN mod 97)) mod 97 sur deux chiffres, puis le SIREN.
     * Proposé à l'inscription ; une société membre d'un groupe TVA en a un
     * autre.
     */
    public static function numeroTvaPropose(string $siren): string
    {
        return 'FR'.str_pad((string) ((12 + 3 * ((int) $siren % 97)) % 97), 2, '0', STR_PAD_LEFT).$siren;
    }

    /**
     * Les chiffres donnés suivis du chiffre qui rend leur clé de Luhn
     * valide : un SIREN fictif à partir de ses 8 premiers chiffres, un NIC
     * à partir du SIREN et des 4 premiers chiffres du NIC.
     */
    public static function avecSaCle(string $chiffres): string
    {
        for ($cle = 0; $cle <= 9; $cle++) {
            if (self::cleDeLuhnValide($chiffres.$cle)) {
                return $chiffres.$cle;
            }
        }

        return $chiffres;
    }

    private static function cleDeLuhnValide(string $chiffres): bool
    {
        $somme = 0;
        $double = false;

        for ($position = strlen($chiffres) - 1; $position >= 0; $position--) {
            $chiffre = (int) $chiffres[$position];

            if ($double) {
                $chiffre *= 2;
                $chiffre -= $chiffre > 9 ? 9 : 0;
            }

            $somme += $chiffre;
            $double = ! $double;
        }

        return $somme % 10 === 0;
    }
}
