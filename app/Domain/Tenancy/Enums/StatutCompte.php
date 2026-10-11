<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le statut d'un compte dans sa société : un compte suspendu n'ouvre plus
 * aucun panneau et n'a plus aucun droit, dès la requête suivante (voir
 * User::canAccessPanel() et ControleDesDroits).
 */
enum StatutCompte: string implements HasLabel
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';

    public function getLabel(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
        };
    }
}
