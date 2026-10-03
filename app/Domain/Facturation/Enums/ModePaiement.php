<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModePaiement: string implements HasLabel
{
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Especes = 'especes';
    case CarteBancaire = 'carte_bancaire';
    case Prelevement = 'prelevement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Especes => 'Espèces',
            self::CarteBancaire => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
        };
    }
}
