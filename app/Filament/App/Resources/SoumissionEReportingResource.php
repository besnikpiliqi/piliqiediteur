<?php

namespace App\Filament\App\Resources;

use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Filament\App\Resources\SoumissionEReportingResource\Pages;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Lecture seule : une soumission se génère uniquement via la commande
 * planifiée app:soumettre-ereporting-periodique (voir
 * GenererSoumissionEReportingAction), jamais via un formulaire
 * create/edit. Contrairement à ClotureCaisse (quotidienne), une soumission
 * couvre toute une période (décade/mois/bimestre selon le régime de TVA
 * du magasin, voir ParametresFiscaux) : c'est elle, et non chaque clôture
 * individuelle, qui est effectivement transmise au PDP.
 */
class SoumissionEReportingResource extends Resource
{
    protected static ?string $model = SoumissionEReporting::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'soumission e-reporting';

    protected static ?string $pluralModelLabel = 'soumissions e-reporting';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Soumission')->schema([
                TextEntry::make('numero')->label('Numéro'),
                TextEntry::make('periode_debut')->label('Période du')->date('d/m/Y'),
                TextEntry::make('periode_fin')->label('au')->date('d/m/Y'),
                TextEntry::make('date_limite_transmission')->label('Date limite légale')->date('d/m/Y'),
                TextEntry::make('generee_le')->label('Générée le')->dateTime('d/m/Y à H:i'),
            ])->columns(3),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('nombre_clotures')->label('Nombre de clôtures agrégées'),
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
            ])->columns(4),

            InfolistSection::make('Transmission électronique')->schema([
                TextEntry::make('transmission_pdp_statut')
                    ->label('Statut de transmission au PDP')
                    ->state(fn (SoumissionEReporting $record) => $record->transmissionsPdp->sortByDesc('soumise_le')->first()?->statut)
                    ->badge()
                    ->placeholder('Pas de mandat PDP actif : non transmise'),
            ]),

            InfolistSection::make('Clôtures agrégées')->schema([
                RepeatableEntry::make('clotures')
                    ->label('')
                    ->schema([
                        TextEntry::make('numero')->label('Numéro'),
                        TextEntry::make('date_cloture')->label('Journée')->date('d/m/Y'),
                        TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                    ])
                    ->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('periode_debut', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->searchable(),
                Tables\Columns\TextColumn::make('periode_debut')->label('Période du')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('periode_fin')->label('au')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('date_limite_transmission')->label('Limite légale')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('nombre_clotures')->label('Clôtures'),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSoumissionsEReporting::route('/'),
            'view' => Pages\ViewSoumissionEReporting::route('/{record}'),
        ];
    }
}
