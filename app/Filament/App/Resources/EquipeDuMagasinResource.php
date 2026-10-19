<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * L'équipe du magasin, dans son panneau : ses vendeurs, ses observateurs
 * et ses responsables (les affectations de membre du magasin actif), que
 * gèrent son propriétaire et ses responsables, selon les règles de
 * PorteeDesDroits (voir AffectationPolicy et MembrePolicy) :
 * - créer le compte d'un vendeur ou d'un observateur de ce magasin, avec
 *   les cases que son rôle précoche, ou réintégrer l'ancien compte retiré
 *   du même nom, et, pour le propriétaire, créer le compte d'un
 *   responsable (voir ListEquipeDuMagasin) ;
 * - changer le rôle et les cases d'un vendeur ou d'un observateur, nommer
 *   l'un d'eux responsable, le retirer du magasin, ce qui le retire de la
 *   société (un compte de membre ne vaut que pour son magasin) ;
 * - suspendre, réactiver, retirer de la société, redonner l'accès, pour
 *   qui en a le droit sur ce compte (voir GereLesComptes).
 * Le propriétaire voit l'identifiant de chaque compte ; un responsable,
 * celui des vendeurs et des observateurs de son magasin, avec les badges
 * « Mot de passe : l'identifiant » et « Jamais connecté » et le filtre des
 * mots de passe encore égaux à l'identifiant. Ni les administrateurs ni
 * les experts-comptables n'y figurent.
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
                Tables\Columns\TextColumn::make('identifiant')
                    ->label('Identifiant')
                    ->state(fn (Affectation $record): ?string => static::peut('voirLIdentifiant', $record->utilisateur) ? $record->utilisateur->identifiantDeConnexion() : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('role')->label('Rôle')->badge(),
                Tables\Columns\TextColumn::make('utilisateur.statut')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('signaux')
                    ->label('À surveiller')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Affectation $record): array => static::peut('voirLIdentifiant', $record->utilisateur) ? static::signaux($record->utilisateur) : [])
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->label('Rôle')->options(RoleMagasin::class),
                Tables\Filters\Filter::make('mot_de_passe_identifiant')
                    ->label('Mot de passe encore l\'identifiant')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('utilisateur', fn (Builder $comptes): Builder => $comptes->where('mot_de_passe_est_l_identifiant', true))
                        ->when(! static::proprietaireConnecte(), fn (Builder $affectations): Builder => $affectations->whereIn('affectations.role', [RoleMagasin::Vendeur->value, RoleMagasin::Observateur->value]))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Rôle et autorisations'),
                Tables\Actions\Action::make('nommer_responsable')
                    ->label('Nommer responsable')
                    ->icon('heroicon-o-star')
                    ->modalDescription(fn (Affectation $record): string => "{$record->utilisateur->name} aura tous les droits dans ce magasin, sans case.".($record->utilisateur->mot_de_passe_est_l_identifiant ? ' Son mot de passe est encore son identifiant : toute personne qui le connaît pourra agir avec ses nouveaux droits.' : ''))
                    ->fillForm(fn (): array => ['jeton' => (string) Str::uuid(), 'mode' => 'plus_tard'])
                    ->form(fn (Affectation $record): array => $record->utilisateur->mot_de_passe_est_l_identifiant ? [
                        Hidden::make('jeton'),
                        Radio::make('mode')
                            ->label('La personne est-elle là ?')
                            ->options(['plus_tard' => 'Non : elle choisira son mot de passe à sa prochaine connexion', 'present' => 'Oui : elle choisit son mot de passe maintenant'])
                            ->required()
                            ->live(),
                        ...static::champsDuMotDePasseChoisi(fn (Get $get): bool => $get('mode') === 'present'),
                    ] : [])
                    ->requiresConfirmation(fn (Affectation $record): bool => ! $record->utilisateur->mot_de_passe_est_l_identifiant)
                    ->visible(fn (Affectation $record): bool => ! $record->estDeResponsable() && app(PorteeDesDroits::class)->peutDonnerLeRole(static::auteur(), $record->utilisateur, $record->magasin, RoleMagasin::Responsable))
                    ->action(function (Affectation $record, array $data, Form $form): void {
                        $compte = $record->utilisateur;
                        $enPresence = ($data['mode'] ?? null) === 'present';

                        if ($enPresence) {
                            static::exigerLeMotDePasseChoisi((string) ($data['mot_de_passe'] ?? ''), $compte, $form);
                        }

                        static::executerLeGeste(function () use ($compte, $enPresence, $data): void {
                            app(ModifierMembreAction::class)->handle(static::auteur(), $compte, RoleMagasin::Responsable);

                            if ($enPresence) {
                                app(RedonnerLAccesAction::class)->handle(static::auteur(), $compte->refresh(), true, (string) $data['mot_de_passe'], (string) ($data['jeton'] ?? ''));
                            }
                        });
                    }),
                Tables\Actions\Action::make('retirer_du_magasin')
                    ->label('Retirer du magasin')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Affectation $record): string => "Un compte ne vaut que pour son magasin : {$record->utilisateur->name} sera retiré de la société. Son compte, son identifiant et son historique restent : il pourra être réintégré.")
                    ->authorize('delete')
                    ->action(fn (Affectation $record) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->retirer(static::auteur(), $record->utilisateur))),
                static::actionRedonnerLAcces(Tables\Actions\Action::make('redonner_l_acces'), fn (Affectation $record): User => $record->utilisateur),
                static::actionSuspendre(Tables\Actions\Action::make('suspendre'), fn (Affectation $record): User => $record->utilisateur),
                static::actionReactiver(Tables\Actions\Action::make('reactiver'), fn (Affectation $record): User => $record->utilisateur),
            ])
            ->bulkActions([]);
    }

    /**
     * Les badges de la liste : un mot de passe encore égal à l'identifiant,
     * un compte jamais connecté.
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipeDuMagasin::route('/'),
            'edit' => Pages\EditMembreDuMagasin::route('/{record}/edit'),
        ];
    }
}
