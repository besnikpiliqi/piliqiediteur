<?php

namespace App\Integrations\Pdp\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutTransmissionPdp: string implements HasColor, HasLabel
{
    case Soumise = 'soumise';
    case Acceptee = 'acceptee';
    case Livree = 'livree';
    case Rejetee = 'rejetee';
    case Refusee = 'refusee';
    case Archivee = 'archivee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Soumise => 'Soumise au PDP',
            self::Acceptee => 'Acceptée par le PDP',
            self::Livree => 'Livrée au destinataire',
            self::Rejetee => 'Rejetée (format invalide)',
            self::Refusee => 'Refusée par le destinataire',
            self::Archivee => 'Archivée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Soumise => 'gray',
            self::Acceptee, self::Livree, self::Archivee => 'success',
            self::Rejetee, self::Refusee => 'danger',
        };
    }
}
