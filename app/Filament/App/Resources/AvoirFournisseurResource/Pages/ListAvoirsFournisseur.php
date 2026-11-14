<?php

namespace App\Filament\App\Resources\AvoirFournisseurResource\Pages;

use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\FactureAchatResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des avoirs fournisseur, avec en tête « Créer un avoir
 * fournisseur » : on y choisit la facture d'achat, puis les produits à
 * retourner (voir FactureAchatResource::creerUnAvoirFournisseur()) ; son
 * sous-titre dit qu'un rabais ou une ristourne ne s'y saisit pas encore.
 */
class ListAvoirsFournisseur extends ListRecords
{
    protected static string $resource = AvoirFournisseurResource::class;

    public function getSubheading(): string
    {
        return AvoirFournisseurResource::INFORMATION_RISTOURNE;
    }

    protected function getHeaderActions(): array
    {
        return [
            FactureAchatResource::creerUnAvoirFournisseur(Actions\Action::make('creer_avoir_fournisseur')),
        ];
    }
}
