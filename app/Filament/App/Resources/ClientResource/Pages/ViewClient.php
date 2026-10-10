<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Filament\App\Resources\ClientResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * La fiche d'un client en lecture seule, ses vérifications VIES comprises
 * (voir ClientResource::sectionDesVerificationsVies()) : à qui peut voir
 * le client (voir ClientPolicy::view()), y compris le comptable ou
 * l'observateur, dans un magasin suspendu ou fermé, et pour un client
 * archivé, dont les preuves restent lisibles.
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * Une fiche archivée reste lisible (voir ArchiveLaFiche) : cette page
     * la trouve, à la différence de celle de modification (voir
     * ClientPolicy::update()). La requête de la ressource garde le client
     * dans le magasin actif : celui d'un autre magasin reste introuvable.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return ClientResource::getEloquentQuery()->withTrashed()->whereKey($key)->firstOrFail();
    }

    /**
     * Un client archivé ne se modifie pas : la fiche dit pourquoi elle n'a
     * pas de bouton « Modifier ».
     */
    public function getSubheading(): ?string
    {
        return $this->getRecord()->trashed()
            ? 'Client archivé : il n\'est plus proposé pour une nouvelle vente. Restaurez-le depuis la liste des clients archivés pour le modifier.'
            : null;
    }

    /**
     * « Modifier » et « Vérifier sur VIES » demandent le droit de modifier
     * le client (voir ClientPolicy::update()) : ni l'un ni l'autre pour un
     * client archivé ou dans un magasin en lecture seule. La fiche montre
     * ici le n° enregistré, celui que vérifie VIES.
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ClientResource::verifierSurVies(Action::make('verifier_sur_vies')),
        ];
    }
}
