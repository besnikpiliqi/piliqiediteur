<?php

namespace App\Domain\Tenancy\Support;

use InvalidArgumentException;

/**
 * Les en-têtes que l'application croit d'un mandataire de confiance (voir
 * FaireConfianceAuxMandataires), tels que Symfony les attend : un masque
 * des six en-têtes qu'il connaît (Request::HEADER_*), de 0 à 63 ; un autre
 * nombre est refusé.
 */
final class EntetesDeConfiance
{
    public const REFUS = 'Les en-têtes de confiance d\'un mandataire forment un masque de 0 à 63 : %d.';

    /**
     * @return int<0, 63>
     *
     * @throws InvalidArgumentException un nombre hors du masque
     */
    public static function masque(int $entetes): int
    {
        return $entetes >= 0 && $entetes <= 63 ? $entetes : throw new InvalidArgumentException(sprintf(self::REFUS, $entetes));
    }
}
