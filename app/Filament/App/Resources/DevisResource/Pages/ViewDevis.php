<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Filament\App\Resources\DevisResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDevis extends ViewRecord
{
    protected static string $resource = DevisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DevisResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            DevisResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
        ];
    }
}
