<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi une inscription est close sans avoir été validée (voir
 * CloreLInscriptionAction) : aucun code saisi dans les trente jours de son
 * dernier courrier recommandé ; ou, par la procédure de l'éditeur (voir
 * editeur:societe:clore et parLEditeur()), son refus après le traitement à
 * la main d'un courrier non reçu, ou un type d'activité choisi par erreur,
 * à la demande du propriétaire, qui s'inscrit alors de nouveau avec le bon
 * type (voir TypeDActivite).
 * Une inscription close reste en lecture seule pour toujours, et son SIREN
 * est libéré.
 */
enum MotifCloture: string implements HasLabel
{
    case DelaiExpire = 'delai_expire';
    case Refus = 'refus';
    case TypeErrone = 'type_errone';

    public function getLabel(): string
    {
        return match ($this) {
            self::DelaiExpire => 'Aucun code saisi dans les trente jours du dernier courrier',
            self::Refus => 'Refus de l\'éditeur',
            self::TypeErrone => 'Type d\'activité choisi par erreur, à la demande du propriétaire',
        };
    }

    /**
     * Les motifs d'une clôture par la procédure de l'éditeur.
     *
     * @return list<self>
     */
    public static function parLEditeur(): array
    {
        return [self::Refus, self::TypeErrone];
    }
}
