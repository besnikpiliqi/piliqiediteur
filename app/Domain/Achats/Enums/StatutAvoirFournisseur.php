<?php

namespace App\Domain\Achats\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Même cycle de vie que StatutAvoir (côté vente) : brouillon tant que
 * l'avoir n'a aucune existence légale ni numéro, émis une fois numéroté
 * (le stock n'est décrémenté qu'à ce moment, voir
 * EmettreAvoirFournisseurAction).
 */
enum StatutAvoirFournisseur: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Emise = 'emise';

    /**
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Emise],
            self::Emise => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Emise => 'Émis',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Emise => 'success',
        };
    }
}
