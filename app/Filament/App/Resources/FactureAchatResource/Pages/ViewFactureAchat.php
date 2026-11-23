<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\TelechargeLesPiecesDuFournisseur;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une facture d'achat : « Valider la réception » d'un brouillon,
 * où mène l'« À faire » d'un brouillon seul (voir ChosesAFaireDuMagasin),
 * puis « Créer un avoir fournisseur » en tête,
 * pour retourner au fournisseur des produits de cette facture, et
 * « Retourner ce produit » sur chacune de ses lignes retournables (voir
 * FactureAchatResource::creerUnAvoirFournisseur()) ; sous chaque ligne, en
 * orange, ce qui en a déjà été retourné (voir
 * FactureAchatResource::retoursDeLaLigne()). Les pièces du fournisseur
 * s'y ajoutent, et, sur un brouillon, s'y retirent ou s'y remplacent (voir
 * JointLesPiecesDuFournisseur) ; elles s'y téléchargent d'un clic sur leur
 * nom (voir TelechargeLesPiecesDuFournisseur).
 */
class ViewFactureAchat extends ViewRecord
{
    use TelechargeLesPiecesDuFournisseur;

    protected static string $resource = FactureAchatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureAchatResource::actionValider(Actions\Action::make('valider')),
            FactureAchatResource::creerUnAvoirFournisseur(Actions\Action::make('creer_avoir_fournisseur')),
            FactureAchatResource::ajouterDesPieces(),
            FactureAchatResource::remplacerUnePiece(),
            FactureAchatResource::retirerUnePiece(),
        ];
    }
}
