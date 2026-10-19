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
use App\Filament\Concerns\CocheLesAutorisationsDeMagasin;
use App\Filament\Concerns\GereLesComptes;
use App\Filament\Societe\Resources\MembreResource\Pages;
use App\Filament\Societe\RessourceDuPanneauSociete;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;
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
 *   avec leur identifiant de connexion, leur magasin et leurs
 *   autorisations, et tous les gestes : créer un membre (pour un seul
 *   magasin), un administrateur, un expert-comptable ; changer le rôle de
 *   société d'un compte ; modifier un administrateur ; suspendre,
 *   réactiver, retirer, réintégrer ; redonner l'accès ; annuler la
 *   nomination d'un responsable par un administrateur ; demander,
 *   renvoyer ou annuler la transmission de la propriété à un
 *   administrateur ;
 * - pour un administrateur autorisé à nommer les responsables, les
 *   responsables des magasins réels de sa portée : en nommer (un nouveau
 *   compte, ou un vendeur ou un observateur du magasin), annuler sa propre
 *   nomination, retirer de la société, redonner l'accès et afficher
 *   l'identifiant ;
 * - pour un administrateur autorisé à redonner l'accès, les vendeurs et
 *   les observateurs des magasins réels de sa portée, avec « Redonner
 *   l'accès » et « Afficher l'identifiant » seulement ;
 * - fermée à tout autre compte.
 * Le propriétaire voit les identifiants, et, dans la liste, les comptes
 * dont le mot de passe est encore l'identifiant et ceux qui ne se sont
 * jamais connectés ; un administrateur ne voit un identifiant que par
 * « Afficher l'identifiant », geste tracé. Les magasins proposés se lisent
 * par la portée de la personne connectée, dans sa société. Personne ne
 * change ici le nom ni l'adresse d'un autre compte, et un compte ne se
 * supprime jamais.
 */
class MembreResource extends RessourceDuPanneauSociete
{
    use CocheLesAutorisationsDeMagasin;
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
                Tables\Columns\TextColumn::make('identifiant')
                    ->label('Identifiant')
                    ->state(fn (User $record): ?string => static::peut('voirLIdentifiant', $record) ? $record->identifiantDeConnexion() : null)
                    ->placeholder('—')
                    ->visible(fn (): bool => static::proprietaireConnecte()),
                Tables\Columns\TextColumn::make('role_societe')->label('Rôle')->badge(),
                Tables\Columns\TextColumn::make('magasins')->label('Magasin')->state(fn (User $record): string => static::resumeDesMagasins($record))->wrap(),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('signaux')
                    ->label('À surveiller')
                    ->badge()
                    ->color('warning')
                    ->state(fn (User $record): array => static::signaux($record))
                    ->placeholder('—')
                    ->visible(fn (): bool => static::proprietaireConnecte()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_societe')->label('Rôle')->options(RoleSociete::class),
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->options(StatutCompte::class),
                Tables\Filters\Filter::make('mot_de_passe_identifiant')
                    ->label('Mot de passe encore l\'identifiant')
                    ->query(fn (Builder $query): Builder => $query->where('users.mot_de_passe_est_l_identifiant', true))
                    ->visible(fn (): bool => static::proprietaireConnecte()),
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
                TextEntry::make('identifiant')
                    ->label('Identifiant de connexion')
                    ->state(fn (User $record): ?string => $record->identifiantDeConnexion())
                    ->visible(fn (User $record): bool => static::peut('voirLIdentifiant', $record)),
                TextEntry::make('email')
                    ->label(fn (User $record): string => $record->estProprietaire() ? 'Adresse e-mail de connexion' : 'Adresse e-mail de contact')
                    ->placeholder('Non renseignée'),
                TextEntry::make('role_societe')->label('Rôle dans la société')->badge(),
                TextEntry::make('statut')->label('Statut')->badge(),
                TextEntry::make('mot_de_passe')
                    ->label('Mot de passe')
                    ->state(fn (User $record): string => $record->mot_de_passe_est_l_identifiant ? 'Encore l\'identifiant' : 'Choisi par la personne')
                    ->visible(fn (User $record): bool => static::peut('voirLIdentifiant', $record)),
                TextEntry::make('derniere_connexion_le')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y à H:i')
                    ->placeholder('Jamais connecté'),
                TextEntry::make('acces_redonne')
                    ->label('Accès redonné')
                    ->state(fn (User $record): ?string => static::accesRedonne($record))
                    ->placeholder('—'),
            ])->columns(2),
            Section::make('Magasin')->schema([
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
            ])->visible(fn (User $record): bool => $record->estAdministrateur() && static::proprietaireConnecte()),
        ]);
    }

    /**
     * Les badges de la liste, pour le propriétaire : un mot de passe encore
     * égal à l'identifiant, un compte jamais connecté.
     *
     * @return list<string>
     */
    public static function signaux(User $compte): array
    {
        return array_values(array_filter([
            $compte->mot_de_passe_est_l_identifiant ? 'Mot de passe : l\'identifiant' : null,
            $compte->premiere_connexion_le === null ? 'Jamais connecté' : null,
        ]));
    }

    /**
     * Le dernier accès redonné à ce compte : la date, l'auteur et le mode.
     */
    public static function accesRedonne(User $compte): ?string
    {
        if ($compte->acces_redonne_le === null) {
            return null;
        }

        $auteur = User::query()->where('societe_id', $compte->societe_id)->find($compte->acces_redonne_par)?->name ?? 'un compte de la société';

        return 'Le '.$compte->acces_redonne_le->setTimezone(config('app.timezone'))->format('d/m/Y à H:i')." par {$auteur}, ".($compte->acces_redonne_en_presence ? 'en sa présence' : 'en son absence');
    }

    /**
     * Son magasin, comme le voit la personne connectée : tous pour le
     * propriétaire ; pour un administrateur autorisé, ceux de sa portée.
     * Un membre retiré garde son magasin, sans droit.
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

        $resume = collect(static::affectationsVisibles($compte))->map(fn (array $ligne): string => "{$ligne['magasin']} ({$ligne['role']})")->implode(', ');

        if ($resume === '' && $compte->estMembre()) {
            $magasin = $compte->magasinDeSonIdentifiant();

            return $magasin !== null && array_key_exists((int) $magasin->getKey(), static::magasinsDeLaPortee()) ? "{$magasin->nom} (sans droit)" : '—';
        }

        return $resume ?: '—';
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
     * Les magasins de sa portée où un compte peut se créer ou se nommer :
     * tous, sauf le magasin de démonstration d'une société validée ou
     * refusée (voir Magasin::estUneDemonstrationTerminee()).
     *
     * @return array<int, string>
     */
    public static function magasinsOuCreerUnCompte(): array
    {
        return collect(static::magasinsDeLaPortee())
            ->reject(fn (string $nom, int $id): bool => static::magasin($id)->estUneDemonstrationTerminee())
            ->all();
    }

    /**
     * Le rôle d'un membre dans son magasin et ses cases, précochées par son
     * rôle, celles de la vente à part (voir CocheLesAutorisationsDeMagasin) ;
     * aucune pour un responsable, qui a toutes les autorisations du
     * magasin.
     *
     * @return list<Component>
     */
    public static function champsDuRoleDeMembre(): array
    {
        return [
            Select::make('role')
                ->label('Rôle')
                ->options(RoleMagasin::class)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $modele = RoleMagasin::tryFrom((string) ($state instanceof RoleMagasin ? $state->value : $state))?->permissionsParDefaut() ?? [];

                    foreach (static::autorisationsAAfficher($modele, 'autorisations') as $champ => $cases) {
                        $set($champ, $cases);
                    }
                }),
            ...static::champsDesAutorisationsDeMagasin(
                'autorisations',
                'Autorisations dans ce magasin',
                null,
                'Vente',
                'Créer et émettre un ticket ou une facture, enregistrer un encaissement, vendre sans stock : ce compte aura sa caisse personnelle.',
                fn (Get $get): bool => in_array($get('role'), [RoleMagasin::Responsable, RoleMagasin::Responsable->value], true),
            ),
        ];
    }

    /**
     * Le rôle saisi et ses cases, comme les reçoivent les actions de
     * l'équipe : aucune case pour un responsable.
     *
     * @param  array<string, mixed>  $data
     * @return array{role: string, autorisations: list<string>}
     */
    public static function roleSaisi(array $data): array
    {
        $role = $data['role'] instanceof RoleMagasin ? $data['role']->value : (string) $data['role'];

        return ['role' => $role, 'autorisations' => $role === RoleMagasin::Responsable->value ? [] : static::autorisationsSaisies($data, 'autorisations')];
    }

    /**
     * Les champs de la portée et des autorisations d'un administrateur,
     * avec à part ses cases de la vente ; l'action reçoit la réunion de ses
     * cases de magasin (voir autorisationsSaisies()). La fenêtre d'un
     * nouvel administrateur les remplit du modèle (voir
     * modeleDeLAdministrateur()).
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
                ->disableOptionWhen(fn (Get $get, string $value): bool => $get('portee') !== 'tous' && in_array($value, AutorisationsSociete::exigentTousLesMagasins(), true)),
            ...static::champsDesAutorisationsDeMagasin(
                'autorisations_magasin',
                'Autorisations dans les magasins de sa portée',
                null,
                'Vente (seulement si cet administrateur vend)',
                'Cet administrateur pourra vendre et aura sa caisse personnelle ; il n\'encaisse que dans un magasin à la fois.',
            ),
        ];
    }

    /**
     * Ce que la fenêtre d'un nouvel administrateur (création, changement de
     * rôle, réintégration) précoche : aucune portée, les autorisations de
     * société et les cases de magasin de son modèle, aucune de la vente
     * (voir AutorisationsSociete::modeleDeLAdministrateur() et
     * PermissionRegistry::modeleDeLAdministrateur()).
     *
     * @return array<string, list<string>>
     */
    public static function modeleDeLAdministrateur(): array
    {
        return [
            'autorisations_societe' => AutorisationsSociete::modeleDeLAdministrateur(),
            ...static::autorisationsAAfficher(PermissionRegistry::modeleDeLAdministrateur(), 'autorisations_magasin'),
        ];
    }

    public static function proprietaireConnecte(): bool
    {
        return auth()->user()?->estProprietaire() ?? false;
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
