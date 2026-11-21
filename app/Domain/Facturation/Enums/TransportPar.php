<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qui transporte les biens d'une vente hors de France (voir
 * NatureOperation::transportImpose()) : le magasin, ou un transporteur
 * pour son compte, ou bien le client lui-même, ou son transporteur. Une
 * livraison intracommunautaire enlevée par le client demande en plus son
 * attestation de réception.
 */
enum TransportPar: string implements HasLabel
{
    case Magasin = 'magasin';
    case Acquereur = 'acquereur';

    public function getLabel(): string
    {
        return match ($this) {
            self::Magasin => 'Expédié par le magasin ou pour son compte',
            self::Acquereur => 'Enlevé par le client ou son transporteur',
        };
    }
}
