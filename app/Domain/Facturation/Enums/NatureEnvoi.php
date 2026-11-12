<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi un document part par e-mail (voir EnvoiDocument) : un envoi
 * demandé par le magasin, une relance d'une facture échue et impayée,
 * numérotée par son niveau, ou la confirmation au client de sa réponse en
 * ligne à un devis, l'acceptation ou le refus, devis joint (voir
 * RepondreAuDevisEnLigneAction).
 */
enum NatureEnvoi: string implements HasColor, HasLabel
{
    case Envoi = 'envoi';
    case Relance = 'relance';
    case Acceptation = 'acceptation';
    case Refus = 'refus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Envoi => 'Envoi',
            self::Relance => 'Relance',
            self::Acceptation => 'Confirmation de l\'acceptation',
            self::Refus => 'Confirmation du refus',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Envoi => 'info',
            self::Relance => 'warning',
            self::Acceptation => 'success',
            self::Refus => 'danger',
        };
    }
}
