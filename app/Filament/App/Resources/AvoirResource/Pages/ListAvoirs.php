<?php

namespace App\Filament\App\Resources\AvoirResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Filament\App\Resources\AvoirResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAvoirs extends ListRecords
{
    protected static string $resource = AvoirResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouvel avoir')
                ->visible(fn () => auth()->user()->can(PermissionRegistry::AVOIR_CREER)),
        ];
    }
}
