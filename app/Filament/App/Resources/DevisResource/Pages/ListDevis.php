<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDevis extends ListRecords
{
    use SignaleUneLigneDisparue;

    protected static string $resource = DevisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
