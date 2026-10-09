<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie volontairement minimal par rapport à StatutFacture : un
 * avoir n'est jamais payé, et son envoi par e-mail ne change pas son
 * statut (voir EnvoiDocument). Il est soit en cours de
 * préparation (brouillon), soit émis (document fiscal définitif), soit
 * annulé (uniquement possible tant qu'il est encore un brouillon, avant
 * tout numéro légal -- voir AnnulerFactureAction pour la même logique).
 */
enum StatutAvoir: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Emise = 'emise';
    case Annulee = 'annulee';

    /**
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Emise, self::Annulee],
            self::Emise, self::Annulee => [],
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
            self::Annulee => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Emise => 'success',
            self::Annulee => 'danger',
        };
    }
}
