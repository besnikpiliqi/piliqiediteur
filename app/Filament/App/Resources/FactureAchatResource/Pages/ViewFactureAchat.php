<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Filament\App\Resources\FactureAchatResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une facture d'achat : « Créer un avoir fournisseur » en tête,
 * pour retourner au fournisseur des produits de cette facture, et
 * « Retourner ce produit » sur chacune de ses lignes retournables (voir
 * FactureAchatResource::creerUnAvoirFournisseur()) ; sous chaque ligne, en
 * orange, ce qui en a déjà été retourné (voir
 * FactureAchatResource::retoursDeLaLigne()).
 */
class ViewFactureAchat extends ViewRecord
{
    protected static string $resource = FactureAchatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureAchatResource::creerUnAvoirFournisseur(Actions\Action::make('creer_avoir_fournisseur')),
        ];
    }
}
