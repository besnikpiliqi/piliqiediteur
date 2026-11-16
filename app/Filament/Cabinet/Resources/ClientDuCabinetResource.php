<?php

namespace App\Filament\Cabinet\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
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
use Illuminate\Database\Query\Builder as RequeteSql;
use Illuminate\Support\Facades\DB;

/**
 * Les clients liés d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet : une lecture d'autres sociétés que CLAUDE.md permet, toujours
 * par les liens actifs que le cabinet ouvre au compte (voir
 * AccesDesCabinets::liensOuverts() : aucun tant que le cabinet n'est pas
 * vérifié, ses seuls clients confiés pour un collaborateur), jamais par
 * LienCabinet::query() ; un lien d'un autre cabinet, ou d'un client non
 * confié, donne 404. La liste montre de chaque client sa dénomination, son
 * SIREN, la ville de son siège, la date du lien et le nombre de ses
 * magasins, et, au propriétaire seul, les collaborateurs à qui il
 * est confié ; elle se cherche par dénomination, sans casse ni accents, ou
 * par SIREN, toujours parmi ces seuls liens ; le propriétaire y confie un
 * client à ses collaborateurs et met fin au lien. La fiche (voir FicheDuClient) montre son identité, ses réglages
 * fiscaux, les autorisations qu'il accorde au cabinet et ses magasins
 * réels, chacun avec le bouton qui l'ouvre dans le panneau des magasins.
 */
class ClientDuCabinetResource extends RessourceDuPanneauCabinet
{
    use GereLesExceptionsMetier;

    protected static ?string $model = LienCabinet::class;

    protected static bool $isScopedToTenant = false;

    protected static bool $isGloballySearchable = false;

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
            ? app(AccesDesCabinets::class)->liensOuverts($compte)
                ->with(['client' => fn ($client) => $client->select(['id', 'uuid', 'siren', 'raison_sociale', 'siege_ville'])])
                ->addSelect(['nombre_de_magasins' => self::magasins(), 'charges_accordees' => self::chargesAccordees()])
            : LienCabinet::query()->withoutGlobalScopes()->whereRaw('1 = 0');
    }

    /**
     * Le nombre de magasins que le client ouvre au cabinet par ce
     * lien, lu dans la requête de la liste, quel que soit le nombre de
     * clients : tous ses magasins, présents et futurs, ou ceux de sa
     * sélection (voir LienCabinet::magasinsChoisis()), chacun filtré par la
     * société du lien.
     */
    private static function magasins(): RequeteSql
    {
        return DB::table('magasins')
            ->selectRaw('count(*)')
            ->whereColumn('magasins.societe_id', 'liens_cabinet.societe_id')
            ->where(fn (RequeteSql $ouverts) => $ouverts
                ->where('liens_cabinet.tous_les_magasins', true)
                ->orWhereIn('magasins.id', fn (RequeteSql $choisis) => $choisis
                    ->from('magasins_cabinet')
                    ->whereColumn('magasins_cabinet.lien_id', 'liens_cabinet.id')
                    ->whereColumn('magasins_cabinet.societe_id', 'liens_cabinet.societe_id')
                    ->select('magasins_cabinet.magasin_id')));
    }

    /**
     * Le client accorde-t-il au cabinet, par ce lien, « Charges : voir,
     * ajouter, saisir et valider » (voir ChargesDuClient) ? Lu dans la
     * requête de la liste, filtré par la société du lien.
     */
    private static function chargesAccordees(): RequeteSql
    {
        return DB::table('autorisations_cabinet')
            ->selectRaw('COUNT(*) > 0')
            ->whereColumn('autorisations_cabinet.lien_id', 'liens_cabinet.id')
            ->whereColumn('autorisations_cabinet.societe_id', 'liens_cabinet.societe_id')
            ->where('autorisations_cabinet.autorisation', PermissionRegistry::CHARGES_VALIDER);
    }

    /**
     * Le client accorde-t-il les charges au cabinet par ce lien ? Lu dans
     * la requête de la liste ; un lien relu par Livewire (la fiche du
     * client, à chaque requête après son ouverture) ne porte plus cette
     * colonne : elle se lit alors pour ce seul lien, filtrée par sa société.
     */
    public static function accordeLesCharges(LienCabinet $lien): bool
    {
        if (array_key_exists('charges_accordees', $lien->getAttributes())) {
            return (bool) $lien->getAttribute('charges_accordees');
        }

        return DB::table('autorisations_cabinet')
            ->where('lien_id', $lien->getKey())
            ->where('societe_id', $lien->societe_id)
            ->where('autorisation', PermissionRegistry::CHARGES_VALIDER)
            ->exists();
    }

    /**
     * Les charges du client (voir ChargesDuClient), quand il les accorde au
     * cabinet.
     */
    public static function actionCharges(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('charges')
            ->label('Charges')
            ->icon('heroicon-o-receipt-percent')
            ->visible(fn (LienCabinet $record): bool => self::accordeLesCharges($record))
            ->url(fn (LienCabinet $record): string => static::getUrl('charges', ['record' => $record]));
    }

    /**
     * Le nombre de magasins que ce client, lu dans la liste, ouvre
     * au cabinet (voir magasins()).
     */
    public static function nombreDeMagasins(LienCabinet $lien): int
    {
        return ValeursLues::entier($lien->getAttribute('nombre_de_magasins'));
    }

    /**
     * La fiche d'un client se lit par l'uuid de son lien, la clé de son
     * adresse (voir LienCabinet::getRouteKeyName()), parmi les seuls liens
     * que le cabinet ouvre au compte : un identifiant numérique, ou l'uuid
     * d'un lien d'un autre cabinet ou d'un client non confié, donne 404.
     */
    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return static::getEloquentQuery()->parUuid((string) $key)->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.raison_sociale')->label('Client')->placeholder('Sans dénomination')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('client.siren')->label('SIREN')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('client.siege_ville')->label('Ville du siège')->placeholder('—'),
                Tables\Columns\TextColumn::make('actif_depuis')->label('Lié depuis')->dateTime('d/m/Y'),
                Tables\Columns\TextColumn::make('magasins')
                    ->label('Magasins')
                    ->state(fn (LienCabinet $record): int => self::nombreDeMagasins($record)),
                Tables\Columns\TextColumn::make('collaborateurs')
                    ->label('Collaborateurs confiés')
                    ->state(fn (LienCabinet $record): array => self::collaborateursConfies($record))
                    ->listWithLineBreaks()
                    ->placeholder('Aucun')
                    ->visible(fn (): bool => auth()->user()?->estProprietaire() ?? false),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ouvrir'),
                self::actionGraphiques(),
                self::actionCharges(),
                self::actionConfier(),
                self::actionMettreFin(),
            ])
            ->bulkActions([])
            ->defaultSort('actif_depuis', 'desc');
    }

    /**
     * Le tableau de bord du client : ses chiffres clés et ses graphiques
     * (voir GraphiquesDuClient), pour qui ouvre ce client, dès qu'il ouvre
     * au cabinet un magasin au moins (voir
     * PerimetreDesGraphiques::voitLesGraphiquesDuClient()) ; une société
     * invitée par le cabinet naît sans magasin.
     */
    public static function actionGraphiques(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('graphiques')
            ->label('Graphiques')
            ->icon('heroicon-o-chart-bar')
            ->visible(fn (LienCabinet $record): bool => self::nombreDeMagasins($record) > 0)
            ->url(fn (LienCabinet $record): string => static::getUrl('graphiques', ['record' => $record]));
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
            ->fillForm(fn (LienCabinet $record): array => ['collaborateurs' => ClientConfie::query()->where('lien_id', $record->getKey())->pluck('user_id')->map(fn (mixed $id): string => (string) ValeursLues::entier($id))->all()])
            ->form([
                CheckboxList::make('collaborateurs')
                    ->label('Collaborateurs qui suivent ce client')
                    ->options(fn (): array => User::query()
                        ->where('societe_id', CompteConnecte::exiger()->societe_id)
                        ->where('role_societe', RoleSociete::Collaborateur->value)
                        ->where('statut', '<>', StatutCompte::Retire->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->columns(1),
            ])
            ->action(function (array $data, LienCabinet $record): void {
                static::executerSansCasser(function () use ($data, $record): void {
                    $voulus = array_map(ValeurSimple::enEntier(...), ValeursLues::tableau($data['collaborateurs'] ?? []));
                    $collaborateurs = User::query()->where('societe_id', CompteConnecte::exiger()->societe_id)->where('role_societe', RoleSociete::Collaborateur->value)->where('statut', '<>', StatutCompte::Retire->value)->orderBy('id')->get();

                    foreach ($collaborateurs as $collaborateur) {
                        $siens = ValeursLues::entiers(ClientConfie::query()->where('user_id', $collaborateur->getKey())->pluck('lien_id'));
                        $apres = in_array(CleDuModele::de($collaborateur), $voulus, true)
                            ? array_values(array_unique([...$siens, CleDuModele::de($record)]))
                            : array_values(array_diff($siens, [CleDuModele::de($record)]));

                        if ($apres !== $siens) {
                            app(ConfierDesClientsAction::class)->handle(CompteConnecte::exiger(), $collaborateur, $apres);
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
            app(MettreFinAuLienAction::class)->handle(CompteConnecte::exiger(), $lien);
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
        return ValeursLues::textes(User::query()
            ->where('societe_id', $lien->cabinet_id)
            ->whereIn('id', ClientConfie::query()->where('lien_id', $lien->getKey())->select('user_id'))
            ->orderBy('name')
            ->pluck('name'));
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
            'graphiques' => Pages\GraphiquesDuClient::route('/{record}/graphiques'),
            'charges' => Pages\ChargesDuClient::route('/{record}/charges'),
        ];
    }
}
