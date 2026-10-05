<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
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
    use GereLesExceptionsMetier;

    protected static string $resource = DevisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Par défaut, Filament reste sur le formulaire d'édition après
    // sauvegarde : une fois le devis corrigé, le vendeur n'a plus rien à y
    // faire et doit pouvoir revenir directement à la liste.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lignes'] = $this->record->lignes->map(fn ($ligne) => [
            'produit_id' => $ligne->produit_id,
            'description' => $ligne->description,
            'reference' => $ligne->reference,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
            'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
            'taux_tva' => $ligne->taux_tva,
            'remise_pourcentage' => $ligne->remise_pourcentage,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(fn () => app(ModifierDevisAction::class)->handle($record, $data['lignes'], [
            'client_id' => $data['client_id'],
            'date_validite' => $data['date_validite'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));
    }
}
