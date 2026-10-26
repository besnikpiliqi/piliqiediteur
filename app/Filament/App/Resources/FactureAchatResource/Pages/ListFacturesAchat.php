<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFacturesAchat extends ListRecords
{
    use SignaleUneLigneDisparue;

    protected static string $resource = FactureAchatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
