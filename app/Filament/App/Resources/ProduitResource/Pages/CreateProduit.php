<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;

class CreateProduit extends CreateRecord
{
    use RevientALaListe;

    protected static string $resource = ProduitResource::class;
}
