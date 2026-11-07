<?php

namespace App\Filament\App\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\TelechargeLesDocumentsParPeriode;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * « Télécharger les documents » d'un magasin (décision de l'éditeur du
 * 03/11/2026, lot EX ; voir TelechargeLesDocumentsParPeriode) : les
 * documents de ce seul magasin, dans son calendrier, pour tout compte qui
 * y a l'autorisation « télécharger les documents par période » (voir
 * DocumentsDeLaPeriode::peutTelechargerDans()), le propriétaire toujours,
 * un compte du cabinet lié quand le client la lui coche. Ouverte même
 * quand le magasin est en lecture seule ; jamais dans un magasin réel
 * d'une société qui n'est pas validée.
 */
class TelechargerLesDocuments extends Page
{
    use TelechargeLesDocumentsParPeriode;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Télécharger les documents';

    protected static ?string $title = 'Télécharger les documents';

    protected static ?string $slug = 'telecharger-les-documents';

    protected static string $view = 'filament.telecharger-les-documents';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin && app(DocumentsDeLaPeriode::class)->peutTelechargerDans($compte, $magasin);
    }

    public function mount(): void
    {
        $this->preparerLaPage();
    }

    protected function magasinsProposes(): Collection
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin ? app(DocumentsDeLaPeriode::class)->magasinsDuMagasin($compte, $magasin) : collect();
    }

    protected function societeDesDocuments(): ?Societe
    {
        $magasin = Filament::getTenant();

        return $magasin instanceof Magasin ? $magasin->societe : null;
    }

    protected function calendrierDesRaccourcis(): Calendrier
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        return $magasin->calendrier();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [...$this->donneesDeLaVue(), 'clients' => null, 'plusieursMagasins' => false];
    }
}
