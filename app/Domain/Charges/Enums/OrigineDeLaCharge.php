<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * D'où vient une charge : saisie par le client (le propriétaire, un
 * administrateur ou un responsable), par le comptable (le cabinet lié ou
 * l'expert-comptable déclaré), ou reçue de la plateforme agréée, que
 * l'étape 2 remplira (une facture reçue de frais devient une charge « à
 * valider », déjà remplie).
 */
enum OrigineDeLaCharge: string implements HasLabel
{
    case Client = 'client';
    case Comptable = 'comptable';
    case Plateforme = 'plateforme';

    public function getLabel(): string
    {
        return match ($this) {
            self::Client => 'Saisie du client',
            self::Comptable => 'Saisie du comptable',
            self::Plateforme => 'Plateforme agréée',
        };
    }
}
