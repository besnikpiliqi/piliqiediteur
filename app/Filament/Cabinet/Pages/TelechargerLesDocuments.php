<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\TelechargeLesDocumentsParPeriode;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * « Télécharger les documents » de l'espace du cabinet (décision de
 * l'éditeur du 03/11/2026, lot EX, F2 = oui ; voir
 * TelechargeLesDocumentsParPeriode) : pour un client lié que le cabinet
 * ouvre au compte (son propriétaire : tous ses clients ; un collaborateur :
 * ceux qui lui sont confiés, voir AccesDesCabinets::liensOuverts()), les
 * documents de ses magasins réels où le client accorde au cabinet le
 * téléchargement par période, jamais par défaut (voir
 * DocumentsDeLaPeriode::magasinsDuClient()) ; seuls les documents que ses
 * autres autorisations accordées lui laissent voir. Le client se choisit
 * en tête de la page ; un client qui ne s'y trouve pas ne se lit pas.
 */
class TelechargerLesDocuments extends Page
{
    use TelechargeLesDocumentsParPeriode;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Télécharger les documents';

    protected static ?string $title = 'Télécharger les documents';

    protected static ?string $slug = 'telecharger-les-documents';

    protected static string $view = 'filament.telecharger-les-documents';

    /**
     * L'uuid du client choisi, parmi ceux que la page propose.
     */
    public string $client = '';

    public static function canAccess(): bool
    {
        $compte = auth()->user();

        return $compte instanceof User && self::clientsProposes($compte)->isNotEmpty();
    }

    public function mount(): void
    {
        $compte = auth()->user();
        $this->client = $compte instanceof User ? (string) self::clientsProposes($compte)->first()?->uuid : '';
        $this->preparerLaPage();
    }

    /**
     * Un autre client choisi : ses magasins, ses types, ce mois, rien de
     * coché.
     */
    public function updatedClient(): void
    {
        $this->selection = [];
        $this->preparerLaPage();
    }

    /**
     * Les clients que le cabinet ouvre au compte, où il télécharge dans au
     * moins un magasin, par dénomination.
     *
     * @return Collection<int, Societe>
     */
    private static function clientsProposes(User $compte): Collection
    {
        $documents = app(DocumentsDeLaPeriode::class);

        return app(AccesDesCabinets::class)->liensOuverts($compte)
            ->with(['client' => fn ($client) => $client->select(['id', 'uuid', 'raison_sociale', 'siren'])])
            ->get()
            ->map(fn (LienCabinet $lien): Societe => $lien->client)
            ->filter(fn (Societe $client): bool => $documents->magasinsDuClient($compte, $client)->isNotEmpty())
            ->sortBy(fn (Societe $client): string => mb_strtolower($client->raison_sociale ?? ''))
            ->values();
    }

    protected function magasinsProposes(): Collection
    {
        $compte = auth()->user();
        $client = $this->societeDesDocuments();

        return $compte instanceof User && $client instanceof Societe ? app(DocumentsDeLaPeriode::class)->magasinsDuClient($compte, $client) : collect();
    }

    protected function societeDesDocuments(): ?Societe
    {
        $compte = auth()->user();

        return $compte instanceof User ? self::clientsProposes($compte)->first(fn (Societe $client): bool => $client->uuid === $this->client) : null;
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
        $compte = auth()->user();

        return [
            ...$this->donneesDeLaVue(),
            'clients' => $compte instanceof User ? self::clientsProposes($compte)->mapWithKeys(fn (Societe $client): array => [$client->uuid => "{$client->raison_sociale} ({$client->siren})"])->all() : [],
            'plusieursMagasins' => true,
        ];
    }
}
