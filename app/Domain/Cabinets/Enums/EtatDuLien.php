<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * L'état d'un lien entre un cabinet d'expertise comptable et un client
 * (voir LienCabinet) : une demande, du cabinet (la demande de rattachement
 * née de l'invitation d'un SIREN déjà inscrit) ou du client (le client
 * désigne le cabinet), qui devient active, refusée, retirée ou expirée ;
 * un lien actif, qui prend fin ; la déclaration de l'expert-comptable
 * d'une société, sans cabinet, qui devient le lien actif à son
 * rattachement au cabinet vérifié de son SIREN, ou prend fin. Le lien en
 * cours d'un client est son lien actif ou sa déclaration (voir enCours()).
 * La base refuse toute autre transition (voir GardesDesCabinets).
 */
enum EtatDuLien: string implements HasLabel
{
    case DemandeParLeCabinet = 'demande_par_le_cabinet';
    case DemandeParLeClient = 'demande_par_le_client';
    case Actif = 'actif';
    case Declare = 'declare';
    case Refuse = 'refuse';
    case Retire = 'retire';
    case Expire = 'expire';
    case Termine = 'termine';

    public function getLabel(): string
    {
        return match ($this) {
            self::DemandeParLeCabinet => 'Demande du cabinet',
            self::DemandeParLeClient => 'Demande du client',
            self::Actif => 'Actif',
            self::Declare => 'Déclaré, en attente de son cabinet',
            self::Refuse => 'Refusée',
            self::Retire => 'Retirée',
            self::Expire => 'Expirée',
            self::Termine => 'Terminé',
        };
    }

    /**
     * Les états d'une demande en cours.
     *
     * @return list<self>
     */
    public static function demandes(): array
    {
        return [self::DemandeParLeCabinet, self::DemandeParLeClient];
    }

    public function estUneDemande(): bool
    {
        return in_array($this, self::demandes(), true);
    }

    /**
     * Les états du lien en cours d'un client : son cabinet lié ou son
     * expert-comptable déclaré ; il en a au plus un (index unique partiel
     * liens_cabinet_un_cabinet_par_client).
     *
     * @return list<self>
     */
    public static function enCours(): array
    {
        return [self::Actif, self::Declare];
    }

    public function estEnCours(): bool
    {
        return in_array($this, self::enCours(), true);
    }
}
