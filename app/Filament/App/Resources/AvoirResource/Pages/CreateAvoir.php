<?php

namespace App\Filament\App\Resources\AvoirResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Crée et émet l'avoir d'un seul geste, comme l'action rapide de
 * FactureResource (voir EnregistrerAvoirAction) : aucun brouillon d'avoir
 * n'existe jamais dans l'interface, pas même après un refus.
 */
class CreateAvoir extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = AvoirResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn () => app(EnregistrerAvoirAction::class)->handle(
            Client::query()->findOrFail($data['client_id']),
            $data['lignes'],
            $data['motif'],
        ));
    }
}
