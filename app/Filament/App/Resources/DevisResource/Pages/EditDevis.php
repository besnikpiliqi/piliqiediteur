<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessible tant que le devis n'a pas été transformé en facture (voir
 * Devis::estModifiable() et DevisPolicy::update()) : lignes comprises,
 * sans recréer le devis.
 */
class EditDevis extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = DevisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Les lignes du document, telles que son formulaire les rouvre (voir
     * AjouteDesLignesDeVente::lignesARouvrir()).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lignes'] = DevisResource::lignesARouvrir($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Limité au magasin par MagasinScope ; withTrashed() garde le client
        // déjà rattaché s'il a été archivé depuis (voir
        // AppServiceProvider::limiterLesChoixAuMagasin()).
        $client = Client::withTrashed()->findOrFail($data['client_id']);

        return static::executerOuInterrompre(fn () => app(ModifierDevisAction::class)->handle($record, $client, $data['lignes'], [
            'date_validite' => $data['date_validite'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));
    }
}
