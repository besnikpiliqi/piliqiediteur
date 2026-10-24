<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Filament\App\Resources\FactureResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFactures extends ListRecords
{
    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
