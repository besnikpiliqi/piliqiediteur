<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Ouverte à un brouillon, ou à un devis envoyé ou accepté pas encore
 * transformé en facture (voir Devis::estModifiable() et
 * DevisPolicy::update()) : lignes comprises, sans recréer le devis. Un
 * devis envoyé ou accepté repasse en brouillon (voir ModifierDevisAction),
 * ce que la page annonce avant l'enregistrement.
 */
class EditDevis extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = DevisResource::class;

    /**
     * Supprimer : seul un brouillon jamais envoyé (voir
     * DevisPolicy::delete()).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->statut) {
            StatutDevis::Envoye => 'Modifier un devis envoyé le repasse en brouillon : renvoyez-le ensuite à votre client.',
            StatutDevis::Accepte => 'Modifier un devis accepté le repasse en brouillon et efface son acceptation : renvoyez-le ensuite à votre client.',
            default => null,
        };
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
