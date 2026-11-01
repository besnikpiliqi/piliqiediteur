<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Comment un lien entre un cabinet et un client est né (voir LienCabinet)
 * : l'invitation acceptée d'un SIREN libre, la demande de rattachement du
 * cabinet à une société déjà inscrite, ou la désignation du cabinet par
 * le client.
 */
enum OrigineDuLien: string implements HasLabel
{
    case Invitation = 'invitation';
    case DemandeDuCabinet = 'demande_du_cabinet';
    case DesignationDuClient = 'designation_du_client';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invitation => 'Invitation du cabinet acceptée',
            self::DemandeDuCabinet => 'Demande de rattachement du cabinet',
            self::DesignationDuClient => 'Désignation par le client',
        };
    }
}
