<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutMembreMagasin: string implements HasColor, HasLabel
{
    case Actif = 'active';
    case Suspendu = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Actif => 'success',
            self::Suspendu => 'danger',
        };
    }
}
