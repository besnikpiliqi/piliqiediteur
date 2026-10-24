<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Contracts\SourceDeHasard;

/**
 * Le hasard cryptographique de PHP, et rien d'autre : un identifiant de
 * connexion ne se devine ni par un compteur, ni par la société, le magasin,
 * le rôle ou l'ordre de création.
 */
final class SourceDeHasardCryptographique implements SourceDeHasard
{
    public function entier(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
