<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Une pièce déposée pour le traitement à la main, par l'éditeur, d'un
 * courrier recommandé non reçu (voir PieceVerification) : extrait Kbis ou
 * extrait du registre national des entreprises de moins de trois mois,
 * pièce d'identité du représentant légal, ou autre justificatif.
 */
enum TypePiece: string implements HasLabel
{
    case Kbis = 'kbis';
    case ExtraitRne = 'extrait_rne';
    case Identite = 'identite';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kbis => 'Extrait Kbis de moins de trois mois',
            self::ExtraitRne => 'Extrait du registre national des entreprises de moins de trois mois',
            self::Identite => 'Pièce d\'identité du représentant légal',
            self::Autre => 'Autre justificatif',
        };
    }
}
