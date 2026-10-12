<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessible uniquement pour un brouillon (voir FacturePolicy::update() et
 * FactureResource::table()) : lignes comprises, pour éviter de devoir tout
 * ressaisir en cas d'erreur de saisie. La suppression complète reste aussi
 * disponible ici (cascade DB sur les lignes, voir la migration
 * lignes_facture) sans avoir besoin de les retirer une à une d'abord.
 */
class EditFacture extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FactureResource::class;

    /**
     * Supprimer le brouillon inscrit l'abandon de la vente au journal des
     * événements (voir Facture::delete()) : un refus du registre fiscal se
     * lit en notification, et le brouillon reste.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->using(fn (Facture $record): ?bool => static::executerOuInterrompre(fn (): ?bool => $record->delete())),
        ];
    }

    /**
     * Les lignes du document et sa nature, tels que son formulaire les
     * rouvre (voir AjouteDesLignesDeVente::lignesARouvrir() et
     * ChoisitLaNatureDeLOperation::natureARouvrir()).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return FactureResource::natureARouvrir($this->record, [...$data, 'lignes' => DevisResource::lignesARouvrir($this->record)]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Limité au magasin par MagasinScope ; withTrashed() garde le client
        // déjà rattaché s'il a été archivé depuis (voir
        // AppServiceProvider::limiterLesChoixAuMagasin()).
        $client = Client::withTrashed()->findOrFail($data['client_id']);

        return static::executerOuInterrompre(fn () => app(ModifierFactureAction::class)->handle(auth()->user(), $record, $client, $data['lignes'], [
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
            ...FactureResource::attributsDeLaNature($data),
        ]));
    }
}
