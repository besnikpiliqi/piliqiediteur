<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * L'état d'une charge (décision de l'éditeur du 14/11/2026, lot CHARGES ;
 * voir Charge) : sa pièce déposée sans ses chiffres, à valider par le
 * comptable, validée, ou renvoyée au client avec le mot du comptable. Une
 * charge validée ne s'efface plus et ne change que par une correction
 * (voir GardesDesCharges).
 */
enum EtatDeLaCharge: string implements HasColor, HasLabel
{
    case PieceSansChiffres = 'piece_sans_chiffres';
    case AValider = 'a_valider';
    case Validee = 'validee';
    case Renvoyee = 'renvoyee';

    public function getLabel(): string
    {
        return match ($this) {
            self::PieceSansChiffres => 'Pièce sans chiffres',
            self::AValider => 'À valider',
            self::Validee => 'Validée',
            self::Renvoyee => 'Renvoyée au client',
        };
    }

    /**
     * Le symbole de sa case dans le tableau des mois (modèle C1).
     */
    public function symbole(): string
    {
        return match ($this) {
            self::PieceSansChiffres => '◦',
            self::AValider => '!',
            self::Validee => '✓',
            self::Renvoyee => '↩',
        };
    }

    /**
     * @return 'gray'|'warning'|'success'|'danger'
     */
    public function getColor(): string
    {
        return match ($this) {
            self::PieceSansChiffres => 'gray',
            self::AValider => 'warning',
            self::Validee => 'success',
            self::Renvoyee => 'danger',
        };
    }

    /**
     * Son avancement, du moins avancé au plus avancé : une case qui porte
     * plusieurs charges montre l'état le moins avancé (renvoyée, pièce sans
     * chiffres, à valider, validée).
     */
    public function rang(): int
    {
        return match ($this) {
            self::Renvoyee => 0,
            self::PieceSansChiffres => 1,
            self::AValider => 2,
            self::Validee => 3,
        };
    }
}
