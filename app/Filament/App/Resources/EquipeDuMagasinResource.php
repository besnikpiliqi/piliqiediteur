<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages;
use App\Filament\Concerns\CocheLesAutorisationsDeMagasin;
use App\Filament\Concerns\GereLesComptes;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'équipe du magasin, dans son panneau : ses vendeurs, ses observateurs
 * et ses responsables (les affectations de membre du magasin actif), que
 * gèrent son propriétaire et ses responsables, selon les règles de
 * PorteeDesDroits (voir AffectationPolicy et MembrePolicy) :
 * - créer un vendeur ou un observateur de ce magasin, avec les cases que
 *   son rôle précoche, ou le proposer à un compte retiré de sa société
 *   (voir ListEquipeDuMagasin), et, pour le propriétaire, nommer un
 *   responsable ;
 * - changer le rôle et les cases d'un vendeur ou d'un observateur, le
 *   retirer du magasin (de la société, si c'était son dernier magasin) ;
 * - suspendre, réactiver, retirer de la société, renouveler l'accès, pour
 *   qui en a le droit sur ce compte (voir GereLesComptes).
 * Un membre qui travaille aussi dans un autre magasin y figure avec la
 * seule mention « aussi dans d'autres magasins », sans dire lesquels ; ni
 * les administrateurs ni les experts-comptables n'y figurent.
 *
 * Le modèle Affectation n'a pas de MagasinScope (les droits se lisent avant
 * tout contexte) : la requête se filtre ici explicitement par le magasin
 * actif et par un rôle de magasin non nul. Fermée à la recherche globale.
 */
class EquipeDuMagasinResource extends Resource
{
    use CocheLesAutorisationsDeMagasin;
    use GereLesComptes;

    protected static ?string $model = Affectation::class;

    protected static ?string $tenantOwnershipRelationshipName = 'magasin';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Équipe du magasin';

    protected static ?string $modelLabel = 'membre de l\'équipe';

    protected static ?string $pluralModelLabel = 'équipe du magasin';

    protected static ?string $slug = 'equipe';

    /**
     * Les affectations de membre du magasin actif, et elles seules.
     *
     * @return Builder<Affectation>
     */
    public static function getEloquentQuery(): Builder
    {
        $contexte = app(TenantContext::class);

        return parent::getEloquentQuery()
            ->with('utilisateur')
            ->where('affectations.magasin_id', $contexte->has() ? $contexte->id() : 0)
            ->whereNotNull('affectations.role');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identité')
                ->visibleOn('create')
                ->schema([
                    TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                    TextInput::make('email')->label('Adresse e-mail de connexion')->email()->adresseDeConnexion()->required()->maxLength(255),
                    static::champMotDePasseProvisoire(),
                ])
                ->columns(2),
            Section::make('Dans ce magasin')->schema([
                static::champRole(),
                ...static::champsAutorisations(),
            ])->columns(2),
        ]);
    }

    /**
     * Le rôle dans ce magasin : vendeur ou observateur ; responsable
     * aussi pour le propriétaire. Changer de rôle précoche le modèle du
     * nouveau rôle.
     */
    public static function champRole(): Select
    {
        return Select::make('role')
            ->label('Rôle')
            ->options(fn (): array => collect(RoleMagasin::cases())
                ->filter(fn (RoleMagasin $role): bool => $role->aDesCases() || static::proprietaireConnecte())
                ->mapWithKeys(fn (RoleMagasin $role): array => [$role->value => $role->getLabel()])
                ->all())
            ->required()
            ->live()
            ->afterStateUpdated(function (Set $set, ?string $state): void {
                foreach (static::autorisationsAAfficher(RoleMagasin::tryFrom((string) $state)?->permissionsParDefaut() ?? [], 'autorisations') as $champ => $cases) {
                    $set($champ, $cases);
                }
            });
    }

    /**
     * Ses cases dans ce magasin, et dans ce seul magasin, celles de la
     * vente à part (voir CocheLesAutorisationsDeMagasin) ; aucune pour un
     * responsable, qui a toutes les autorisations du magasin.
     *
     * @return list<CheckboxList>
     */
    public static function champsAutorisations(): array
    {
        return static::champsDesAutorisationsDeMagasin(
            'autorisations',
            'Autorisations dans ce magasin',
            'Le rôle choisi les a précochées : cochez ou décochez ce que cette personne peut voir et faire dans ce magasin.',
            'Vente',
            'Créer et émettre un ticket ou une facture, enregistrer un encaissement, vendre sans stock : cette personne aura sa caisse personnelle.',
            fn (Get $get): bool => RoleMagasin::tryFrom((string) $get('role')) === RoleMagasin::Responsable,
        );
    }

    public static function proprietaireConnecte(): bool
    {
        $magasin = Filament::getTenant();

        return $magasin instanceof Magasin && (auth()->user()?->estProprietaireDuMagasin($magasin) ?? false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('utilisateur.name')->label('Nom')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('utilisateur.email')->label('Adresse e-mail')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('role')->label('Rôle')->badge(),
                Tables\Columns\TextColumn::make('utilisateur.statut')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('autres_magasins')
                    ->label('Ailleurs')
                    ->state(fn (Affectation $record): ?string => app(PorteeDesDroits::class)->aAussiDAutresMagasins($record->utilisateur, $record->magasin) ? 'Aussi dans d\'autres magasins' : null)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->label('Rôle')->options(RoleMagasin::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Rôle et autorisations'),
                Tables\Actions\Action::make('nommer_responsable')
                    ->label('Nommer responsable')
                    ->icon('heroicon-o-star')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Affectation $record): string => "{$record->utilisateur->name} aura tous les droits dans ce magasin, sans case.")
                    ->visible(fn (Affectation $record): bool => ! $record->estDeResponsable() && app(PorteeDesDroits::class)->peutDonnerLeRole(static::auteur(), $record->utilisateur, $record->magasin, RoleMagasin::Responsable))
                    ->action(fn (Affectation $record) => static::executerLeGeste(fn () => app(ModifierMembreAction::class)->handle(static::auteur(), $record->utilisateur, [$record->magasin_id => ['role' => RoleMagasin::Responsable]]))),
                Tables\Actions\Action::make('retirer_du_magasin')
                    ->label('Retirer du magasin')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Affectation $record): string => app(PorteeDesDroits::class)->aAussiDAutresMagasins($record->utilisateur, $record->magasin)
                        ? "{$record->utilisateur->name} n'aura plus accès à ce magasin ; ses autres magasins ne changent pas."
                        : "C'est le dernier magasin de {$record->utilisateur->name} : {$record->utilisateur->name} sera retiré de la société.")
                    ->authorize('delete')
                    ->action(fn (Affectation $record) => static::executerLeGeste(fn () => app(ModifierMembreAction::class)->handle(static::auteur(), $record->utilisateur, [$record->magasin_id => null]))),
                static::actionSuspendre(Tables\Actions\Action::make('suspendre'), fn (Affectation $record): User => $record->utilisateur),
                static::actionReactiver(Tables\Actions\Action::make('reactiver'), fn (Affectation $record): User => $record->utilisateur),
                static::actionRetirerDeLaSociete(Tables\Actions\Action::make('retirer_de_la_societe'), fn (Affectation $record): User => $record->utilisateur),
                static::actionRenvoyerUnLien(Tables\Actions\Action::make('renvoyer_un_lien'), fn (Affectation $record): User => $record->utilisateur),
                static::actionRedonnerUnProvisoire(Tables\Actions\Action::make('redonner_un_provisoire'), fn (Affectation $record): User => $record->utilisateur),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipeDuMagasin::route('/'),
            'create' => Pages\CreateMembreDuMagasin::route('/create'),
            'edit' => Pages\EditMembreDuMagasin::route('/{record}/edit'),
        ];
    }
}
