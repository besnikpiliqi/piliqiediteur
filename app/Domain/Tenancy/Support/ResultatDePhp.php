<?php

namespace App\Domain\Tenancy\Support;

use RuntimeException;

/**
 * Le résultat d'une fonction de PHP ou d'une bibliothèque qui rend false
 * quand elle échoue (fopen(), Number::format()…), lu pour ce qu'il est : un
 * échec lève une exception qui nomme l'opération, au lieu de passer pour un
 * flux ou un texte.
 */
final class ResultatDePhp
{
    public const ECHEC = '%s a échoué.';

    /**
     * @throws RuntimeException l'opération a échoué (false)
     */
    public static function texte(string|false $resultat, string $operation): string
    {
        return $resultat !== false ? $resultat : throw new RuntimeException(sprintf(self::ECHEC, $operation));
    }

    /**
     * @param  resource|false  $resultat
     * @return resource
     *
     * @throws RuntimeException l'opération a échoué (false)
     */
    public static function flux(mixed $resultat, string $operation): mixed
    {
        return is_resource($resultat) ? $resultat : throw new RuntimeException(sprintf(self::ECHEC, $operation));
    }
}
