<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Comment un lien entre un cabinet et un client est né (voir LienCabinet)
 * : l'invitation acceptée d'un SIREN libre, la demande de rattachement du
 * cabinet à une société déjà inscrite, la désignation du cabinet par le
 * client, ou la déclaration de son expert-comptable par le client, par le
 * SIREN d'un cabinet qui n'a pas encore d'espace (voir
 * DeclarerSonExpertComptableAction).
 */
enum OrigineDuLien: string implements HasLabel
{
    case Invitation = 'invitation';
    case DemandeDuCabinet = 'demande_du_cabinet';
    case DesignationDuClient = 'designation_du_client';
    case DeclarationDuClient = 'declaration_du_client';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invitation => 'Invitation du cabinet acceptée',
            self::DemandeDuCabinet => 'Demande de rattachement du cabinet',
            self::DesignationDuClient => 'Désignation par le client',
            self::DeclarationDuClient => 'Expert-comptable déclaré par le client',
        };
    }
}
