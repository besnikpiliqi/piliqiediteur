<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RecoitLeClientChoisi;
use App\Filament\Concerns\RecoitLesProduitsChoisis;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFacture extends CreateRecord
{
    use GereLesExceptionsMetier, RecoitLeClientChoisi, RecoitLesProduitsChoisis, RevientALaListe;

    protected static string $resource = FactureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->whereKey(ValeursLues::cle($data['client_id']))->firstOrFail();

        return static::executerOuInterrompre(fn () => app(CreerFactureAction::class)->handle(CompteConnecte::exiger(), $client, ValeursLues::lignes($data['lignes']), [
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
            ...FactureResource::attributsDeLaNature($data),
        ]));
    }
}
