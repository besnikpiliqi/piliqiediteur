<?php

namespace App\Filament\App\Resources\PaiementResource\Pages;

use App\Filament\App\Resources\PaiementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaiements extends ListRecords
{
    protected static string $resource = PaiementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
