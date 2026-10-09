<?php

namespace App\Domain\Tenancy\Enums;

enum StatutMagasin: string
{
    case EnAttenteVerification = 'pending_verification';
    case Active = 'active';
    case Suspendu = 'suspended';
    case Ferme = 'closed';
}
