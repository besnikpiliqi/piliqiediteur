<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les deux formats du téléchargement par période (décision de l'éditeur du
 * 03/11/2026, E2) : les documents en PDF dans un fichier ZIP (voir
 * ArchiveDesDocuments), ou leur récapitulatif dans un fichier CSV qui
 * s'ouvre dans Excel (voir RecapitulatifDesDocuments).
 */
enum FormatDuTelechargement: string implements HasLabel
{
    case Zip = 'zip';
    case Csv = 'csv';

    public function getLabel(): string
    {
        return match ($this) {
            self::Zip => 'PDF (ZIP)',
            self::Csv => 'Récapitulatif (CSV)',
        };
    }

    public function typeMime(): string
    {
        return match ($this) {
            self::Zip => 'application/zip',
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }
}
