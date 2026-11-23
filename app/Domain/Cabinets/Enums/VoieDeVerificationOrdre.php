<?php

namespace App\Domain\Cabinets\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Comment l'inscription d'un cabinet d'expertise comptable au tableau de
 * l'Ordre a été vérifiée (voir Cabinet) : par l'annuaire de l'Ordre, quand
 * il offre un accès automatique (voir AnnuaireDeLOrdre), ou par l'éditeur,
 * dans sa procédure (voir ConstaterLInscriptionALOrdreAction).
 */
enum VoieDeVerificationOrdre: string implements HasLabel
{
    case Annuaire = 'annuaire';
    case Editeur = 'editeur';

    public function getLabel(): string
    {
        return match ($this) {
            self::Annuaire => 'par l\'annuaire de l\'Ordre',
            self::Editeur => 'par l\'éditeur',
        };
    }
}
