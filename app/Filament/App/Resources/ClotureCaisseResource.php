<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\ClotureCaisseResource\Pages;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lecture seule : une clôture se crée uniquement via l'action « Clôturer
 * la journée » (voir ListClotureCaisses) ou la commande planifiée
 * app:cloturer-caisses-automatiquement, jamais via un formulaire
 * create/edit classique. Document fiscal au même titre qu'une facture :
 * jamais modifiable ni supprimable une fois créée (voir
 * ClotureCaissePolicy).
 */
class ClotureCaisseResource extends Resource
{
    protected static ?string $model = ClotureCaisse::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'clôture de caisse';

    protected static ?string $pluralModelLabel = 'clôtures de caisse';

    /**
     * Confidentialité par vendeur (voir ClotureCaissePolicy) : sans
     * cloture_caisse.view_toutes, la liste elle-même ne doit montrer que
     * les clôtures déclenchées par l'utilisateur courant.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    /**
     * « 08/10/2026 (oubliée) et 09/10/2026 » : des journées de caisse
     * (AAAA-MM-JJ), une journée d'avant aujourd'hui encore à clôturer
     * étant une journée oubliée.
     *
     * @param  Collection<int, string>  $journees
     */
    public static function libelleDesJournees(Collection $journees): string
    {
        $aujourdhui = today()->toDateString();

        return $journees
            ->map(fn (string $journee): string => CarbonImmutable::parse($journee)->format('d/m/Y').($journee < $aujourdhui ? ' (oubliée)' : ''))
            ->join(', ', ' et ');
    }

    /**
     * Ce que la clôture de ces journées regroupera, avec les mêmes
     * critères que CloturerCaisseAction : les ventes au comptoir de chaque
     * journée et les avoirs du même jour, pas encore clôturés, et leur
     * total net TTC.
     *
     * @param  Collection<int, string>  $journees
     * @return array{ventes: int, avoirs: int, total_ttc: float}
     */
    public static function enAttenteDeCloture(Collection $journees): array
    {
        $ventes = $journees->flatMap(fn (string $journee) => Facture::query()->enAttenteDeCloture($journee)->get());
        $avoirs = $journees->flatMap(fn (string $journee) => Avoir::query()->enAttenteDeCloture($journee)->get());
        $calculateur = app(CalculateurLigne::class);

        return [
            'ventes' => $ventes->count(),
            'avoirs' => $avoirs->count(),
            'total_ttc' => $calculateur->deduire($calculateur->cumuler($ventes), $calculateur->cumuler($avoirs))['total_ttc'],
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Clôture')->schema([
                TextEntry::make('numero')->label('Numéro'),
                TextEntry::make('date_cloture')->label('Journée clôturée')->date('d/m/Y'),
                TextEntry::make('cloturee_a')->label('Clôturée le')->dateTime('d/m/Y à H:i'),
                TextEntry::make('creePar.name')->label('Déclenchée par')->placeholder('Automatique (planifiée à minuit)'),
            ])->columns(2),

            // Ventes moins avoirs du jour (voir CloturerCaisseAction).
            InfolistSection::make('Totaux')->schema([
                TextEntry::make('nombre_ventes')->label('Nombre de ventes'),
                TextEntry::make('nombre_avoirs')->label('Avoirs déduits'),
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
                FactureResource::ventilationParNatureEntry(),
            ])->columns(4),

            InfolistSection::make('E-reporting')->schema([
                // L'e-reporting n'est pas transmis clôture par clôture : il
                // est agrégé par période (décade/mois/bimestre selon le
                // régime de TVA, voir CalculateurPeriodeEReporting) puis
                // transmis en une seule soumission (voir
                // SoumissionEReporting et la page "Soumissions e-reporting").
                TextEntry::make('soumissionEReporting.numero')
                    ->label('Incluse dans la soumission')
                    ->placeholder('Pas encore incluse (période en cours)'),
            ]),

            InfolistSection::make('Ventes agrégées')->schema([
                RepeatableEntry::make('factures')
                    ->label('')
                    ->schema([
                        TextEntry::make('numero')->label('Numéro'),
                        TextEntry::make('client_nom')->label('Client'),
                        TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                        TextEntry::make('total_tva')->label('TVA')->money('EUR'),
                        TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                    ])
                    ->columns(5),
            ]),

            InfolistSection::make('Avoirs déduits')
                ->visible(fn (ClotureCaisse $record) => $record->avoirs()->exists())
                ->schema([
                    RepeatableEntry::make('avoirs')
                        ->label('')
                        ->schema([
                            TextEntry::make('numero')->label('Numéro'),
                            TextEntry::make('client_nom')->label('Client'),
                            TextEntry::make('total_ht')->label('Total HT')->state(fn (Avoir $record) => -(float) $record->total_ht)->money('EUR'),
                            TextEntry::make('total_tva')->label('TVA')->state(fn (Avoir $record) => -(float) $record->total_tva)->money('EUR'),
                            TextEntry::make('total_ttc')->label('Total TTC')->state(fn (Avoir $record) => -(float) $record->total_ttc)->money('EUR'),
                        ])
                        ->columns(5),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_cloture', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->searchable(),
                Tables\Columns\TextColumn::make('date_cloture')->label('Journée')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('nombre_ventes')->label('Ventes'),
                Tables\Columns\TextColumn::make('nombre_avoirs')->label('Avoirs'),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR'),
                Tables\Columns\TextColumn::make('cree_par')
                    ->label('Déclenchée par')
                    ->state(fn (ClotureCaisse $record) => $record->creePar?->name ?? 'Automatique')
                    ->badge()
                    ->color(fn (ClotureCaisse $record) => $record->cree_par === null ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('cloturee_a')->label('Clôturée le')->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClotureCaisses::route('/'),
            'view' => Pages\ViewClotureCaisse::route('/{record}'),
        ];
    }
}
