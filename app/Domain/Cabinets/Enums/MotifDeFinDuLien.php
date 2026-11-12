<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi un lien entre un cabinet et un client a pris fin (voir
 * MettreFinAuLienAction) : le client ou le cabinet y a mis fin ; le client
 * en a lié un autre ; le cabinet n'est plus vérifié (radiation de l'Ordre
 * constatée par l'éditeur) ; une autre personne a saisi le code du
 * courrier de la société et en est devenue propriétaire ; l'inscription de
 * la société s'est close sans validation ; l'éditeur a révoqué le cabinet
 * ou le client (voir RevoquerSocieteAction). La déclaration de
 * l'expert-comptable d'une société prend fin par le client, par un
 * remplacement ou par la révocation de la société.
 */
enum MotifDeFinDuLien: string implements HasLabel
{
    case ParLeClient = 'par_le_client';
    case ParLeCabinet = 'par_le_cabinet';
    case Remplace = 'remplace';
    case CabinetPlusVerifie = 'cabinet_plus_verifie';
    case ProprietaireRemplace = 'proprietaire_remplace';
    case InscriptionClose = 'inscription_close';
    case SocieteRevoquee = 'societe_revoquee';

    public function getLabel(): string
    {
        return match ($this) {
            self::ParLeClient => 'Fin décidée par le client',
            self::ParLeCabinet => 'Fin décidée par le cabinet',
            self::Remplace => 'Le client a choisi un autre expert-comptable',
            self::CabinetPlusVerifie => 'Cabinet plus vérifié',
            self::ProprietaireRemplace => 'Nouveau propriétaire de la société',
            self::InscriptionClose => 'Inscription de la société close',
            self::SocieteRevoquee => 'Société révoquée par l\'éditeur',
        };
    }
}
