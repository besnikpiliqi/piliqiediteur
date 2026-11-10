<?php

namespace App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La fiche d'un client lié que le cabinet ouvre au compte connecté (voir
 * ClientDuCabinetResource) : son identité légale, son type d'activité et
 * ses réglages fiscaux communs, en lecture ; les autorisations qu'il
 * accorde au cabinet ; ses magasins réels (nom, code, ville, statut),
 * chacun avec le bouton qui l'ouvre dans le panneau des magasins ; pour le
 * propriétaire du cabinet, les collaborateurs à qui il est confié ; et
 * l'origine du lien. Rien d'autre du client, ni d'un autre client.
 */
class FicheDuClient extends ViewRecord
{
    protected static string $resource = ClientDuCabinetResource::class;

    protected static string $view = 'filament.cabinet.resources.fiche-du-client';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mettreFin')
                ->label('Mettre fin au lien')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(ClientDuCabinetResource::CONFIRMATION_DE_LA_FIN)
                ->visible(fn (): bool => auth()->user()?->can('mettreFin', $this->lien()) ?? false)
                ->action(function (): void {
                    ClientDuCabinetResource::mettreFin($this->lien());
                    $this->redirect(ClientDuCabinetResource::getUrl('index'));
                }),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->client()->raison_sociale ?? 'Client';
    }

    public function lien(): LienCabinet
    {
        /** @var LienCabinet */
        return $this->getRecord();
    }

    public function client(): Societe
    {
        return ClientDuCabinetResource::client($this->lien());
    }

    /**
     * Ses magasins réels, par nom, chacun avec l'adresse qui l'ouvre dans le
     * panneau des magasins : lus par le seul client du lien ouvert, aucun
     * identifiant de magasin n'entre ici par une requête.
     *
     * @return list<array{nom: string, code: string|null, ville: string|null, statut: string, url: string}>
     */
    public function magasins(): array
    {
        return array_values(Magasin::query()->where('societe_id', $this->lien()->societe_id)->where('est_demonstration', false)->orderBy('nom')->orderBy('id')
            ->get(['id', 'uuid', 'nom', 'code', 'ville', 'statut'])
            ->map(fn (Magasin $magasin): array => [
                'nom' => (string) $magasin->nom,
                'code' => $magasin->code,
                'ville' => $magasin->ville,
                'statut' => (string) $magasin->statut->getLabel(),
                'url' => Dashboard::getUrl(panel: 'app', tenant: $magasin),
            ])
            ->all());
    }

    /**
     * Les libellés des autorisations que le client accorde au cabinet.
     *
     * @return list<string>
     */
    public function autorisations(): array
    {
        return array_values(array_intersect_key(PermissionRegistry::libellesDuCabinet($this->client()->type_activite), array_flip($this->lien()->autorisations())));
    }

    /**
     * @return list<string>
     */
    public function collaborateurs(): array
    {
        return CompteConnecte::exiger()->estProprietaire() ? ClientDuCabinetResource::collaborateursConfies($this->lien()) : [];
    }
}
