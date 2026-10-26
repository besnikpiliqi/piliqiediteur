<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi un document part par e-mail (voir EnvoiDocument) : un envoi
 * demandé par le magasin, ou une relance d'une facture échue et impayée,
 * numérotée par son niveau.
 */
enum NatureEnvoi: string implements HasColor, HasLabel
{
    case Envoi = 'envoi';
    case Relance = 'relance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Envoi => 'Envoi',
            self::Relance => 'Relance',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Envoi => 'info',
            self::Relance => 'warning',
        };
    }
}
