<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Filament\App\Resources\ClientResource;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use RevientALaListe;

    protected static string $resource = ClientResource::class;
}
