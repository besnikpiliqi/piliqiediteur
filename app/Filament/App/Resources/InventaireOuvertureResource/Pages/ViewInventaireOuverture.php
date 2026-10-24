<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Filament\App\Resources\InventaireOuvertureResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'un inventaire d'ouverture : « Valider l'inventaire » et
 * « Modifier » en tête tant qu'il est un brouillon.
 */
class ViewInventaireOuverture extends ViewRecord
{
    protected static string $resource = InventaireOuvertureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InventaireOuvertureResource::actionValider(Actions\Action::make('valider')),
            Actions\EditAction::make(),
        ];
    }
}
