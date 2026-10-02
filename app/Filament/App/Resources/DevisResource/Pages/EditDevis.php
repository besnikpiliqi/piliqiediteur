<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Filament\App\Resources\DevisResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Les lignes ne sont pas modifiables après création (le Repeater est
 * désactivé en édition, voir DevisResource::form()) : on affiche quand
 * même leur contenu existant pour éviter qu'un utilisateur ne croie
 * qu'elles ont disparu. Pour changer les lignes d'un devis, on en recrée
 * un -- pas de recalcul partiel des totaux pris en charge pour l'instant.
 */
class EditDevis extends EditRecord
{
    protected static string $resource = DevisResource::class;

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
