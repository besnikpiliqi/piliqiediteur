<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\ReinitialiserMotDePasseMembreAction;
use App\Domain\Tenancy\Actions\RetirerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Rules\EmailLibrePourUnNouveauMembre;
use App\Filament\App\Resources\MembreEquipeResource\Pages;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * Gestion de l'équipe du magasin (vendeurs, comptable...). Le magasin crée
 * directement les comptes et communique les identifiants lui-même -- pas
 * d'auto-inscription, pas de vérification d'email (voir
 * CreerMembreMagasinAction). Modifier, « Nouveau mot de passe » et
 * « Retirer » passent par MembreEquipePolicy (update, delete) : il faut
 * « Équipe : modifier », et ni le propriétaire ni sa propre ligne ne se
 * modifient ou ne se retirent ici -- un seul chemin mène au rôle de
 * propriétaire, la création du magasin.
 *
 * Le rôle choisi coche d'office les autorisations de son modèle ; seul le
 * propriétaire les coche ou les décoche, et change le rôle d'un membre
 * (voir champAutorisations() et EditMembreEquipe).
 */
class MembreEquipeResource extends Resource
{
    use GereLesExceptionsMetier;

    protected static ?string $model = MagasinUser::class;

    // Contrairement à la convention par défaut de Filament (devinée côté
    // Magasin, ex: Magasin::clients() pour ClientResource), ce nom
    // explicite pointe côté MODÈLE DE LA RESSOURCE vers son tenant :
    // MagasinUser::magasin() existe déjà (ajouté dès la modélisation du
    // pivot), pas besoin d'en recréer un autre.
    protected static ?string $tenantOwnershipRelationshipName = 'magasin';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Équipe';

    protected static ?string $modelLabel = 'membre';

    protected static ?string $pluralModelLabel = 'équipe';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nouveau membre')->schema([
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (): EmailLibrePourUnNouveauMembre => new EmailLibrePourUnNouveauMembre(Filament::getTenant())),
                TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->helperText('À communiquer vous-même à la personne concernée -- aucun email ne lui sera envoyé.'),
                static::champRole(),
                static::champAutorisations()->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /**
     * Le rôle du membre : un libellé, et le modèle d'autorisations que
     * l'écran coche de nouveau à chaque changement de rôle, tel que la
     * personne connectée le donne (voir
     * CreerMembreMagasinAction::autorisationsDuModele()).
     */
    public static function champRole(): Select
    {
        return Select::make('role')
            ->label('Rôle')
            ->options(collect(RoleMagasin::assignables())->mapWithKeys(
                fn (RoleMagasin $role) => [$role->value => $role->getLabel()]
            ))
            ->required()
            ->live()
            ->afterStateUpdated(function (Set $set, ?string $state): void {
                $role = RoleMagasin::tryFrom((string) $state);

                /** @var Magasin $magasin */
                $magasin = Filament::getTenant();

                $set('permissions', $role === null ? [] : app(CreerMembreMagasinAction::class)->autorisationsDuModele(auth()->user(), $magasin, $role));
            });
    }

    /**
     * Les autorisations du membre. Seul le propriétaire les coche ou les
     * décoche : un autre membre qui gère l'équipe les voit sans pouvoir
     * les changer, et ne les envoie pas. Un membre qu'il crée reçoit le
     * modèle de son rôle, sans les autorisations que lui-même n'a pas ; un
     * membre qu'il modifie garde celles que le propriétaire a cochées.
     */
    public static function champAutorisations(): CheckboxList
    {
        return CheckboxList::make('permissions')
            ->label('Autorisations')
            ->helperText(fn (string $operation): string => match (true) {
                static::proprietaireConnecte() => 'Cochées d\'après le rôle choisi : cochez ou décochez ce que ce membre peut voir et faire.',
                $operation === 'create' => 'Le modèle du rôle choisi, sans les autorisations que vous n\'avez pas vous-même. Seul le propriétaire les coche ou les décoche.',
                default => 'Seul le propriétaire coche ou décoche les autorisations d\'un membre et change son rôle.',
            })
            ->options(PermissionRegistry::libelles())
            ->columns(2)
            ->bulkToggleable()
            ->disabled(fn (): bool => ! static::proprietaireConnecte());
    }

    /**
     * La personne connectée est-elle le propriétaire du magasin ouvert ?
     */
    public static function proprietaireConnecte(): bool
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        return auth()->user()?->estProprietaireDe($magasin) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('utilisateur.name')->label('Nom')->searchable(),
                Tables\Columns\TextColumn::make('utilisateur.email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rôle')->badge(),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('joined_at')->label('Membre depuis')->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options(RoleMagasin::class),
                Tables\Filters\SelectFilter::make('statut')->options(StatutMembreMagasin::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reinitialiser_mot_de_passe')
                    ->label('Nouveau mot de passe')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->form([
                        TextInput::make('nouveau_mot_de_passe')
                            ->label('Nouveau mot de passe')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::default()),
                    ])
                    ->authorize('update')
                    ->action(fn (MagasinUser $record, array $data) => static::executerSansCasser(fn () => app(ReinitialiserMotDePasseMembreAction::class)
                        ->handle(auth()->user(), $record, $data['nouveau_mot_de_passe']))),
                Tables\Actions\Action::make('retirer')
                    ->label('Retirer')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('La personne perdra immédiatement son accès à l\'équipe, et son adresse e-mail ne pourra plus servir à un nouveau membre. Pour une absence, modifiez plutôt son statut.')
                    ->authorize('delete')
                    ->action(fn (MagasinUser $record) => static::executerSansCasser(fn () => app(RetirerMembreMagasinAction::class)->handle($record))),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembreEquipes::route('/'),
            'create' => Pages\CreateMembreEquipe::route('/create'),
            'edit' => Pages\EditMembreEquipe::route('/{record}/edit'),
        ];
    }
}
