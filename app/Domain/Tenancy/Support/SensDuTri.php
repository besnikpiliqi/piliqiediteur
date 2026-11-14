<?php

namespace App\Domain\Tenancy\Support;

use InvalidArgumentException;

/**
 * Le sens d'un tri qu'un tableau reçoit du navigateur (Filament le passe
 * à la requête d'une colonne triable), lu comme le lit le constructeur de
 * requêtes : « asc » ou « desc », sans tenir compte de la casse ; tout
 * autre sens est refusé, avec le message du constructeur de requêtes.
 */
final class SensDuTri
{
    public const REFUS = 'Order direction must be "asc" or "desc".';

    /**
     * @return 'asc'|'desc'
     *
     * @throws InvalidArgumentException un autre sens
     */
    public static function de(string $sens): string
    {
        return match (strtolower($sens)) {
            'asc' => 'asc',
            'desc' => 'desc',
            default => throw new InvalidArgumentException(self::REFUS),
        };
    }
}
