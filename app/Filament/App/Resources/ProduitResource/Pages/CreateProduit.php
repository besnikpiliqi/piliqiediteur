<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduit extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = ProduitResource::class;

    /**
     * Quand le produit est refusé à l'enregistrement (référence prise au
     * même instant par un autre membre du magasin, voir
     * Produit::refuserUneReferencePriseEntreTemps()), l'écran dit
     * pourquoi et garde la saisie.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => parent::handleRecordCreation($data));
    }
}
