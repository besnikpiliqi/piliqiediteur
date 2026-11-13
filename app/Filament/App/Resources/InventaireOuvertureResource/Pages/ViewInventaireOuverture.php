<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Filament\App\Resources\InventaireOuvertureResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'un inventaire d'ouverture : « Valider l'inventaire »,
 * « Modifier » et « Supprimer » en tête tant qu'il est un brouillon, chacun
 * selon InventaireOuverturePolicy. Après la première vente du magasin, un
 * brouillon ne se valide ni ne se modifie plus et reste à supprimer : l'« À
 * faire » d'un brouillon seul mène ici (voir ChosesAFaireDuMagasin).
 */
class ViewInventaireOuverture extends ViewRecord
{
    protected static string $resource = InventaireOuvertureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InventaireOuvertureResource::actionValider(Actions\Action::make('valider')),
            Actions\EditAction::make(),
            InventaireOuvertureResource::actionSupprimer(Actions\DeleteAction::make()),
        ];
    }
}
