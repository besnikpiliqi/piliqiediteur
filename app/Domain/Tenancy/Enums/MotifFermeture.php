<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi une période d'activité d'un magasin a pris fin (voir
 * PeriodeActivite) : une fermeture temporaire ou définitive par la
 * société, une cession, la fin du magasin de démonstration, la résiliation
 * ou la révocation de la société.
 */
enum MotifFermeture: string implements HasLabel
{
    case Temporaire = 'temporaire';
    case Cessation = 'cessation';
    case Cession = 'cession';
    case FinDemonstration = 'fin_demonstration';
    case ResiliationSociete = 'resiliation_societe';
    case RevocationSociete = 'revocation_societe';

    public function getLabel(): string
    {
        return match ($this) {
            self::Temporaire => 'Fermeture temporaire',
            self::Cessation => 'Cessation d\'activité',
            self::Cession => 'Cession',
            self::FinDemonstration => 'Fin de la démonstration',
            self::ResiliationSociete => 'Résiliation de la société',
            self::RevocationSociete => 'Révocation de la société',
        };
    }
}
