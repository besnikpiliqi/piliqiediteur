<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Filament\App\Resources\ClientResource;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    use SignaleUneLigneDisparue;

    protected static string $resource = ClientResource::class;

    /**
     * Le crédit d'avoir des clients affichés, lu une fois par requête, en
     * deux lectures quel que soit le nombre de clients (voir
     * Client::creditsDAvoirEnCentimes()).
     *
     * @var array<int, int>|null
     */
    private ?array $credits = null;

    /**
     * Le crédit d'avoir de ce client de la page, en centimes (colonne
     * « Solde créditeur », voir ClientResource::table()).
     */
    public function creditDAvoirEnCentimes(Client $client): int
    {
        return ($this->credits ??= Client::creditsDAvoirEnCentimes($this->getTableRecords()->items()))[$client->getKey()] ?? 0;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
