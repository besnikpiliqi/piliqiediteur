<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Filament\App\Resources\FactureResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Comme pour les devis, les lignes ne sont pas modifiables après création
 * (voir FactureResource::form()) -- a fortiori ici puisqu'une facture
 * n'est de toute façon éditable que tant qu'elle est en brouillon
 * (FacturePolicy::update), avant tout impact sur le stock ou le PDP.
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
}
