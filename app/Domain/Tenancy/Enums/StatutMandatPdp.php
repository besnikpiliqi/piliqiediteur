<?php

namespace App\Domain\Tenancy\Enums;

enum StatutMandatPdp: string
{
    case EnAttente = 'pending';
    case Active = 'active';
    case Revoque = 'revoked';
    case Rejete = 'rejected';

    public function autoriseLaTransmission(): bool
    {
        return $this === self::Active;
    }
}
