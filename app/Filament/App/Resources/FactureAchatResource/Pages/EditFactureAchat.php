<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessible uniquement pour un brouillon (voir FactureAchatPolicy::update()
 * et FactureAchatResource::table()) : lignes comprises, même raisonnement
 * que EditFacture côté vente.
 */
class EditFactureAchat extends EditRecord
{
    use GereLesExceptionsMetier;

    protected static string $resource = FactureAchatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Par défaut, Filament reste sur le formulaire d'édition après
    // sauvegarde : une fois la facture d'achat corrigée, le vendeur n'a
    // plus rien à y faire et doit pouvoir revenir directement à la liste.
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
            'prix_reference' => $ligne->prix_reference?->value,
            'taux_tva' => $ligne->taux_tva,
            'remise_pourcentage' => $ligne->remise_pourcentage,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(fn () => app(ModifierFactureAchatAction::class)->handle($record, $data['lignes'], [
            'fournisseur_id' => $data['fournisseur_id'],
            'numero_fournisseur' => $data['numero_fournisseur'] ?? null,
            'date_facture' => $data['date_facture'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));
    }
}
