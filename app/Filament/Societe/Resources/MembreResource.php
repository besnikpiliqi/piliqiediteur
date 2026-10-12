<?php

namespace App\Filament\Societe\Resources;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Concerns\GereLesComptes;
use App\Filament\Societe\Resources\MembreResource\Pages;
use App\Filament\Societe\RessourceDuPanneauSociete;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les comptes de la société, dans son espace d'administration (voir
 * MembrePolicy et PorteeDesDroits) :
 * - pour le propriétaire, tous les comptes, experts-comptables compris,
 *   avec leurs magasins et leurs autorisations, et tous les gestes :
 *   créer un membre (dans un ou plusieurs magasins), un administrateur, un
 *   expert-comptable ; nommer administrateur ; modifier les magasins d'un
 *   membre ; modifier ou rétrograder un administrateur ; suspendre,
 *   réactiver, retirer, réintégrer ; renouveler un accès ; annuler la
 *   nomination d'un responsable par un administrateur ; proposer à un
 *   compte retiré de rejoindre la société ; transmettre la propriété à un
 *   administrateur dont la qualité de représentant est prouvée ;
 * - pour un administrateur autorisé à nommer les responsables, les
 *   responsables de ses magasins : en nommer (un nouveau compte, ou un
 *   vendeur ou un observateur du magasin), leur retirer un magasin de sa
 *   portée ou les déplacer vers un autre, annuler sa propre nomination,
 *   proposer à un compte retiré d'en devenir un, et, pour une personne qui
 *   n'est que responsable de magasins de sa portée, la retirer de la
 *   société ou lui envoyer un lien ;
 * - fermée à tout autre compte.
 * Les magasins proposés se lisent par la portée de la personne connectée,
 * dans sa société. Personne ne change ici le nom ni l'adresse d'un autre
 * compte, et un compte ne se supprime jamais.
 */
class MembreResource extends RessourceDuPanneauSociete
{
    use GereLesComptes;

    protected static ?string $model = User::class;

    protected static ?string $tenantOwnershipRelationshipName = 'societe';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Comptes';

    protected static ?string $modelLabel = 'compte';

    protected static ?string $pluralModelLabel = 'comptes';

    protected static ?string $slug = 'comptes';

    /**
     * Les comptes que la personne connectée voit (voir
     * PorteeDesDroits::comptesVisibles()).
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        $auteur = auth()->user();

        return $auteur instanceof User
            ? app(PorteeDesDroits::class)->comptesVisibles($auteur)
            : User::query()->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nom')->rechercheSansAccents()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Adresse e-mail')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('role_societe')->label('Rôle')->badge(),
                Tables\Columns\TextColumn::make('magasins')->label('Magasins')->state(fn (User $record): string => static::resumeDesMagasins($record))->wrap(),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_societe')->label('Rôle')->options(RoleSociete::class),
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->options(StatutCompte::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Fiche'),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Compte')->schema([
                TextEntry::make('name')->label('Nom'),
                TextEntry::make('email')->label('Adresse e-mail de connexion'),
                TextEntry::make('role_societe')->label('Rôle dans la société')->badge(),
                TextEntry::make('statut')->label('Statut')->badge(),
                TextEntry::make('premiere_connexion')
                    ->label('Mot de passe')
                    ->state(fn (User $record): string => $record->doitChangerSonMotDePasse() ? 'Provisoire, pas encore changé' : 'Choisi par la personne'),
            ])->columns(2),
            Section::make('Magasins')->schema([
                RepeatableEntry::make('affectationsVisibles')
                    ->label('')
                    ->state(fn (User $record): array => static::affectationsVisibles($record))
                    ->schema([
                        TextEntry::make('magasin')->label('Magasin'),
                        TextEntry::make('role')->label('Rôle'),
                        TextEntry::make('autorisations')->label('Autorisations')->listWithLineBreaks()->placeholder('—'),
                        TextEntry::make('nomination')->label('Nomination')->placeholder('—'),
                    ])
                    ->columns(4),
            ])->visible(fn (User $record): bool => ! $record->estProprietaire() && ! $record->estExpertComptable()),
            Section::make('Autorisations de société')->schema([
                TextEntry::make('autorisations_de_societe')
                    ->label('')
                    ->state(fn (User $record): array => array_map(fn (string $cle): string => AutorisationsSociete::libelles()[$cle], $record->autorisationsDeSociete()))
                    ->listWithLineBreaks()
                    ->placeholder('Aucune'),
            ])->visible(fn (User $record): bool => $record->estAdministrateur() && (auth()->user()?->estProprietaire() ?? false)),
        ]);
    }

    /**
     * Ses magasins, comme les voit la personne connectée : tous pour le
     * propriétaire ; pour un administrateur autorisé, ceux de sa portée.
     */
    public static function resumeDesMagasins(User $compte): string
    {
        if ($compte->estProprietaire()) {
            return 'Tous les magasins (propriétaire)';
        }

        if ($compte->estExpertComptable()) {
            return 'Tous les magasins réels, en lecture';
        }

        if ($compte->estAdministrateur() && $compte->administreTousLesMagasins()) {
            return 'Tous les magasins (administrateur)';
        }

        return collect(static::affectationsVisibles($compte))->map(fn (array $ligne): string => "{$ligne['magasin']} ({$ligne['role']})")->implode(', ') ?: '—';
    }

    /**
     * Ses affectations dans les magasins que la personne connectée voit :
     * tous ceux de la société pour le propriétaire, ceux de sa portée pour
     * un administrateur autorisé ; leurs cases pour le propriétaire seul.
     *
     * @return list<array{affectation: int, magasin: string, role: string, autorisations: list<string>, nomination: string|null}>
     */
    public static function affectationsVisibles(User $compte): array
    {
        $auteur = auth()->user();

        if (! $auteur instanceof User) {
            return [];
        }

        $portee = $auteur->magasinsDeSaPortee()->pluck('magasins.id')->map(fn ($id): int => (int) $id)->all();

        return Affectation::query()
            ->where('societe_id', $auteur->societe_id)
            ->where('user_id', $compte->getKey())
            ->whereIn('magasin_id', $portee)
            ->with('magasin')
            ->orderBy('magasin_id')
            ->get()
            ->map(fn (Affectation $affectation): array => [
                'affectation' => (int) $affectation->getKey(),
                'magasin' => $affectation->magasin->nom,
                'role' => $affectation->role?->getLabel() ?? 'Administrateur',
                'autorisations' => $auteur->estProprietaire() && $affectation->role !== RoleMagasin::Responsable
                    ? array_map(fn (string $cle): string => PermissionRegistry::libelles()[$cle], $affectation->autorisations())
                    : [],
                'nomination' => $affectation->nomme_par === null ? null : 'Nommé par '.(User::query()->find($affectation->nomme_par)?->name ?? 'un administrateur').' : annulable',
            ])
            ->all();
    }

    /**
     * Les magasins de la société que la personne connectée peut proposer :
     * ceux de sa portée, par nom.
     *
     * @return array<int, string>
     */
    public static function magasinsDeLaPortee(): array
    {
        $auteur = auth()->user();

        if (! $auteur instanceof User) {
            return [];
        }

        return $auteur->magasinsDeSaPortee()->orderBy('nom')->pluck('nom', 'magasins.id')->all();
    }

    /**
     * Les champs d'affectations de membre d'un compte, que le propriétaire
     * choisit magasin par magasin : le magasin, le rôle, et les cases d'un
     * vendeur ou d'un observateur, précochées par son rôle.
     */
    public static function champAffectations(string $nom = 'affectations'): Repeater
    {
        return Repeater::make($nom)
            ->label('Magasins')
            ->schema([
                Select::make('magasin_id')
                    ->label('Magasin')
                    ->options(fn (): array => static::magasinsDeLaPortee())
                    ->required()
                    ->distinct(),
                Select::make('role')
                    ->label('Rôle')
                    ->options(RoleMagasin::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('autorisations', RoleMagasin::tryFrom((string) ($state instanceof RoleMagasin ? $state->value : $state))?->permissionsParDefaut() ?? [])),
                CheckboxList::make('autorisations')
                    ->label('Autorisations dans ce magasin')
                    ->options(PermissionRegistry::libelles())
                    ->columns(2)
                    ->bulkToggleable()
                    ->hidden(fn (Get $get): bool => in_array($get('role'), [RoleMagasin::Responsable, RoleMagasin::Responsable->value], true))
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Ajouter un magasin');
    }

    /**
     * Les affectations saisies, par identifiant de magasin, comme les
     * reçoivent les actions de l'équipe.
     *
     * @param  list<array{magasin_id: int|string, role: RoleMagasin|string, autorisations?: list<string>|null}>  $lignes
     * @return array<int, array{role: string, autorisations: list<string>}>
     */
    public static function affectationsSaisies(array $lignes): array
    {
        $affectations = [];

        foreach ($lignes as $ligne) {
            $role = $ligne['role'] instanceof RoleMagasin ? $ligne['role']->value : (string) $ligne['role'];
            $affectations[(int) $ligne['magasin_id']] = ['role' => $role, 'autorisations' => $role === RoleMagasin::Responsable->value ? [] : array_values($ligne['autorisations'] ?? [])];
        }

        return $affectations;
    }

    /**
     * Les champs de la portée et des autorisations d'un administrateur,
     * sans rien de présélectionné pour sa portée.
     *
     * @return list<Component>
     */
    public static function champsDeLAdministrateur(): array
    {
        return [
            Radio::make('portee')
                ->label('Accès')
                ->options(['tous' => 'Tous les magasins, présents et futurs', 'selection' => 'Une sélection de magasins'])
                ->required()
                ->live(),
            CheckboxList::make('magasins')
                ->label('Magasins de sa sélection')
                ->options(fn (): array => static::magasinsDeLaPortee())
                ->required(fn (Get $get): bool => $get('portee') === 'selection')
                ->visible(fn (Get $get): bool => $get('portee') === 'selection'),
            CheckboxList::make('autorisations_societe')
                ->label('Autorisations de société')
                ->options(AutorisationsSociete::libelles())
                ->default(AutorisationsSociete::modeleDeLAdministrateur())
                ->disableOptionWhen(fn (Get $get, string $value): bool => $get('portee') !== 'tous' && in_array($value, AutorisationsSociete::exigentTousLesMagasins(), true)),
            CheckboxList::make('autorisations_magasin')
                ->label('Autorisations dans les magasins de sa portée')
                ->options(PermissionRegistry::libelles())
                ->default(PermissionRegistry::modeleDeLAdministrateur())
                ->columns(2)
                ->bulkToggleable(),
        ];
    }

    public static function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    public static function magasin(int $id): Magasin
    {
        return static::societe()->magasins()->whereKey($id)->firstOrFail();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembres::route('/'),
            'view' => Pages\FicheDuMembre::route('/{record}'),
        ];
    }
}
