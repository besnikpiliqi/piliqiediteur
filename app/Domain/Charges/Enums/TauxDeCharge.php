<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le taux d'une ligne de charge, en centièmes de point (décision de
 * l'éditeur du 14/11/2026, lot CHARGES, modèle C2) : 20 %, 10 %, 5,5 %,
 * 2,1 %, 0 % ou sans TVA, et 8,5 %, le taux normal de la Guadeloupe, de
 * la Martinique et de La Réunion, pour une charge d'un magasin de ces
 * territoires (décision de Claude, D76).
 */
enum TauxDeCharge: int implements HasLabel
{
    case Vingt = 2000;
    case Dix = 1000;
    case HuitVirguleCinq = 850;
    case CinqVirguleCinq = 550;
    case DeuxVirguleUn = 210;
    case Zero = 0;

    public function getLabel(): string
    {
        return match ($this) {
            self::Vingt => '20 %',
            self::Dix => '10 %',
            self::HuitVirguleCinq => '8,5 %',
            self::CinqVirguleCinq => '5,5 %',
            self::DeuxVirguleUn => '2,1 %',
            self::Zero => '0 % ou sans TVA',
        };
    }
}
