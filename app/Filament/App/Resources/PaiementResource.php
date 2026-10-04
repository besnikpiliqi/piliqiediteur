<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Filament\App\Resources\PaiementResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pas de page "edit" ni d'action de suppression : un paiement enregistré
 * ne se modifie ni ne se supprime jamais (voir PaiementPolicy et le
 * commentaire sur Paiement::$fillable). Une erreur se corrige en
 * enregistrant un nouveau paiement.
 */
class PaiementResource extends Resource
{
    protected static ?string $model = Paiement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'paiement';

    protected static ?string $pluralModelLabel = 'paiements';

    /**
     * Même règle de confidentialité par vendeur que FactureResource (voir
     * PaiementPolicy::view) : la liste elle-même doit déjà être filtrée,
     * pas seulement l'accès direct à un enregistrement.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('allocations.facture');

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Paiement')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),
                Select::make('mode_paiement')
                    ->label('Mode de paiement')
                    ->options(ModePaiement::class)
                    ->required(),
                // live() et non live(onBlur: true) : le reste à allouer se
                // met à jour pendant la saisie.
                TextInput::make('montant')->label('Montant reçu')->numeric()->required()->live(),
                TextInput::make('reference')->label('Référence'),
                DatePicker::make('recu_le')->label('Reçu le')->required()->default(now()),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Allocation aux factures')
                ->description('Répartissez ce paiement sur une ou plusieurs factures du client.')
                ->schema([
                    Repeater::make('allocations')
                        ->label('')
                        ->schema([
                            Select::make('facture_id')
                                ->label('Facture')
                                ->options(function (Get $get) {
                                    $clientId = $get('../../client_id');

                                    if (! $clientId) {
                                        return [];
                                    }

                                    return Facture::query()
                                        ->where('client_id', $clientId)
                                        ->whereIn('statut', [
                                            StatutFacture::Emise,
                                            StatutFacture::Envoyee,
                                            StatutFacture::PartiellementPayee,
                                            StatutFacture::EnRetard,
                                        ])
                                        ->get()
                                        ->filter(fn (Facture $facture) => $facture->montantRestant() > 0)
                                        ->mapWithKeys(fn (Facture $facture) => [
                                            $facture->id => "{$facture->numero} — restant : ".number_format($facture->montantRestant(), 2, ',', ' ').' €',
                                        ]);
                                })
                                ->searchable()
                                ->live()
                                ->required()
                                ->afterStateUpdated(function (?int $state, Set $set) {
                                    $facture = $state ? Facture::find($state) : null;
                                    $set('montant', $facture?->montantRestant());
                                }),
                            TextInput::make('montant')
                                ->label('Montant alloué')
                                ->numeric()
                                ->required()
                                // Une allocation ne doit jamais dépasser le
                                // restant dû de la facture choisie : un
                                // trop-perçu ne devient pas un crédit
                                // (contrairement à un avoir), seule une
                                // allocation partielle (acompte) reste
                                // permise.
                                ->maxValue(fn (Get $get) => Facture::find($get('facture_id'))?->montantRestant()),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel('Ajouter une facture')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recu_le')->label('Reçu le')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('mode_paiement')->label('Mode')->badge(),
                Tables\Columns\TextColumn::make('montant')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('allocations')
                    ->label('Factures réglées')
                    ->state(fn (Paiement $record) => $record->allocations
                        ->map(fn ($allocation) => $allocation->facture?->numero ?? '—')
                        ->join(', '))
                    ->wrap(),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->toggleable(),
                Tables\Columns\TextColumn::make('creePar.name')->label('Enregistré par')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('mode_paiement')->options(ModePaiement::class),
            ])
            // Aucune action d'édition ni de suppression : voir le
            // commentaire de classe.
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaiements::route('/'),
            'create' => Pages\CreatePaiement::route('/create'),
        ];
    }
}
