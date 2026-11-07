<?php

namespace App\Filament\App\Resources\AvoirFournisseurResource\Pages;

use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\FactureAchatResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'un avoir fournisseur émis : la note d'avoir du fournisseur
 * s'y ajoute en pièce, jamais ne s'en retire (voir
 * JointLesPiecesDuFournisseur).
 */
class ViewAvoirFournisseur extends ViewRecord
{
    protected static string $resource = AvoirFournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureAchatResource::ajouterDesPieces(),
        ];
    }
}
