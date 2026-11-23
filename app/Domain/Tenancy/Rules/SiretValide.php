<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\IdentifiantsInsee;

/**
 * Le SIRET d'un magasin : 14 chiffres, clé de contrôle comprise (voir
 * IdentifiantsInsee). Les actions qui réunissent leurs propres refus
 * (création, modification et changement de SIRET d'un magasin) le
 * contrôlent par refus(), sur le SIREN de la société suivi du NIC saisi :
 * il commence donc toujours par ce SIREN.
 */
final class SiretValide
{
    public const MESSAGE = 'Ce SIRET n\'est pas valide : vérifiez le NIC de l\'établissement.';

    /**
     * Pourquoi ce SIRET est refusé, ou null s'il est valable.
     */
    public static function refus(string $siret): ?string
    {
        return IdentifiantsInsee::siretValide($siret) ? null : self::MESSAGE;
    }
}
