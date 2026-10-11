<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Comment part un code de confirmation (voir CodeConfirmation) : par
 * courrier recommandé avec accusé de réception et remise contre signature,
 * à l'adresse officielle de l'entreprise, ou par lettre recommandée
 * électronique qualifiée (eIDAS art. 44) remise à un dirigeant inscrit au
 * registre national des entreprises et identifié par le prestataire.
 */
enum CanalEnvoi: string implements HasLabel
{
    case RecommandeAr = 'recommande_ar';
    case LreQualifiee = 'lre_qualifiee';

    public function getLabel(): string
    {
        return match ($this) {
            self::RecommandeAr => 'Courrier recommandé avec accusé de réception',
            self::LreQualifiee => 'Lettre recommandée électronique qualifiée',
        };
    }

    public function typeDeVerification(): TypeVerification
    {
        return match ($this) {
            self::RecommandeAr => TypeVerification::Courrier,
            self::LreQualifiee => TypeVerification::Lre,
        };
    }
}
