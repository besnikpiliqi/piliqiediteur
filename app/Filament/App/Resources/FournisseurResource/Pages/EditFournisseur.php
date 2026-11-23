<?php

namespace App\Filament\App\Resources\FournisseurResource\Pages;

use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\EnregistreLesCorrectionsDeLaFiche;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Fournisseur $record
 */
class EditFournisseur extends EditRecord
{
    use ArchiveLaFiche, EnregistreLesCorrectionsDeLaFiche, RevientALaListe;

    protected static string $resource = FournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::actionArchiver(
                'Archiver ce fournisseur',
                'Il ne sera plus proposé pour une nouvelle facture d\'achat. Il reste sur ses factures et ses produits ; vous pourrez le restaurer depuis les fournisseurs archivés.',
                'Fournisseur archivé',
            ),
        ];
    }
}
