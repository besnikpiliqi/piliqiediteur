<?php

namespace App\Filament\App\Resources\AvoirResource\Pages;

use App\Filament\App\Resources\AvoirResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAvoir extends ViewRecord
{
    protected static string $resource = AvoirResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AvoirResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            AvoirResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
        ];
    }
}
