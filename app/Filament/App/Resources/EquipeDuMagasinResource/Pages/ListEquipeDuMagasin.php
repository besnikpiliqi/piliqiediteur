<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Filament\App\Resources\EquipeDuMagasinResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEquipeDuMagasin extends ListRecords
{
    protected static string $resource = EquipeDuMagasinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Créer un compte'),
        ];
    }
}
