<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La décision motivée de l'examen exceptionnel du SIREN d'une société
 * validée par l'éditeur (voir ExaminerSirenAction) : une erreur, que la
 * correction du SIREN répare ensuite (voir CorrigerSirenAction) ; une
 * usurpation, que la révocation de la société traite (voir
 * RevoquerSocieteAction) ; ou rien à régulariser, qui clôt l'examen.
 */
enum DecisionExamenSiren: string implements HasLabel
{
    case Erreur = 'erreur';
    case Usurpation = 'usurpation';
    case RienARegulariser = 'rien_a_regulariser';

    public function getLabel(): string
    {
        return match ($this) {
            self::Erreur => 'SIREN saisi par erreur, à corriger',
            self::Usurpation => 'SIREN usurpé, la société à révoquer',
            self::RienARegulariser => 'Rien à régulariser',
        };
    }
}
