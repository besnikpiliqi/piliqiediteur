<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Filament\App\Resources\InventaireOuvertureResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des inventaires d'ouverture du magasin, avec « Nouveau » tant
 * que le magasin n'a pas vendu (voir InventaireOuverturePolicy::create()).
 */
class ListInventairesOuverture extends ListRecords
{
    protected static string $resource = InventaireOuvertureResource::class;

    public function getSubheading(): string
    {
        return InventaireOuvertureResource::AIDE;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
