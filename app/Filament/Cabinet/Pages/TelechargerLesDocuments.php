<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\Concerns\TelechargeLesDocumentsParPeriode;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * « Télécharger les documents » de l'espace du cabinet (décision de
 * l'éditeur du 03/11/2026, lot EX, F2 = oui ; voir
 * TelechargeLesDocumentsParPeriode) : pour un client lié que le cabinet
 * ouvre au compte (son propriétaire : tous ses clients ; un collaborateur :
 * ceux qui lui sont confiés, voir AccesDesCabinets::magasinsOuverts()), les
 * documents de ses magasins réels où le client accorde au cabinet le
 * téléchargement par période, jamais par défaut (voir
 * DocumentsDeLaPeriode::magasinsDesClients()) ; seuls les documents que ses
 * autres autorisations accordées lui laissent voir. Le client se choisit
 * en tête de la page ; un client qui ne s'y trouve pas ne se lit pas. Ses
 * raccourcis d'exercice suivent le mois de fin d'exercice de ce client et
 * ses changements légaux (voir ExercicesDeLaSociete), sa société étant lue
 * entière avec ses magasins.
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

    /**
     * Les magasins proposés de chaque client, par identifiant de sa
     * société, lus une fois par requête.
     *
     * @var Collection<int, Collection<int, Magasin>>|null
     */
    private ?Collection $magasinsParClient = null;

    public static function canAccess(): bool
    {
        $compte = auth()->user();

        return $compte instanceof User && app(DocumentsDeLaPeriode::class)->magasinsDesClients($compte)->isNotEmpty();
    }

    public function mount(): void
    {
        $this->client = (string) $this->clientsProposes()->first()?->uuid;
        $this->preparerLaPage();
    }

    /**
     * Un autre client choisi : ses magasins, ses types, ce mois, rien de
     * coché.
     */
    public function updatedClient(): void
    {
        $this->selection = [];
        $this->oublierLesMagasinsProposes();
        $this->preparerLaPage();
    }

    /**
     * Les clients que le cabinet ouvre au compte, où il télécharge dans au
     * moins un magasin, par dénomination.
     *
     * @return Collection<int, Societe>
     */
    private function clientsProposes(): Collection
    {
        return $this->magasinsParClient()
            ->map(fn (Collection $magasins): Societe => ValeurRequise::de($magasins->first(), 'le premier magasin d\'un client')->societe)
            ->sortBy(fn (Societe $client): string => mb_strtolower($client->raison_sociale ?? ''))
            ->values();
    }

    /**
     * @return Collection<int, Collection<int, Magasin>>
     */
    private function magasinsParClient(): Collection
    {
        $compte = auth()->user();

        return $this->magasinsParClient ??= $compte instanceof User ? $this->documentsDeLaPeriode()->magasinsDesClients($compte) : new Collection;
    }

    protected function chercherLesMagasins(): Collection
    {
        $client = $this->societeDesDocuments();

        return $client instanceof Societe ? $this->magasinsParClient()->get(CleDuModele::de($client), new Collection) : new Collection;
    }

    protected function societeDesDocuments(): ?Societe
    {
        return $this->clientsProposes()->first(fn (Societe $client): bool => $client->uuid === $this->client);
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
        return [
            ...$this->donneesDeLaVue(),
            'clients' => $this->clientsProposes()->mapWithKeys(fn (Societe $client): array => [$client->uuid => "{$client->raison_sociale} ({$client->siren})"])->all(),
            'plusieursMagasins' => true,
        ];
    }
}
