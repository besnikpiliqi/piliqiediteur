<?php

namespace App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\PageDesCharges;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Les charges d'un client lié, dans l'espace du cabinet (décision de
 * l'éditeur du 14/11/2026, lot CHARGES), pour son propriétaire et le
 * collaborateur à qui le client est confié, quand le client accorde au
 * cabinet « Charges : voir, ajouter, saisir et valider » (accordée par
 * défaut) : celles de « Toute la société » et des magasins réels qu'il lui
 * ouvre (voir DroitsSurLesCharges et PageDesCharges). Le lien se lit parmi
 * ceux que le cabinet ouvre au compte (voir ClientDuCabinetResource) : un
 * lien d'un autre cabinet ou d'un client non confié donne 404 ; un client
 * qui retire l'autorisation, 403.
 */
class ChargesDuClient extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ClientDuCabinetResource::class;

    protected static string $view = 'filament.charges.page';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        if (app(DroitsSurLesCharges::class)->pour(CompteConnecte::exiger(), ClientDuCabinetResource::client($this->lien())) === null) {
            throw new AuthorizationException(PageDesCharges::REFUS);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Charges de '.(ClientDuCabinetResource::client($this->lien())->raison_sociale ?? 'ce client');
    }

    public function lien(): LienCabinet
    {
        return ValeursLues::instance($this->getRecord(), LienCabinet::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $this->lien()->uuid];
    }
}
