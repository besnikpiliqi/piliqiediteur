<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce que le client répond à un devis par son lien secret (voir
 * RepondreAuDevisEnLigneAction) : il l'accepte, en tapant son nom et en
 * cochant « Bon pour accord », ou il le refuse, avec un motif facultatif.
 */
enum ReponseAuDevis: string implements HasLabel
{
    case Acceptation = 'acceptation';
    case Refus = 'refus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Acceptation => 'Accepté en ligne',
            self::Refus => 'Refusé en ligne',
        };
    }

    /**
     * Le statut que la réponse donne au devis.
     */
    public function statut(): StatutDevis
    {
        return match ($this) {
            self::Acceptation => StatutDevis::Accepte,
            self::Refus => StatutDevis::Refuse,
        };
    }

    /**
     * La nature de l'e-mail qui confirme la réponse au client.
     */
    public function natureDeLaConfirmation(): NatureEnvoi
    {
        return match ($this) {
            self::Acceptation => NatureEnvoi::Acceptation,
            self::Refus => NatureEnvoi::Refus,
        };
    }
}
