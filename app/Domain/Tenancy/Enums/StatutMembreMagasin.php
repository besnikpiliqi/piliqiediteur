<?php

namespace App\Domain\Tenancy\Enums;

enum StatutMembreMagasin: string
{
    case Invite = 'invited';
    case Actif = 'active';
    case Suspendu = 'suspended';

    public function permetAcces(): bool
    {
        return $this === self::Actif;
    }
}
