<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFacture extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FactureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->findOrFail($data['client_id']);

        return static::executerOuInterrompre(fn () => app(CreerFactureAction::class)->handle(auth()->user(), $client, $data['lignes'], [
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
            ...FactureResource::attributsDeLaNature($data),
        ]));
    }
}
