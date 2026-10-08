<?php

namespace App\Filament\App\Resources\FournisseurResource\Pages;

use App\Filament\App\Resources\FournisseurResource;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFournisseur extends EditRecord
{
    use RevientALaListe;

    protected static string $resource = FournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
