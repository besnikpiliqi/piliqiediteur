<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce qu'un reçu de paiement atteste (R2, voir RecuPaiement) : un
 * encaissement fait après l'émission d'une facture ou d'un ticket, ou la
 * contre-passation d'un paiement saisi par erreur. Son libellé est le
 * titre du reçu.
 */
enum NatureRecuPaiement: string implements HasLabel
{
    case Encaissement = 'encaissement';
    case ContrePassation = 'contre_passation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Encaissement => 'Reçu de paiement',
            self::ContrePassation => 'Contre-passation de paiement',
        };
    }
}
