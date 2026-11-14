<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFactures extends ListRecords
{
    use SignaleUneLigneDisparue;

    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
