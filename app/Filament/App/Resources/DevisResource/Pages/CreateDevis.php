<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RecoitLeClientChoisi;
use App\Filament\Concerns\RecoitLesProduitsChoisis;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Ne construit JAMAIS la facture via Eloquent directement : la logique de
 * snapshot des lignes et de calcul des totaux vit dans CreerDevisAction
 * (partagée avec un éventuel futur usage API), la Resource ne fait que
 * collecter et transmettre les données du formulaire.
 */
class CreateDevis extends CreateRecord
{
    use GereLesExceptionsMetier, RecoitLeClientChoisi, RecoitLesProduitsChoisis, RevientALaListe;

    protected static string $resource = DevisResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->findOrFail($data['client_id']);

        return static::executerOuInterrompre(fn () => app(CreerDevisAction::class)->handle(auth()->user(), $client, $data['lignes'], [
            'date_validite' => $data['date_validite'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
            ...DevisResource::attributsDeLaNature($data),
        ]));
    }
}
