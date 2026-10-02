<?php

namespace App\Domain\Contacts\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceFournisseur: string implements HasLabel
{
    case Manuel = 'manual';
    case AutoPdp = 'pdp_auto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manuel => 'Saisi manuellement',
            self::AutoPdp => 'Détecté automatiquement (PDP)',
        };
    }
}
