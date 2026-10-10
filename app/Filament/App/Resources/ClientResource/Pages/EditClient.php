<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Filament\App\Resources\ClientResource;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    use ArchiveLaFiche, RevientALaListe;

    protected static string $resource = ClientResource::class;

    /**
     * « Vérifier sur VIES » pour un professionnel de l'Union (voir
     * ClientResource::verifierSurVies()) ; le client comptoir n'a pas de
     * bouton « Archiver » (voir ClientPolicy::delete()).
     */
    protected function getHeaderActions(): array
    {
        return [
            ClientResource::verifierSurVies(Action::make('verifier_sur_vies')),
            static::actionArchiver(
                'Archiver ce client',
                'Ce client ne sera plus proposé pour une nouvelle vente. Ses devis, factures, avoirs et paiements restent lisibles, et vous pourrez le restaurer depuis les clients archivés.',
                'Client archivé',
            ),
        ];
    }
}
