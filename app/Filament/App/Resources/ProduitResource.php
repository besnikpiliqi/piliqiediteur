<?php

namespace App\Filament\App\Resources;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\ProduitResource\Pages;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use InvalidArgumentException;

class ProduitResource extends Resource
{
    protected static ?string $model = Produit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'produit';

    protected static ?string $pluralModelLabel = 'produits';

    /**
     * Mêmes champs que form() (hors stock_quantite, propre à l'édition --
     * un produit tout juste créé n'a de toute façon jamais de stock
     * existant -- et hors fournisseur_id, redondant sur la facture
     * d'achat où ce Select apparaît déjà en tête de page), utilisés dans
     * la modale "+" du Select produit_id d'une ligne de
     * Facture/Devis/Facture d'achat : voir DevisResource::ligneSchema()
     * et FactureAchatResource::form().
     *
     * @return array<Component>
     */
    public static function creationRapideSchema(): array
    {
        return [
            TextInput::make('reference')
                ->label('Référence')
                ->helperText('Laissez vide pour une référence générée automatiquement.')
                ->maxLength(60),
            TextInput::make('code_barres')->label('Code-barres')->maxLength(50),
            TextInput::make('nom')->required()->maxLength(200)->columnSpanFull(),
            TextInput::make('taille')->maxLength(20),
            TextInput::make('couleur')->maxLength(30),
            TextInput::make('prix_achat_ht')->label('Prix d\'achat HT')->numeric()->prefix('€')->required(),
            ...self::prixVenteTtcSchema(),
            Toggle::make('suivi_stock')->label('Suivre le stock')->default(true)->live(),
            TextInput::make('seuil_alerte')
                ->label('Seuil d\'alerte')
                ->integer()
                ->minValue(0)
                ->default(0)
                ->visible(fn ($get) => $get('suivi_stock')),
            Toggle::make('actif')->default(true),
        ];
    }

    /**
     * Prix de vente saisi et enregistré en TTC, pas HT (contrairement au
     * prix d'achat) : c'est le prix affiché au client qui compte pour un
     * vendeur qui fixe un tarif, et c'est lui qu'une ligne de vente reprend
     * tel quel. Le HT correspondant est déduit par le modèle à
     * l'enregistrement (voir Produit::synchroniserPrixDeVente()).
     *
     * @return array<Component>
     */
    private static function prixVenteTtcSchema(): array
    {
        return [
            TextInput::make('prix_vente_ttc')
                ->label('Prix de vente TTC')
                ->numeric()
                ->prefix('€')
                ->required()
                ->saisieEnDirect()
                ->helperText(fn (Get $get) => self::detailPrixDeVente($get)),
            TextInput::make('taux_tva')
                ->label('Taux de TVA (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(20)
                ->formatStateUsing(fn (mixed $state): mixed => blank($state) ? $state : DevisResource::pourcentageEnTexte($state))
                ->saisieEnDirect(),
        ];
    }

    /**
     * "Soit 33,33 € HT + 6,67 € de TVA" : le détail exact qu'une ligne de
     * vente reprendra pour cet article (voir CalculateurLigne).
     */
    private static function detailPrixDeVente(Get $get): ?string
    {
        if (blank($get('prix_vente_ttc'))) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);

        try {
            $prix = $calculateur->prixUnitaires(PrixReference::Ttc, (float) $get('prix_vente_ttc'), (float) ($get('taux_tva') ?? 20));
        } catch (InvalidArgumentException) {
            return null;
        }

        return 'Soit '.number_format($prix['prix_unitaire_ht'], 2, ',', ' ').' € HT + '
            .number_format($calculateur->soustraire($prix['prix_unitaire_ttc'], $prix['prix_unitaire_ht']), 2, ',', ' ').' € de TVA';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identification')->schema([
                TextInput::make('reference')
                    ->label('Référence')
                    ->helperText('Laissez vide pour une référence générée automatiquement.')
                    ->maxLength(60),
                TextInput::make('code_barres')->label('Code-barres')->maxLength(50),
                TextInput::make('nom')->required()->maxLength(200)->columnSpanFull(),
                TextInput::make('taille')->maxLength(20),
                TextInput::make('couleur')->maxLength(30),
                Select::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationship('fournisseur', 'nom')
                    ->searchable()
                    ->preload(),
            ])->columns(2),

            Section::make('Prix et TVA')->schema([
                TextInput::make('prix_achat_ht')->label('Prix d\'achat HT')->numeric()->prefix('€')->required(),
                ...self::prixVenteTtcSchema(),
            ])->columns(3),

            Section::make('Stock')->schema([
                Toggle::make('suivi_stock')->label('Suivre le stock')->default(true)->live(),
                TextInput::make('seuil_alerte')
                    ->label('Seuil d\'alerte')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn ($get) => $get('suivi_stock')),
                TextInput::make('stock_quantite')
                    ->label('Stock actuel')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation, $get) => $operation === 'edit' && $get('suivi_stock'))
                    ->helperText('Modifiable uniquement via un mouvement de stock, jamais directement ici.'),
            ])->columns(3),

            Toggle::make('actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Référence')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('prix_vente_ttc')->label('PV TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('taux_tva')->label('TVA')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                Tables\Columns\TextColumn::make('stock_quantite')
                    ->label('Stock')
                    ->formatStateUsing(fn (Produit $record) => $record->suivi_stock
                        ? Number::format($record->stock_quantite, maxPrecision: 3, locale: config('app.locale'))
                        : '—')
                    ->badge()
                    ->color(fn (Produit $record) => ! $record->suivi_stock
                        ? 'gray'
                        : ($record->enAlerteStock() ? 'danger' : 'success')),
                Tables\Columns\IconColumn::make('actif')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('actif'),
                Tables\Filters\TernaryFilter::make('suivi_stock'),
                Tables\Filters\SelectFilter::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationship('fournisseur', 'nom'),
                Tables\Filters\SelectFilter::make('statut_stock')
                    ->label('Statut du stock')
                    ->options([
                        'en_stock' => 'En stock',
                        'epuise' => 'Épuisé',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'en_stock' => $query->where('stock_quantite', '>', 0),
                            'epuise' => $query->where('stock_quantite', '<=', 0),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProduits::route('/'),
            'create' => Pages\CreateProduit::route('/create'),
            'edit' => Pages\EditProduit::route('/{record}/edit'),
        ];
    }
}
