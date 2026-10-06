<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Filament\App\Resources\AvoirResource\Pages;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Création possible de deux façons : depuis l'action rapide "Avoir" de
 * FactureResource (pour le cas simple d'une seule facture, pré-rempli
 * depuis celle-ci), ou depuis cette Resource (pour regrouper des lignes de
 * plusieurs factures différentes d'un même client, voir le docblock de
 * CreerAvoirAction). Jamais de page edit : un avoir émis ne se modifie ni
 * ne se supprime jamais (voir AvoirPolicy).
 */
class AvoirResource extends Resource
{
    protected static ?string $model = Avoir::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'avoir';

    protected static ?string $pluralModelLabel = 'avoirs';

    /**
     * Même esprit de confidentialité que FactureResource, mais basée sur
     * le créateur de l'avoir lui-même : voir AvoirPolicy.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['client', 'lignes.ligneFacture.facture']);

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('client_id')
                ->label('Client')
                ->relationship('client', 'nom')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(fn (?int $state, Set $set) => $set('lignes', $state ? self::lignesCreditablesPourClient($state) : []))
                ->columnSpanFull(),

            Repeater::make('lignes')
                ->label('Lignes à créditer')
                ->helperText('Toutes les lignes encore créditables de ce client, toutes factures confondues. Retirez celles à ne pas créditer.')
                ->schema([
                    Hidden::make('ligne_facture_id'),
                    TextInput::make('facture_numero')->label('Facture')->disabled()->dehydrated(false),
                    TextInput::make('description')->label('Produit / description')->disabled()->dehydrated(false),
                    TextInput::make('quantite')
                        ->label('Quantité à créditer')
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(fn (Get $get) => LigneFacture::find($get('ligne_facture_id'))?->quantiteCreditableRestante() ?? 0),
                ])
                ->columns(4)
                ->addable(false)
                ->deletable(true)
                ->columnSpanFull(),

            Textarea::make('motif')->label('Motif de l\'avoir')->required()->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Avoir')->schema([
                TextEntry::make('numero')->label('Numéro'),
                TextEntry::make('client.nom')->label('Client'),
                TextEntry::make('factures_concernees')
                    ->label('Factures créditées')
                    ->state(fn (Avoir $record) => $record->facturesConcernees()->pluck('numero')->implode(', ')),
                TextEntry::make('date_emission')->label('Émis le')->dateTime('d/m/Y à H:i'),
                TextEntry::make('creePar.name')->label('Émis par'),
                TextEntry::make('motif')->label('Motif')->columnSpanFull(),
            ])->columns(2),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
                // Un avoir n'est jamais remboursé par ce logiciel (pas
                // d'intégration bancaire) : tant qu'il reste du crédit non
                // appliqué à une facture (voir
                // AppliquerAvoirsDisponiblesAction), ce doit être visible
                // ici aussi, pas seulement sur la fiche du client.
                TextEntry::make('montant_non_alloue')
                    ->label('Crédit encore disponible')
                    ->state(fn (Avoir $record) => $record->montantNonAlloue())
                    ->money('EUR')
                    ->visible(fn (Avoir $record) => $record->montantNonAlloue() > 0),
            ])->columns(4),

            InfolistSection::make('Produits retournés')->schema([
                RepeatableEntry::make('lignes')
                    ->label('')
                    ->schema([
                        TextEntry::make('description'),
                        TextEntry::make('quantite'),
                        TextEntry::make('prix_unitaire_ht')->label('PU HT')->money('EUR'),
                        TextEntry::make('prix_unitaire_ttc')->label('PU TTC')->money('EUR'),
                        TextEntry::make('taux_tva')->label('Taux TVA')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                        TextEntry::make('montant_ht')->label('Total HT')->money('EUR'),
                        TextEntry::make('montant_tva')->label('TVA')->money('EUR'),
                        TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
                    ])
                    ->columns(8),
            ]),

            InfolistSection::make('Crédit appliqué')
                ->visible(fn (Avoir $record) => $record->allocations()->exists())
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->label('')
                        ->schema([
                            TextEntry::make('facture.numero')->label('Facture'),
                            TextEntry::make('montant_alloue')->label('Montant crédité')->money('EUR'),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * @return list<array{ligne_facture_id: int, facture_numero: ?string, description: string, quantite: int}>
     */
    private static function lignesCreditablesPourClient(int $clientId): array
    {
        return LigneFacture::query()
            ->whereHas('facture', fn (Builder $query) => $query
                ->where('client_id', $clientId)
                ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee]))
            ->with('facture')
            ->get()
            ->map(fn (LigneFacture $ligne) => [
                'ligne_facture_id' => $ligne->id,
                'facture_numero' => $ligne->facture->numero,
                'description' => $ligne->description,
                'quantite' => $ligne->quantiteCreditableRestante(),
            ])
            ->filter(fn (array $ligne) => $ligne['quantite'] > 0)
            ->values()
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->searchable(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('factures_concernees')
                    ->label('Factures créditées')
                    ->state(fn (Avoir $record) => $record->facturesConcernees()->pluck('numero')->implode(', ')),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('motif')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('date_emission')->label('Émis le')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('creePar.name')->label('Émis par')->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('telecharger_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Avoir $record) {
                        $generateur = app(AvoirPdfGenerator::class);

                        return response()->streamDownload(
                            fn () => print ($generateur->generer($record)->output()),
                            $generateur->nomFichier($record),
                        );
                    }),
            ])
            // Aucune action de suppression, ni unitaire ni groupée : un
            // avoir émis ne se supprime jamais (voir AvoirPolicy::delete()).
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAvoirs::route('/'),
            'create' => Pages\CreateAvoir::route('/create'),
            'view' => Pages\ViewAvoir::route('/{record}'),
        ];
    }
}
