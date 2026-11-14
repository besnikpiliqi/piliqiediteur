<?php

namespace App\Filament\Societe\Resources\MagasinResource\Pages;

use App\Filament\Societe\Resources\MagasinResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des magasins de la portée de la personne connectée, avec la
 * création d'un magasin pour qui peut en créer (voir
 * MagasinDeLaSocietePolicy::create()).
 */
class ListMagasins extends ListRecords
{
    protected static string $resource = MagasinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Créer un magasin'),
        ];
    }
}
