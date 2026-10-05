<?php

namespace App\Filament\App\Resources\AvoirResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Émet immédiatement l'avoir dès sa création, comme l'action rapide de
 * FactureResource : pas d'étape brouillon intermédiaire exposée dans
 * l'UI pour ce formulaire.
 */
class CreateAvoir extends CreateRecord
{
    use GereLesExceptionsMetier;

    protected static string $resource = AvoirResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(function () use ($data) {
            $client = Client::query()->findOrFail($data['client_id']);

            $avoir = app(CreerAvoirAction::class)->handle($client, $data['lignes'], $data['motif']);

            return app(EmettreAvoirAction::class)->handle($avoir);
        });
    }
}
