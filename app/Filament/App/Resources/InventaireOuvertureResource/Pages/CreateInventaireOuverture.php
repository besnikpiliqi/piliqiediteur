<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Un inventaire d'ouverture s'enregistre en brouillon (voir
 * CreerInventaireOuvertureAction) et s'ouvre ensuite sur sa fiche, où il
 * se valide.
 */
class CreateInventaireOuverture extends CreateRecord
{
    use GereLesExceptionsMetier;

    protected static string $resource = InventaireOuvertureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => app(CreerInventaireOuvertureAction::class)->handle(
            auth()->user(),
            array_values($data['lignes'] ?? []),
            ['notes' => $data['notes'] ?? null],
        ));
    }

    protected function getRedirectUrl(): string
    {
        return InventaireOuvertureResource::getUrl('view', ['record' => $this->record]);
    }
}
