<?php

namespace App\Filament\App\Resources\FournisseurResource\Pages;

use App\Filament\App\Resources\FournisseurResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFournisseurs extends ListRecords
{
    protected static string $resource = FournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
