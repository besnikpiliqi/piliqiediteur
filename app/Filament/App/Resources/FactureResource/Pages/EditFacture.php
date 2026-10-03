<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Filament\App\Resources\FactureResource;
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
    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lignes'] = $this->record->lignes->map(fn ($ligne) => [
            'produit_id' => $ligne->produit_id,
            'description' => $ligne->description,
            'reference' => $ligne->reference,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
            'taux_tva' => $ligne->taux_tva,
            'remise_pourcentage' => $ligne->remise_pourcentage,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ModifierFactureAction::class)->handle($record, $data['lignes'], [
            'client_id' => $data['client_id'],
            'date_echeance' => $data['date_echeance'] ?? null,
            'conditions_paiement' => $data['conditions_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
