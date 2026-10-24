<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi un code de confirmation part par courrier recommandé au
 * représentant légal d'une entreprise, à l'adresse officielle de son siège
 * (voir CodeConfirmation) : la vérification d'une société, à son
 * inscription ou pour un nouvel envoi ; et les procédures postales de
 * l'éditeur (étape 13) : la récupération du compte d'un propriétaire, la
 * libération d'un SIRET pris à tort, la révocation d'une société qui a
 * pris le SIREN d'une autre entreprise, l'examen exceptionnel d'un SIREN.
 * Un seul code actif par SIREN et par motif.
 */
enum MotifCode: string implements HasLabel
{
    case Verification = 'verification';
    case Recuperation = 'recuperation';
    case LiberationSiret = 'liberation_siret';
    case Revocation = 'revocation';
    case ExamenSiren = 'examen_siren';

    public function getLabel(): string
    {
        return match ($this) {
            self::Verification => 'Vérification de votre entreprise',
            self::Recuperation => 'Récupération de votre compte',
            self::LiberationSiret => 'Libération d\'un SIRET',
            self::Revocation => 'Révocation d\'une inscription de votre SIREN',
            self::ExamenSiren => 'Examen de votre SIREN',
        };
    }
}
