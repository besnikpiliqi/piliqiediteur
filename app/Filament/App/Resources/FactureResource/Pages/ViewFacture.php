<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Filament\App\Resources\FactureResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFacture extends ViewRecord
{
    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            FactureResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
            FactureResource::relancer(Actions\Action::make('relancer')),
        ];
    }
}
