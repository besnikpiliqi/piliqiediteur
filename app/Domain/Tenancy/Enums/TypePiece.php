<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Une pièce déposée pour l'examen de la qualité de représentant par
 * l'éditeur (voir PieceVerification) : extrait Kbis ou extrait du registre
 * national des entreprises de moins de trois mois, pièce d'identité,
 * mandat, ou autre justificatif.
 */
enum TypePiece: string implements HasLabel
{
    case Kbis = 'kbis';
    case ExtraitRne = 'extrait_rne';
    case Identite = 'identite';
    case Mandat = 'mandat';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kbis => 'Extrait Kbis de moins de trois mois',
            self::ExtraitRne => 'Extrait du registre national des entreprises de moins de trois mois',
            self::Identite => 'Pièce d\'identité',
            self::Mandat => 'Mandat signé par un dirigeant',
            self::Autre => 'Autre justificatif',
        };
    }
}
