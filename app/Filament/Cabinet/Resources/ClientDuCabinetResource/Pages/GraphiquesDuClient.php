<?php

namespace App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Le tableau de bord d'un client lié, dans l'espace du cabinet (décision
 * de l'éditeur du 14/11/2026) : ses chiffres clés et ses graphiques, sur
 * les magasins réels que le client ouvre au cabinet, selon les
 * consultations qu'il lui accorde (voir GrilleDesGraphiques et
 * PerimetreDesGraphiques::duClient()). Le lien se lit parmi ceux que le
 * cabinet ouvre au compte (voir ClientDuCabinetResource) : un lien d'un
 * autre cabinet, d'un client non confié ou d'un cabinet qui n'est plus
 * vérifié donne 404. Un client qui n'a encore aucun magasin réel (une
 * société invitée par le cabinet naît sans magasin) n'a pas de graphiques
 * : la page le dit, sans monter la grille (voir
 * PerimetreDesGraphiques::voitLesGraphiquesDuClient()). Les préférences
 * du compte valent pour tous ses clients.
 */
class GraphiquesDuClient extends Page
{
    use InteractsWithRecord;

    public const SANS_MAGASIN = 'Ce client n\'a encore aucun magasin : ses chiffres clés et ses graphiques s\'afficheront avec son premier magasin.';

    protected static string $resource = ClientDuCabinetResource::class;

    protected static string $view = 'filament.cabinet.resources.graphiques-du-client';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Tableau de bord de '.(ClientDuCabinetResource::client($this->lien())->raison_sociale ?? 'ce client');
    }

    public function lien(): LienCabinet
    {
        return ValeursLues::instance($this->getRecord(), LienCabinet::class);
    }

    public function voitLesGraphiques(): bool
    {
        return app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuClient(CompteConnecte::exiger(), $this->lien());
    }
}
