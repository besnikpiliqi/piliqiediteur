<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le statut d'une société, que seule l'administration de l'éditeur
 * change : active, suspendue, résiliée, ou révoquée par la procédure de
 * l'éditeur qui rend son SIREN au vrai titulaire. Hors active, tous ses magasins
 * sont en lecture seule (voir Magasin::estEnLectureSeule()) : consultation
 * et téléchargements, plus aucune vente ni aucun e-mail aux clients, et
 * aucun réglage de la société ne se modifie.
 */
enum StatutSociete: string implements HasLabel
{
    case Active = 'active';
    case Suspendue = 'suspendue';
    case Resiliee = 'resiliee';
    case Revoquee = 'revoquee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspendue => 'Suspendue',
            self::Resiliee => 'Résiliée',
            self::Revoquee => 'Révoquée',
        };
    }

    public function estEnLectureSeule(): bool
    {
        return $this !== self::Active;
    }
}
