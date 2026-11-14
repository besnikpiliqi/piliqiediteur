<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\TelechargeLesDocumentsParPeriode;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * « Télécharger les documents » de l'espace de la société (décision de
 * l'éditeur du 03/11/2026, lot EX ; voir TelechargeLesDocumentsParPeriode) :
 * les documents de plusieurs magasins de la portée de la personne (le
 * propriétaire : tous ; un administrateur : ceux de sa portée), chacun où
 * elle a l'autorisation « télécharger les documents par période » (voir
 * DocumentsDeLaPeriode::magasinsDeLaSociete()) ; les raccourcis de la
 * période prennent le jour de Paris, et chaque magasin classe ses
 * documents dans son calendrier. Fermée à qui n'y télécharge dans aucun
 * magasin.
 */
class TelechargerLesDocuments extends Page
{
    use TelechargeLesDocumentsParPeriode;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Télécharger les documents';

    protected static ?string $title = 'Télécharger les documents';

    protected static ?string $slug = 'telecharger-les-documents';

    protected static string $view = 'filament.telecharger-les-documents';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $societe = Filament::getTenant();

        return $compte instanceof User && $societe instanceof Societe && app(DocumentsDeLaPeriode::class)->magasinsDeLaSociete($compte, $societe)->isNotEmpty();
    }

    public function mount(): void
    {
        $this->preparerLaPage();
    }

    protected function chercherLesMagasins(): Collection
    {
        $compte = auth()->user();
        $societe = Filament::getTenant();

        return $compte instanceof User && $societe instanceof Societe ? $this->documentsDeLaPeriode()->magasinsDeLaSociete($compte, $societe) : new Collection;
    }

    protected function societeDesDocuments(): ?Societe
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe ? $societe : null;
    }

    protected function calendrierDesRaccourcis(): Calendrier
    {
        return Calendrier::deParis();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [...$this->donneesDeLaVue(), 'clients' => null, 'plusieursMagasins' => true];
    }
}
