<?php

namespace App\Domain\Achats\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Pure traçabilité pour le moment : un avoir fournisseur ne se crée
 * aujourd'hui que manuellement (voir CreerAvoirFournisseurAction), le cas
 * Api anticipe une future synchronisation automatique des retours déjà
 * traités par le fournisseur, non construite ici.
 */
enum SourceAvoirFournisseur: string implements HasLabel
{
    case Manuelle = 'manuelle';
    case Api = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manuelle => 'Saisi manuellement',
            self::Api => 'Récupéré automatiquement (API)',
        };
    }
}
