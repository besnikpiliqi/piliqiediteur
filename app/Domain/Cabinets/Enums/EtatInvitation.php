<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * L'état de l'invitation d'un SIREN libre par un cabinet (voir
 * InvitationCabinet) : ouverte jusqu'à sa fin, puis acceptée, refusée,
 * retirée par le cabinet, expirée, ou devenue caduque quand le SIREN a été
 * inscrit entre-temps.
 */
enum EtatInvitation: string implements HasLabel
{
    case Ouverte = 'ouverte';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
    case Retiree = 'retiree';
    case Expiree = 'expiree';
    case Caduque = 'caduque';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::Acceptee => 'Acceptée',
            self::Refusee => 'Refusée',
            self::Retiree => 'Retirée',
            self::Expiree => 'Expirée',
            self::Caduque => 'Caduque',
        };
    }
}
