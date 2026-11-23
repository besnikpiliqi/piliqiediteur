<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Widgets\ValeurStockApercu;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des produits, sans bouton « Nouveau » : un produit naît de la
 * validation d'une facture d'achat ou d'un inventaire d'ouverture (voir
 * ProduitResource::canCreate()), ce que dit le sous-titre de la page.
 */
class ListProduits extends ListRecords
{
    use SignaleUneLigneDisparue;

    protected static string $resource = ProduitResource::class;

    public function getSubheading(): string
    {
        return 'Un produit naît de la validation d\'une facture d\'achat ou d\'un inventaire d\'ouverture ; il se modifie ensuite ici, sauf son fournisseur et son coût.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ValeurStockApercu::class,
        ];
    }
}
