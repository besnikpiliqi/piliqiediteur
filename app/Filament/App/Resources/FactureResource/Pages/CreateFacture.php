<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Filament\App\Resources\FactureResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFacture extends CreateRecord
{
    protected static string $resource = FactureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->findOrFail($data['client_id']);

        return app(CreerFactureAction::class)->handle($client, $data['lignes'], [
            'date_echeance' => $data['date_echeance'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
