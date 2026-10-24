<?php

namespace App\Filament\App\Resources\FournisseurResource\Pages;

use App\Filament\App\Resources\FournisseurResource;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;

class CreateFournisseur extends CreateRecord
{
    use RevientALaListe;

    protected static string $resource = FournisseurResource::class;
}
