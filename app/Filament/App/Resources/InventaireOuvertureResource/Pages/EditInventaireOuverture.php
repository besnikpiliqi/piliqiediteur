<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessible tant que l'inventaire d'ouverture est un brouillon et que le
 * magasin n'a pas vendu (voir InventaireOuverturePolicy::update()), lignes
 * comprises.
 *
 * @property InventaireOuverture $record
 */
class EditInventaireOuverture extends EditRecord
{
    use GereLesExceptionsMetier;

    protected static string $resource = InventaireOuvertureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InventaireOuvertureResource::actionValider(Actions\Action::make('valider')),
            InventaireOuvertureResource::actionSupprimer(Actions\DeleteAction::make()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lignes'] = $this->record->lignes
            ->map(fn (LigneInventaireOuverture $ligne): array => InventaireOuvertureResource::ligneDejaEnregistree($ligne))
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => app(ModifierInventaireOuvertureAction::class)->handle(
            CompteConnecte::exiger(),
            $this->record,
            array_values($data['lignes'] ?? []),
            ['notes' => $data['notes'] ?? null],
        ));
    }

    protected function getRedirectUrl(): string
    {
        return InventaireOuvertureResource::getUrl('view', ['record' => $this->record]);
    }
}
