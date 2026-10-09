<?php

namespace App\Filament\App\Resources;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * La caisse sécurisée du magasin (art. 286 I-3° bis du CGI), en lecture
 * seule : son registre fiscal signé, c'est-à-dire ses ventes, ses
 * encaissements et son journal des événements techniques (JET), tels
 * qu'ils ont été signés. Aucune création, modification ni suppression
 * (voir EnregistrementFiscalPolicy) : une ligne ne s'écrit que par
 * RegistreFiscal.
 *
 * Les horodatages, enregistrés et signés en UTC, s'affichent à l'heure de
 * Paris. La fiche montre le contenu signé mis en forme, puis son texte
 * exact, à copier pour vérifier la signature hors de l'application ;
 * l'export CSV du journal des événements (voir ListEnregistrementsFiscaux)
 * reprend aussi ce texte pour chaque événement technique.
 */
class EnregistrementFiscalResource extends Resource
{
    protected static ?string $model = EnregistrementFiscal::class;

    protected static ?string $slug = 'caisse-securisee';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Caisse sécurisée';

    protected static ?string $modelLabel = 'enregistrement fiscal';

    protected static ?string $pluralModelLabel = 'caisse sécurisée';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Enregistrement')->schema([
                TextEntry::make('chaine')->label('Chaîne')->badge(),
                TextEntry::make('sequence')->label('Séquence'),
                TextEntry::make('type')->label('Type ou code')
                    ->formatStateUsing(fn (EnregistrementFiscal $record): string => $record->libelleDuType()),
                TextEntry::make('horodatage')->label('Horodatage (heure de Paris)')
                    ->dateTime('d/m/Y à H:i:s', config('app.timezone')),
                TextEntry::make('libelle_operateur')->label('Opérateur')
                    ->state(fn (EnregistrementFiscal $record): string => $record->libelleDeLOperateur()),
                TextEntry::make('libelle_document')->label('Document')
                    ->state(fn (EnregistrementFiscal $record): ?string => $record->libelleDuDocument())
                    ->placeholder('Aucun'),
            ])->columns(3),

            InfolistSection::make('Événement technique')
                ->visible(fn (EnregistrementFiscal $record): bool => $record->chaine === ChaineFiscale::Evenement)
                ->schema([
                    TextEntry::make('adresse_ip')->label('Adresse IP')
                        ->state(fn (EnregistrementFiscal $record): ?string => $record->contenuSigne()['donnees']['ip'] ?? null)
                        ->placeholder('Aucune (traitement automatique)'),
                    TextEntry::make('navigateur')->label('Navigateur')
                        ->state(fn (EnregistrementFiscal $record): ?string => $record->contenuSigne()['donnees']['agent_utilisateur'] ?? null)
                        ->placeholder('Aucun (traitement automatique)'),
                ])->columns(2),

            InfolistSection::make('Signature')->schema([
                TextEntry::make('algorithme')->label('Algorithme'),
                TextEntry::make('version_module')->label('Version du module fiscal'),
                TextEntry::make('cle_id')->label('Clé de signature (empreinte SHA-256)')
                    ->fontFamily(FontFamily::Mono)->copyable()->columnSpanFull(),
                TextEntry::make('signature_precedente')->label('Signature précédente')
                    ->fontFamily(FontFamily::Mono)->copyable()->columnSpanFull()
                    ->placeholder('Aucune : premier enregistrement de la chaîne'),
                TextEntry::make('signature')->label('Signature')
                    ->fontFamily(FontFamily::Mono)->copyable()->columnSpanFull(),
            ])->columns(2),

            InfolistSection::make('Contenu signé')
                ->description('Mis en forme pour la lecture, puis tel qu\'il est signé : la signature porte sur ce texte exact, à copier sans le modifier pour la vérifier hors de l\'application.')
                ->schema([
                    ViewEntry::make('contenu_mis_en_forme')->hiddenLabel()
                        ->view('filament.app.infolists.contenu-signe')
                        ->state(fn (EnregistrementFiscal $record): string => json_encode(
                            $record->contenuSigne(),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        )),
                    TextEntry::make('contenu')->label('Texte exact signé')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->copyMessage('Texte signé copié')
                        ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('chaine')->label('Chaîne')->badge(),
                Tables\Columns\TextColumn::make('sequence')->label('Séquence'),
                Tables\Columns\TextColumn::make('type')->label('Type ou code')
                    ->formatStateUsing(fn (EnregistrementFiscal $record): string => $record->libelleDuType())
                    ->wrap(),
                Tables\Columns\TextColumn::make('horodatage')->label('Horodatage (heure de Paris)')
                    ->dateTime('d/m/Y H:i:s', config('app.timezone'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('libelle_operateur')->label('Opérateur')
                    ->state(fn (EnregistrementFiscal $record): string => $record->libelleDeLOperateur()),
                Tables\Columns\TextColumn::make('libelle_document')->label('Document')
                    ->state(fn (EnregistrementFiscal $record): ?string => $record->libelleDuDocument())
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('signature')->label('Signature')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 16, '…'))
                    ->fontFamily(FontFamily::Mono),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('chaine')->label('Chaîne')->options(ChaineFiscale::class),
                Tables\Filters\Filter::make('periode')
                    ->form([
                        DatePicker::make('du')->label('Du'),
                        DatePicker::make('au')->label('Au'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->horodatesDuJourAuJour($data['du'] ?? null, $data['au'] ?? null))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['du'] ?? null) ? 'Du '.CarbonImmutable::parse($data['du'])->format('d/m/Y') : null,
                        filled($data['au'] ?? null) ? 'Au '.CarbonImmutable::parse($data['au'])->format('d/m/Y') : null,
                    ]))),
                Tables\Filters\SelectFilter::make('code')->label('Code de l\'événement')
                    ->options(collect(CodeEvenement::cases())->mapWithKeys(
                        fn (CodeEvenement $code): array => [$code->value => $code->libelleAvecCode()],
                    )->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('chaine', ChaineFiscale::Evenement->value)->where('type', (string) $data['value'])
                        : $query),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnregistrementsFiscaux::route('/'),
            'view' => Pages\ViewEnregistrementFiscal::route('/{record}'),
        ];
    }
}
