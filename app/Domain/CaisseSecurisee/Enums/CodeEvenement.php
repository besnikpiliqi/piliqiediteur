<?php

namespace App\Domain\CaisseSecurisee\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les codes du journal des événements techniques (JET), chaîne
 * ChaineFiscale::Evenement du registre fiscal : ceux du dictionnaire
 * NF525 R19, avec un libellé en français. Un code n'y figure qu'une fois
 * que le logiciel l'inscrit.
 *
 * Ceux-ci, RegistreFiscal les inscrit de lui-même avant l'enregistrement
 * qui les rend nécessaires : l'initialisation au tout premier
 * enregistrement du magasin, le changement de version du module fiscal
 * (ModuleFiscal::VERSION) et celui de la clé de signature. L'annulation de
 * ticket suit l'émission d'un avoir sur des tickets de caisse (voir
 * EmettreAvoirAction).
 */
enum CodeEvenement: int implements HasLabel
{
    case AnnulationDeTicket = 190;
    case VersionMajeure = 250;
    case VersionMineure = 255;
    case Initialisation = 260;
    case ParametreDeConformite = 270;

    public function getLabel(): string
    {
        return match ($this) {
            self::AnnulationDeTicket => 'Annulation de ticket (avoir sur des tickets de caisse)',
            self::VersionMajeure => 'Changement de version majeure du module fiscal',
            self::VersionMineure => 'Changement de version mineure du module fiscal',
            self::Initialisation => 'Initialisation du registre fiscal du magasin',
            self::ParametreDeConformite => 'Modification d\'un paramètre ayant un impact sur la conformité',
        };
    }
}
