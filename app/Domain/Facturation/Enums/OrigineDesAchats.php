<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * L'origine des factures d'achat et des avoirs fournisseur que garde le
 * téléchargement par période (décision de l'éditeur du 03/11/2026, E1) :
 * toutes, celles saisies à la main seulement, ou celles reçues
 * automatiquement (par l'API, puis par la plateforme agréée) seulement.
 */
enum OrigineDesAchats: string implements HasLabel
{
    case Toutes = 'toutes';
    case SaisieManuelle = 'manuelle';
    case ReceptionAutomatique = 'automatique';

    public function getLabel(): string
    {
        return match ($this) {
            self::Toutes => 'Toutes',
            self::SaisieManuelle => 'Saisie manuelle',
            self::ReceptionAutomatique => 'Reçues automatiquement',
        };
    }

    /**
     * Les valeurs de la colonne source d'une facture d'achat ou d'un avoir
     * fournisseur que cette origine garde (voir SourceFactureAchat et
     * SourceAvoirFournisseur, qui ont les mêmes valeurs).
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return match ($this) {
            self::Toutes => ['manuelle', 'api'],
            self::SaisieManuelle => ['manuelle'],
            self::ReceptionAutomatique => ['api'],
        };
    }
}
