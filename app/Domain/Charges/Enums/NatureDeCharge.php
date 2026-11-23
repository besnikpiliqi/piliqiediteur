<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce que paie une charge, qui fixe le mois de TVA proposé (décision de
 * Claude, D76, voir PropositionsDeTva) : un bien, dont la TVA se déduit au
 * mois de la pièce ; un service, dont la TVA se déduit en principe au
 * paiement, sauf si le fournisseur a opté pour les débits.
 */
enum NatureDeCharge: string implements HasLabel
{
    case Biens = 'biens';
    case Services = 'services';

    public function getLabel(): string
    {
        return match ($this) {
            self::Biens => 'Biens',
            self::Services => 'Services',
        };
    }
}
