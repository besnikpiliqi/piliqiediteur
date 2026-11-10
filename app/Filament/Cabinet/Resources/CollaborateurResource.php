<?php

namespace App\Filament\Cabinet\Resources;

use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\Cabinet\Resources\CollaborateurResource\Pages;
use App\Filament\Cabinet\RessourceDuPanneauCabinet;
use App\Filament\Concerns\GereLesComptes;
use App\Models\User;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les collaborateurs d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet, pour son propriétaire seul (voir MembrePolicy et
 * PorteeDesDroits) : leur identifiant de connexion, leur statut, leur
 * mot de passe encore égal à l'identifiant, leur première connexion, les
 * clients qui leur sont confiés ; et ses gestes : créer un collaborateur,
 * leur confier des clients, suspendre, réactiver, retirer,
 * réintégrer, redonner l'accès en sa présence, transmettre la propriété
 * du cabinet. Un collaborateur n'y voit rien. Personne ne change ici le
 * nom ni l'adresse d'un autre compte, et un compte ne se supprime jamais.
 */
class CollaborateurResource extends RessourceDuPanneauCabinet
{
    use GereLesComptes;

    protected static ?string $model = User::class;

    protected static ?string $tenantOwnershipRelationshipName = 'societe';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Collaborateurs';

    protected static ?string $modelLabel = 'collaborateur';

    protected static ?string $pluralModelLabel = 'collaborateurs';

    protected static ?string $slug = 'collaborateurs';

    /**
     * Les collaborateurs que la personne connectée voit : tous ceux du
     * cabinet pour son propriétaire (voir PorteeDesDroits::comptesVisibles()),
     * aucun pour un collaborateur.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        $auteur = auth()->user();

        return $auteur instanceof User && $auteur->estProprietaire()
            ? app(PorteeDesDroits::class)->comptesVisibles($auteur)->where('users.role_societe', RoleSociete::Collaborateur->value)
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
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge(),
                Tables\Columns\TextColumn::make('clients_confies')
                    ->label('Clients confiés')
                    ->state(fn (User $record): int => ClientConfie::query()->where('user_id', $record->getKey())->count()),
                Tables\Columns\TextColumn::make('signaux')
                    ->label('À surveiller')
                    ->badge()
                    ->color('warning')
                    ->state(fn (User $record): array => array_values(array_filter([
                        $record->mot_de_passe_est_l_identifiant ? 'Mot de passe : l\'identifiant' : null,
                        $record->premiere_connexion_le === null ? 'Jamais connecté' : null,
                    ])))
                    ->placeholder('—'),
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
                TextEntry::make('email')->label('Adresse e-mail de contact')->placeholder('Non renseignée'),
                TextEntry::make('statut')->label('Statut')->badge(),
                TextEntry::make('mot_de_passe')
                    ->label('Mot de passe')
                    ->state(fn (User $record): string => $record->mot_de_passe_est_l_identifiant ? 'Encore l\'identifiant : la personne ne peut pas se connecter avant de choisir le sien' : 'Choisi par la personne'),
                TextEntry::make('clients_confies')
                    ->label('Clients confiés')
                    ->state(fn (User $record): array => self::clientsConfies($record))
                    ->listWithLineBreaks()
                    ->placeholder('Aucun'),
                TextEntry::make('derniere_connexion_le')
                    ->label('Dernière connexion')
                    ->formatStateUsing(fn (User $record): string => $record->calendrier()->afficherHorsDuMagasin(ValeurRequise::de($record->derniere_connexion_le, 'la date de la dernière connexion'), 'd/m/Y à H:i'))
                    ->placeholder('Jamais connecté'),
            ])->columns(2),
        ]);
    }

    /**
     * Les clients confiés à ce collaborateur, par dénomination : ceux des
     * liens actifs de son cabinet (voir AccesDesCabinets::liensDuCompte()).
     *
     * @return list<string>
     */
    private static function clientsConfies(User $collaborateur): array
    {
        $clients = app(AccesDesCabinets::class)->liensDuCompte($collaborateur)->pluck('liens_cabinet.societe_id')->all();

        return array_values(Societe::query()->whereKey($clients)->orderBy('raison_sociale')->get(['id', 'siren', 'raison_sociale'])
            ->map(fn (Societe $client): string => ($client->raison_sociale ?? 'Sans dénomination').' · SIREN '.$client->siren)
            ->all());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollaborateurs::route('/'),
            'view' => Pages\FicheDuCollaborateur::route('/{record}'),
        ];
    }
}
