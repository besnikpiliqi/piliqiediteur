<?php

namespace App\Filament\App\Livewire;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Recherche\RechercheSansAccents;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Le tableau des clients (point 22), qu'ouvre la loupe du champ
 * « Client » à la caisse, sur une facture ou un devis (voir
 * ClientResource::actionChercherUnClient()) : les clients du magasin actif
 * (MagasinScope), jamais un client archivé ; une recherche par nom,
 * SIREN, SIRET, n° de TVA, e-mail, téléphone, ville ou code postal, sans
 * casse ni accents (voir RechercheSansAccents) ; du plus récent au plus
 * ancien, le client comptoir toujours en tête ; les filtres particuliers,
 * professionnels et « avec un crédit d'avoir » ; et « Nouveau client »
 * pour qui peut en créer.
 *
 * Les identifiants, les coordonnées, le crédit d'avoir et le dernier achat
 * de chaque client, et la recherche ou le filtre qui les lisent, ne
 * servent qu'à qui voit les fiches des clients (« Clients : voir », voir
 * ClientResource::voitLesFichesDesClients()) : les autres ne voient que le
 * nom et le type des clients, et ne cherchent que par le nom.
 *
 * Un clic sur une rangée choisit le client : il part à la page (voir
 * RecoitLeClientChoisi), qui le revérifie, le pose dans le formulaire et
 * ferme la fenêtre ; à la caisse, son mode de paiement préféré se
 * présélectionne alors.
 */
class SelecteurDeClients extends Component implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    /**
     * L'événement qui porte le client choisi à la page du document.
     */
    public const EVENEMENT = 'client-choisi';

    /**
     * Le crédit d'avoir des clients affichés, lu une fois par requête, en
     * deux lectures quel que soit le nombre de clients (voir
     * Client::creditsDAvoirEnCentimes()).
     *
     * @var array<int, int>|null
     */
    private ?array $credits = null;

    /**
     * Ouvert seulement à qui saisit une vente, à chaque requête (voir
     * AjouteDesLignesDeVente::peutSaisirUneVente()).
     */
    public function boot(): void
    {
        abort_unless(DevisResource::peutSaisirUneVente(), 403);
    }

    public function table(Table $table): Table
    {
        $voitLesFiches = ClientResource::voitLesFichesDesClients();

        return $table
            ->query(fn (): Builder => Client::query())
            // Avant le tri choisi : le client comptoir reste en tête. Seul
            // il a est_comptoir rempli (vrai), les autres l'ont vide, et
            // PostgreSQL range les valeurs vides en tête d'un tri
            // décroissant, SQLite à la fin : « est vide » se trie pareil
            // sur les deux moteurs.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderByRaw($query->getQuery()->getGrammar()->wrap($query->qualifyColumn('est_comptoir')).' is null'))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordAction('choisir')
            ->searchPlaceholder($voitLesFiches ? 'Nom, SIREN, n° de TVA, e-mail, téléphone, ville...' : 'Nom du client')
            ->emptyStateHeading('Aucun client ne correspond.')
            ->columns([
                TextColumn::make('nom')
                    ->label('Nom')
                    ->description(fn (Client $client): ?string => $client->estComptoir() ? 'Vente anonyme' : null)
                    ->searchable(query: RechercheSansAccents::dans(...ClientResource::colonnesDeRecherche()))
                    ->sortable(),
                TextColumn::make('type_client')->label('Type')->badge(),
                TextColumn::make('siren')
                    ->label('SIREN ou SIRET')
                    ->state(fn (Client $client): ?string => $client->siret ?? $client->siren)
                    ->placeholder('—')
                    ->visible($voitLesFiches),
                TextColumn::make('numero_tva')->label('N° de TVA')->placeholder('—')->visible($voitLesFiches),
                TextColumn::make('email')->label('E-mail')->placeholder('—')->visible($voitLesFiches),
                TextColumn::make('telephone')->label('Téléphone')->placeholder('—')->visible($voitLesFiches),
                TextColumn::make('ville')->label('Ville')->placeholder('—')->visible($voitLesFiches),
                TextColumn::make('credit_d_avoir')
                    ->label('Crédit d\'avoir')
                    ->state(fn (Client $client): ?string => ($credit = $this->credits()[$client->getKey()] ?? 0) > 0 ? app(CalculateurLigne::class)->enEuros($credit) : null)
                    ->placeholder('—')
                    ->color('warning')
                    ->visible($voitLesFiches),
                // Celui d'une facture ou d'un ticket que la personne voit
                // (voir Facture::scopeVisiblesPar()).
                TextColumn::make('factures_max_date_emission')
                    ->label('Dernier achat')
                    ->max(['factures' => fn (Builder $query): Builder => $query->visiblesPar(auth()->user())], 'date_emission')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->visible($voitLesFiches)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->date('d/m/Y')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('created_at', $direction)->orderBy('id', $direction)),
            ])
            ->filters([
                SelectFilter::make('type_client')
                    ->label('Type')
                    ->options([
                        TypeClient::Particulier->value => 'Particuliers',
                        TypeClient::Professionnel->value => 'Professionnels',
                    ]),
                Filter::make('avec_credit')
                    ->label('Avec un crédit d\'avoir')
                    ->toggle()
                    ->visible($voitLesFiches)
                    ->query(fn (Builder $query): Builder => $query->avecUnCreditDAvoir()),
            ])
            ->headerActions([
                Action::make('nouveauClient')
                    ->label('Nouveau client')
                    ->icon('heroicon-m-plus')
                    ->authorize(fn (): bool => auth()->user()?->can(PermissionRegistry::CLIENT_CREATE) ?? false)
                    ->modalHeading('Nouveau client')
                    ->form(ClientResource::creationRapideSchema())
                    ->action(fn (array $data) => $this->choisir(Client::create($data)->getKey())),
            ]);
    }

    /**
     * Un clic sur la rangée, ou le client qui vient d'être créé : il part
     * à la page du document, s'il est du catalogue du magasin et non
     * archivé ; jamais, même par une requête forgée, un client d'un autre
     * magasin.
     */
    public function choisir(int|string $cle): void
    {
        $client = Client::query()->find((int) $cle);

        if ($client === null) {
            Notification::make()->title(ClientResource::CLIENT_INTROUVABLE)->danger()->send();

            return;
        }

        $this->dispatch(self::EVENEMENT, clientId: $client->getKey());
    }

    public function render(): View
    {
        return view('filament.app.livewire.selecteur-de-clients');
    }

    /**
     * @return array<int, int>
     */
    private function credits(): array
    {
        return $this->credits ??= Client::creditsDAvoirEnCentimes($this->getTableRecords()->items());
    }
}
