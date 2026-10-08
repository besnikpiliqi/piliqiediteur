<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduit extends EditRecord
{
    use RevientALaListe;

    protected static string $resource = ProduitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
