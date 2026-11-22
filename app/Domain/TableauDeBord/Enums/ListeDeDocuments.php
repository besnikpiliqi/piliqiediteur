<?php

namespace App\Domain\TableauDeBord\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Une liste de documents d'un magasin, que le clic sur un jour d'un
 * graphique par période ouvre filtrée sur ce jour (voir ListesDesJours) :
 * les tickets et les factures, les avoirs, les factures d'achat ou les
 * avoirs fournisseur. Chacune ne s'ouvre que dans un magasin où la personne
 * voit tous les documents que le graphique compte (voir
 * SourceDesGraphiques).
 */
enum ListeDeDocuments: string implements HasLabel
{
    case Factures = 'factures';
    case Avoirs = 'avoirs';
    case FacturesAchat = 'factures_achat';
    case AvoirsFournisseur = 'avoirs_fournisseur';

    public function getLabel(): string
    {
        return match ($this) {
            self::Factures => 'Factures et tickets',
            self::Avoirs => 'Avoirs',
            self::FacturesAchat => 'Factures d\'achat',
            self::AvoirsFournisseur => 'Avoirs fournisseur',
        };
    }

    /**
     * Ce qu'il faut voir en entier dans un magasin pour y ouvrir la liste :
     * toutes les factures, tous les avoirs, ou les achats.
     */
    public function source(): SourceDesGraphiques
    {
        return match ($this) {
            self::Factures => SourceDesGraphiques::Factures,
            self::Avoirs => SourceDesGraphiques::Avoirs,
            self::FacturesAchat, self::AvoirsFournisseur => SourceDesGraphiques::Achats,
        };
    }
}
