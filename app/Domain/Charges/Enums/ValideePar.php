<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qui a validé une charge (décision de l'éditeur du 14/11/2026, lot
 * CHARGES) : le cabinet lié, l'expert-comptable déclaré, ou le
 * propriétaire d'une société qui n'a aucun comptable (voir
 * DroitsSurLesCharges).
 */
enum ValideePar: string implements HasLabel
{
    case Cabinet = 'cabinet';
    case ExpertComptable = 'expert_comptable';
    case Proprietaire = 'proprietaire';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cabinet => 'Validée par le cabinet',
            self::ExpertComptable => 'Validée par l\'expert-comptable',
            self::Proprietaire => 'Validée par le propriétaire',
        };
    }
}
