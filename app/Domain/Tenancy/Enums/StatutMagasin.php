<?php

namespace App\Domain\Tenancy\Enums;

enum StatutMagasin: string
{
    case EnAttenteVerification = 'pending_verification';
    case Active = 'active';
    case Suspendu = 'suspended';
    case Ferme = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::EnAttenteVerification => 'En attente de vérification',
            self::Active => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Ferme => 'Fermé',
        };
    }

    public function permetConnexion(): bool
    {
        return $this === self::Active;
    }
}
