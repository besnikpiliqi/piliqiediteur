<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFactureAchat extends CreateRecord
{
    protected static string $resource = FactureAchatResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $fournisseur = Fournisseur::query()->findOrFail($data['fournisseur_id']);

        return app(CreerFactureAchatAction::class)->handle($fournisseur, $data['lignes'], [
            'numero_fournisseur' => $data['numero_fournisseur'] ?? null,
            'date_facture' => $data['date_facture'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
