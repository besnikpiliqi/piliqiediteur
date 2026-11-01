<?php

namespace App\Filament\Cabinet\Resources;

use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;
use App\Filament\Cabinet\RessourceDuPanneauCabinet;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Les clients liés d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet : une lecture d'autres sociétés que CLAUDE.md permet, toujours
 * par les liens actifs que le cabinet ouvre au compte (voir
 * AccesDesCabinets::liensOuverts() : aucun tant que le cabinet n'est pas
 * vérifié, ses seuls clients confiés pour un collaborateur), jamais par
 * LienCabinet::query() ; un lien d'un autre cabinet, ou d'un client non
 * confié, donne 404. La liste montre de chaque client sa dénomination, son
 * SIREN, la ville de son siège, la date du lien et le nombre de ses
 * magasins réels, et, au propriétaire seul, les collaborateurs à qui il
 * est confié ; il y confie un client à ses collaborateurs et met fin au
 * lien. La fiche (voir FicheDuClient) montre son identité, ses réglages
 * fiscaux, les autorisations qu'il accorde au cabinet et ses magasins
 * réels, chacun avec le bouton qui l'ouvre dans le panneau des magasins.
 */
class ClientDuCabinetResource extends RessourceDuPanneauCabinet
{
    use GereLesExceptionsMetier;

    protected static ?string $model = LienCabinet::class;

    protected static bool $isScopedToTenant = false;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    protected static ?string $slug = 'clients';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', LienCabinet::class) ?? false;
    }

    /**
     * Les liens actifs que le cabinet ouvre au compte connecté, avec leur
     * client réduit aux colonnes montrées.
     *
     * @return Builder<LienCabinet>
     */
    public static function getEloquentQuery(): Builder
    {
        $compte = auth()->user();

        return $compte instanceof User
            ? app(AccesDesCabinets::class)->liensOuverts($compte)->with(['client' => fn ($client) => $client->select(['id', 'uuid', 'siren', 'raison_sociale', 'siege_ville'])])
            : LienCabinet::query()->withoutGlobalScopes()->whereRaw('1 = 0');
    }

    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return static::getEloquentQuery()->parUuid((string) $key)->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.raison_sociale')->label('Client')->placeholder('Sans dénomination'),
                Tables\Columns\TextColumn::make('client.siren')->label('SIREN'),
                Tables\Columns\TextColumn::make('client.siege_ville')->label('Ville du siège')->placeholder('—'),
                Tables\Columns\TextColumn::make('actif_depuis')->label('Lié depuis')->dateTime('d/m/Y'),
                Tables\Columns\TextColumn::make('magasins')
                    ->label('Magasins réels')
                    ->state(fn (LienCabinet $record): int => Magasin::query()->where('societe_id', $record->societe_id)->where('est_demonstration', false)->count()),
                Tables\Columns\TextColumn::make('collaborateurs')
                    ->label('Collaborateurs confiés')
                    ->state(fn (LienCabinet $record): array => self::collaborateursConfies($record))
                    ->listWithLineBreaks()
                    ->placeholder('Aucun')
                    ->visible(fn (): bool => auth()->user()?->estProprietaire() ?? false),
            ])
            ->searchable(false)
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ouvrir'),
                self::actionConfier(),
                self::actionMettreFin(),
            ])
            ->bulkActions([])
            ->defaultSort('actif_depuis', 'desc');
    }

    /**
     * Confier ce client à des collaborateurs : le propriétaire du cabinet
     * coche ceux qui le suivront ; chacun garde ses autres clients (voir
     * ConfierDesClientsAction, appelée collaborateur par collaborateur).
     */
    public static function actionConfier(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('confier')
            ->label('Confier')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (LienCabinet $record): bool => auth()->user()?->can('confier', $record) ?? false)
            ->fillForm(fn (LienCabinet $record): array => ['collaborateurs' => ClientConfie::query()->where('lien_id', $record->getKey())->pluck('user_id')->map(fn ($id): string => (string) $id)->all()])
            ->form([
                CheckboxList::make('collaborateurs')
                    ->label('Collaborateurs qui suivent ce client')
                    ->options(fn (): array => User::query()
                        ->where('societe_id', auth()->user()->societe_id)
                        ->where('role_societe', RoleSociete::Collaborateur->value)
                        ->where('statut', '<>', StatutCompte::Retire->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->columns(1),
            ])
            ->action(function (array $data, LienCabinet $record): void {
                static::executerSansCasser(function () use ($data, $record): void {
                    $voulus = array_map(intval(...), $data['collaborateurs'] ?? []);
                    $collaborateurs = User::query()->where('societe_id', auth()->user()->societe_id)->where('role_societe', RoleSociete::Collaborateur->value)->where('statut', '<>', StatutCompte::Retire->value)->orderBy('id')->get();

                    foreach ($collaborateurs as $collaborateur) {
                        $siens = ClientConfie::query()->where('user_id', $collaborateur->getKey())->pluck('lien_id')->map(fn ($id): int => (int) $id)->all();
                        $apres = in_array((int) $collaborateur->getKey(), $voulus, true)
                            ? array_values(array_unique([...$siens, (int) $record->getKey()]))
                            : array_values(array_diff($siens, [(int) $record->getKey()]));

                        if ($apres !== $siens) {
                            app(ConfierDesClientsAction::class)->handle(auth()->user(), $collaborateur, $apres);
                        }
                    }

                    Notification::make()->title('Les collaborateurs de ce client sont enregistrés.')->success()->send();
                });
            });
    }

    public const CONFIRMATION_DE_LA_FIN = 'L\'accès à ce client s\'arrête aussitôt pour tout le cabinet, vous compris ; ses collaborateurs en sont prévenus, et le client aussi.';

    /**
     * Mettre fin au lien, depuis la liste : le propriétaire du cabinet,
     * après une confirmation qui dit que l'accès s'arrête aussitôt pour
     * tout le cabinet (voir mettreFin()).
     */
    public static function actionMettreFin(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('mettreFin')
            ->label('Mettre fin au lien')
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(self::CONFIRMATION_DE_LA_FIN)
            ->visible(fn (LienCabinet $record): bool => auth()->user()?->can('mettreFin', $record) ?? false)
            ->action(fn (LienCabinet $record) => self::mettreFin($record));
    }

    /**
     * Met fin au lien par le compte connecté (voir MettreFinAuLienAction),
     * qui la contrôle de nouveau ; un refus s'affiche sans casser la page.
     */
    public static function mettreFin(LienCabinet $lien): void
    {
        static::executerSansCasser(function () use ($lien): void {
            app(MettreFinAuLienAction::class)->handle(auth()->user(), $lien);
            Notification::make()->title('Le lien avec ce client a pris fin.')->success()->send();
        });
    }

    /**
     * Les collaborateurs à qui ce client est confié, par nom.
     *
     * @return list<string>
     */
    public static function collaborateursConfies(LienCabinet $lien): array
    {
        return User::query()
            ->where('societe_id', $lien->cabinet_id)
            ->whereIn('id', ClientConfie::query()->where('lien_id', $lien->getKey())->select('user_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Le client du lien, réduit à ce que sa fiche montre.
     */
    public static function client(LienCabinet $lien): Societe
    {
        return Societe::query()->whereKey($lien->societe_id)->firstOrFail([...Societe::CHAMPS_DE_L_IDENTITE, 'id', 'uuid', 'siren', 'type_activite', 'regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'statut', 'validee_le', 'close_le']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientsDuCabinet::route('/'),
            'view' => Pages\FicheDuClient::route('/{record}'),
        ];
    }
}
