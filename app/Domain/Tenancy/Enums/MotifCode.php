<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pourquoi un code de confirmation part à l'adresse officielle d'une
 * entreprise (voir CodeConfirmation) : la vérification d'une société, la
 * récupération d'un compte (étape 13), la libération d'un SIRET, la
 * réclamation d'un SIREN validé par une autre société, l'examen
 * exceptionnel d'un SIREN. Un seul code actif par SIREN et par motif.
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
            self::Revocation => 'Réclamation de votre SIREN',
            self::ExamenSiren => 'Examen de votre SIREN',
        };
    }
}
