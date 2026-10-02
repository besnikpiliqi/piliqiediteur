<?php

namespace App\Domain\Contacts\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Détermine le circuit de facturation électronique applicable :
 * un client professionnel relève de l'e-invoicing (transmission PDP
 * individuelle), un particulier relève de l'e-reporting (agrégé via la
 * clôture de caisse journalière).
 */
enum TypeClient: string implements HasLabel
{
    case Particulier = 'particulier';
    case Professionnel = 'professionnel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Particulier => 'Particulier',
            self::Professionnel => 'Professionnel',
        };
    }
}
