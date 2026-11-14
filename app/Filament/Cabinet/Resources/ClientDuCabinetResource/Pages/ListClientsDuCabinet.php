<?php

namespace App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;

use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des clients liés que le cabinet ouvre au compte connecté (voir
 * ClientDuCabinetResource).
 */
class ListClientsDuCabinet extends ListRecords
{
    protected static string $resource = ClientDuCabinetResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
