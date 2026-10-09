<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduit extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = ProduitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Un changement de taux de TVA s'inscrit au journal des événements du
     * magasin (voir JournaliseSesParametresDeConformite) : quand le
     * registre fiscal ne peut pas l'inscrire, la fiche n'est pas modifiée
     * et l'écran dit pourquoi.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
