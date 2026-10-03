<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutMembreMagasin: string implements HasColor, HasLabel
{
    case Invite = 'invited';
    case Actif = 'active';
    case Suspendu = 'suspended';

    public function permetAcces(): bool
    {
        return $this === self::Actif;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Invite => 'Invité',
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invite => 'gray',
            self::Actif => 'success',
            self::Suspendu => 'danger',
        };
    }
}
