<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Filament\App\Resources\MembreEquipeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMembreEquipes extends ListRecords
{
    protected static string $resource = MembreEquipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
