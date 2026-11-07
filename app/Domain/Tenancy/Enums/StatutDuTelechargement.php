<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Où en est un téléchargement des documents par période (voir
 * TelechargementDeDocuments) : demandé et en préparation dans la file
 * d'attente, prêt à télécharger pendant sept jours, ou en échec.
 */
enum StatutDuTelechargement: string implements HasColor, HasLabel
{
    case EnPreparation = 'en_preparation';
    case Pret = 'pret';
    case Echec = 'echec';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Pret => 'Prêt',
            self::Echec => 'Échec',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnPreparation => 'gray',
            self::Pret => 'success',
            self::Echec => 'danger',
        };
    }
}
