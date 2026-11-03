<?php

namespace App\Domain\Stock\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Un inventaire d'ouverture se saisit en brouillon, sans aucun effet sur
 * le catalogue ni sur le stock, puis se valide une fois pour toutes (voir
 * ValiderInventaireOuvertureAction) : sa validation crée ses produits,
 * fait entrer leur stock au coût saisi et lui donne son numéro. Validé, il
 * ne se modifie ni ne se supprime plus jamais.
 */
enum StatutInventaireOuverture: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Valide = 'valide';

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Valide => 'Validé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Valide => 'success',
        };
    }
}
