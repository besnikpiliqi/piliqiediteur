<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RecoitLeClientChoisi;
use App\Filament\Concerns\RecoitLesProduitsChoisis;
use App\Filament\Concerns\RevientALaListe;
use App\Filament\Concerns\RevientALaListeSiLeDocumentEstSupprime;
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
    use GereLesExceptionsMetier, RecoitLeClientChoisi, RecoitLesProduitsChoisis, RevientALaListe, RevientALaListeSiLeDocumentEstSupprime;

    protected static string $resource = DevisResource::class;

    /**
     * Supprimer : seul un brouillon jamais envoyé (voir
     * DevisPolicy::delete() et DevisResource::actionSupprimer()).
     */
    protected function getHeaderActions(): array
    {
        return [
            DevisResource::actionSupprimer(Actions\DeleteAction::make()),
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
     * Les lignes du document et sa nature, tels que son formulaire les
     * rouvre (voir AjouteDesLignesDeVente::lignesARouvrir() et
     * ChoisitLaNatureDeLOperation::natureARouvrir()), et, pour qui n'est pas
     * autorisé à fixer les prix, au prix du catalogue quand l'enregistrement
     * ne les reprendrait plus telles quelles (voir
     * AjouteDesLignesDeVente::lignesAuxPrixPermis()).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $donnees = DevisResource::natureARouvrir($this->record, [...$data, 'lignes' => DevisResource::lignesARouvrir($this->record)]);

        return [...$donnees, 'lignes' => DevisResource::lignesAuxPrixPermis($donnees['lignes'], $donnees['client_id'] ?? null, $donnees['nature_operation'] ?? null, $this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Limité au magasin par MagasinScope ; withTrashed() garde le client
        // déjà rattaché s'il a été archivé depuis (voir
        // AppServiceProvider::limiterLesChoixAuMagasin()).
        $client = Client::withTrashed()->findOrFail($data['client_id']);

        return static::executerOuInterrompre(fn () => app(ModifierDevisAction::class)->handle(auth()->user(), $record, $client, $data['lignes'], [
            'date_validite' => $data['date_validite'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
            ...DevisResource::attributsDeLaNature($data),
        ]));
    }
}
