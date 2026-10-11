<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le résultat d'une vérification : concluante, négative (entreprise
 * cessée, établissement fermé, inconnu ou rattaché à un autre SIREN), ou
 * impossible parce que le service ne répondait pas, à reprendre (voir
 * app:reverifier-sirene).
 */
enum ResultatVerification: string implements HasLabel
{
    case Concluante = 'concluante';
    case Negative = 'negative';
    case Indisponible = 'indisponible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Concluante => 'Concluante',
            self::Negative => 'Négative',
            self::Indisponible => 'Service indisponible',
        };
    }
}
