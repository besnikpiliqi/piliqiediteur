<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi une inscription est close sans avoir été validée (voir
 * CloreLInscriptionAction) : aucun code saisi dans les trente jours de son
 * dernier courrier recommandé, ou refus de l'éditeur après le traitement à
 * la main d'un courrier non reçu.
 * Une inscription close reste en lecture seule pour toujours, et son SIREN
 * est libéré.
 */
enum MotifCloture: string implements HasLabel
{
    case DelaiExpire = 'delai_expire';
    case Refus = 'refus';

    public function getLabel(): string
    {
        return match ($this) {
            self::DelaiExpire => 'Aucun code saisi dans les trente jours du dernier courrier',
            self::Refus => 'Refus de l\'éditeur',
        };
    }
}
