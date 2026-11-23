<?php

namespace App\Domain\Achats\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Détermine si une facture d'achat reste modifiable après validation, voir
 * FactureAchat::estModifiable() : une saisie manuelle peut contenir une
 * erreur de frappe, une récupération automatique par API ne le peut pas
 * (une correction viendrait alors forcément de la source elle-même, donc
 * jamais d'une réécriture locale).
 */
enum SourceFactureAchat: string implements HasLabel
{
    case Manuelle = 'manuelle';
    case Api = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manuelle => 'Saisie manuelle',
            self::Api => 'Récupérée automatiquement (API)',
        };
    }
}
