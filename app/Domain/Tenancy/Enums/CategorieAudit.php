<?php

namespace App\Domain\Tenancy\Enums;

use Carbon\CarbonInterval;
use Filament\Support\Contracts\HasLabel;

/**
 * La catégorie d'une entrée du journal d'audit (voir EntreeAudit) : chaque
 * catégorie a sa chaîne, dans chaque magasin et dans la société, et sa
 * durée de conservation (S13, Q4, voir config/conservation.php).
 */
enum CategorieAudit: string implements HasLabel
{
    /** Opérations commerciales et fiscales, réglages, procédures. */
    case Operations = 'operations';

    /** Comptes, droits, rôles, accès redonnés, connexions, transmissions. */
    case Acces = 'acces';

    /** Vérification de la société. */
    case Verification = 'verification';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operations => 'Opérations',
            self::Acces => 'Accès',
            self::Verification => 'Vérification',
        };
    }

    /**
     * Combien de temps une entrée se garde après son inscription ; null
     * pour la vérification d'une société, qui se garde jusqu'à cinq ans
     * après la fin de la relation, date qui n'est pas encore connue.
     */
    public function dureeDeConservation(): ?CarbonInterval
    {
        return match ($this) {
            self::Operations => CarbonInterval::years((int) config('conservation.journal_audit_operations_en_annees')),
            self::Acces => CarbonInterval::years((int) config('conservation.journal_audit_acces_en_annees')),
            self::Verification => null,
        };
    }
}
