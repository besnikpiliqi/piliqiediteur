<?php

namespace App\Domain\Tenancy\Support;

/**
 * L'identifiant de connexion tel qu'il se tape : 8 chiffres, affichés sans
 * séparateur (« 48210937 »), le mot de passe d'un compte qui ne l'a pas
 * changé étant exactement ces 8 chiffres. À la connexion, les espaces, les
 * tirets et les points tapés autour ou entre les chiffres sont retirés
 * avant tout contrôle : « 4821 0937 », « 4821-0937 » et « 48210937 »
 * désignent le même compte et comptent pour la même saisie (voir
 * LimitationDesConnexions).
 */
final class IdentifiantDeConnexion
{
    public const MESSAGE_FORME = 'L\'identifiant compte 8 chiffres.';

    public static function normaliser(string $saisie): string
    {
        return (string) preg_replace('/[\s.\-]+/u', '', $saisie);
    }

    /**
     * Une saisie normalisée a-t-elle la forme d'un identifiant : 8
     * chiffres, le premier de 1 à 9 ?
     */
    public static function aLaForme(string $normalise): bool
    {
        return preg_match('/^[1-9][0-9]{7}$/D', $normalise) === 1;
    }
}
