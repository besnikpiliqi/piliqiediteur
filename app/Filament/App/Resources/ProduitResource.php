<?php

namespace App\Filament\App\Resources;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ProduitResource\Pages;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Closure;
use Filament\Forms\Components\Field;
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
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;

class ProduitResource extends Resource
{
    use SupprimeEnMasseLigneParLigne;

    protected static ?string $model = Produit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'produit';

    protected static ?string $pluralModelLabel = 'produits';

    /**
     * Voir StockLedgerService::receptionner().
     */
    private const AIDE_CMUP = 'Le coût moyen du stock, recalculé à chaque facture d\'achat validée avec le prix réellement payé, remise et escompte déduits.';

    /**
     * Référence, code-barres, nom, taille et couleur : la fiche produit et
     * le formulaire de ligne d'une facture d'achat (voir
     * AjouteDesLignesDAchat) en partagent la saisie. $produitModifie donne
     * le produit en cours de modification (null pour un nouveau produit),
     * dont la référence n'entre pas en conflit avec elle-même.
     *
     * @return array<Field>
     */
    public static function champsIdentification(Closure $produitModifie): array
    {
        return [
            TextInput::make('reference')
                ->label('Référence')
                ->helperText('Laissez vide pour une référence générée automatiquement.')
                ->maxLength(60)
                // Archivés compris, comme l'index unique de la table (voir
                // Produit::verifierQueSaReferenceEstLibre()).
                ->unique(
                    table: Produit::class,
                    ignorable: $produitModifie,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('magasin_id', app(TenantContext::class)->id()),
                )
                ->validationMessages(['unique' => 'Cette référence est déjà celle d\'un autre produit, peut-être supprimé. Affichez les enregistrements supprimés de la liste des produits pour le restaurer.']),
            TextInput::make('code_barres')->label('Code-barres')->maxLength(50),
            TextInput::make('nom')->required()->maxLength(200)->columnSpanFull(),
            TextInput::make('taille')->maxLength(20),
            TextInput::make('couleur')->maxLength(30),
        ];
    }

    /**
     * @return array<Field>
     */
    public static function champsSuiviDuStock(): array
    {
        return [
            Toggle::make('suivi_stock')->label('Suivre le stock')->default(true)->live(),
            TextInput::make('seuil_alerte')
                ->label('Seuil d\'alerte')
                ->integer()
                ->minValue(0)
                ->default(0)
                ->visible(fn (Get $get): bool => (bool) $get('suivi_stock')),
        ];
    }

    /**
     * Recherche côté serveur plutôt qu'une liste préchargée : par nom,
     * référence ou code-barres, pour qu'une douchette USB (qui se contente
     * de « taper » les chiffres scannés dans le champ actif) retrouve le
     * produit aussi vite qu'en tapant son nom.
     *
     * @param  Builder<Produit>  $produits
     * @return array<int, string>
     */
    public static function rechercher(Builder $produits, string $recherche): array
    {
        return $produits
            ->where(fn (Builder $produits) => $produits
                ->where('nom', 'like', "%{$recherche}%")
                ->orWhere('reference', 'like', "%{$recherche}%")
                ->orWhere('code_barres', 'like', "%{$recherche}%"))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Produit $produit): array => [$produit->id => "{$produit->nom} ({$produit->reference})"])
            ->all();
    }

    /**
     * Prix de vente saisi et enregistré en TTC, pas HT (contrairement au
     * prix d'achat) : c'est le prix affiché au client qui compte pour un
     * vendeur qui fixe un tarif, et c'est lui qu'une ligne de vente reprend
     * tel quel. Le HT correspondant est déduit par le modèle à
     * l'enregistrement (voir Produit::synchroniserPrixDeVente()).
     *
     * @return array<Field>
     */
    public static function prixVenteTtcSchema(): array
    {
        return [
            TextInput::make('prix_vente_ttc')
                ->label('Prix de vente TTC')
                ->numeric()
                ->minValue(0)
                ->maxValue(Caisse::MONTANT_MAXIMUM)
                ->prefix('€')
                ->required()
                ->saisieEnDirect()
                ->helperText(fn (Get $get) => self::detailPrixDeVente($get)),
            TextInput::make('taux_tva')
                ->label('Taux de TVA (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required()
                ->default(20)
                ->formatStateUsing(fn (mixed $state): mixed => blank($state) ? $state : DevisResource::pourcentageEnTexte($state))
                ->saisieEnDirect(),
        ];
    }

    /**
     * Le taux de TVA saisi, ou null tant que le champ est vide : un champ
     * vidé n'est ni un taux de 0 % ni le taux par défaut, et rien ne se
     * calcule sans lui.
     */
    public static function tauxDeTvaSaisi(mixed $taux): ?float
    {
        return filled($taux) ? (float) $taux : null;
    }

    /**
     * "Soit 33,33 € HT + 6,67 € de TVA" : le détail exact qu'une ligne de
     * vente reprendra pour cet article (voir CalculateurLigne).
     */
    public static function detailPrixDeVente(Get $get): ?string
    {
        $tauxTva = self::tauxDeTvaSaisi($get('taux_tva'));

        if (blank($get('prix_vente_ttc')) || $tauxTva === null) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);

        try {
            $prixUnitaireTtc = $calculateur->arrondirAuCentime((float) $get('prix_vente_ttc'));
            $prixUnitaireHt = $calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, $tauxTva);
        } catch (InvalidArgumentException) {
            return null;
        }

        return 'Soit '.number_format($prixUnitaireHt, 2, ',', ' ').' € HT + '
            .number_format($calculateur->soustraire($prixUnitaireTtc, $prixUnitaireHt), 2, ',', ' ').' € de TVA';
    }

    /**
     * Un produit reçu sur une facture d'achat récupérée automatiquement
     * garde ce que son fournisseur en dit (voir
     * Produit::vientDUneFactureAchatApi()) : ces champs-là restent
     * affichés mais ne se modifient pas. Son prix de vente, sa TVA et le
     * suivi de son stock restent ceux du magasin.
     */
    public static function form(Form $form): Form
    {
        $produit = $form->getRecord();
        $figeParSonFournisseur = $produit instanceof Produit && $produit->vientDUneFactureAchatApi();

        return $form->schema([
            Section::make('Identification')
                ->description($figeParSonFournisseur ? 'Reçu sur une facture d\'achat récupérée automatiquement : sa référence, son nom et son fournisseur sont ceux du fournisseur.' : null)
                ->schema([
                    ...array_map(
                        fn (Field $champ): Field => $champ->disabled($figeParSonFournisseur),
                        self::champsIdentification(fn (?Produit $record): ?Produit => $record),
                    ),
                    Select::make('fournisseur_id')
                        ->label('Fournisseur')
                        ->relationship('fournisseur', 'nom')
                        ->searchable()
                        ->preload()
                        ->helperText('Celui de la dernière facture d\'achat validée pour ce produit.')
                        ->disabled($figeParSonFournisseur),
                ])->columns(2),

            Section::make('Prix et TVA')->schema([
                // Saisi une fois, à la création ; ensuite seules les
                // factures d'achat validées le font évoluer (voir
                // StockLedgerService::receptionner()).
                TextInput::make('cmup_ht')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Prix d\'achat HT' : 'Coût moyen pondéré (CMUP) HT')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(Caisse::MONTANT_MAXIMUM)
                    ->prefix('€')
                    ->required()
                    ->formatStateUsing(fn (mixed $state): mixed => blank($state) ? $state : number_format((float) $state, 2, '.', ''))
                    ->helperText(fn (string $operation): ?string => $operation === 'create' ? null : self::AIDE_CMUP)
                    ->disabledOn('edit'),
                ...self::prixVenteTtcSchema(),
            ])->columns(3),

            Section::make('Stock')->schema([
                ...self::champsSuiviDuStock(),
                TextInput::make('stock_quantite')
                    ->label('Stock actuel')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'edit' && $get('suivi_stock'))
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
                        ? Number::format($record->stock_quantite, locale: config('app.locale'))
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
                // Seulement les produits qui suivent leur stock, comme le
                // widget de la liste (voir ValeurStockApercu) : les autres
                // n'en ont pas.
                Tables\Filters\SelectFilter::make('statut_stock')
                    ->label('Statut du stock')
                    ->options([
                        'en_stock' => 'En stock',
                        'epuise' => 'Épuisé',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'en_stock' => $query->where('suivi_stock', true)->where('stock_quantite', '>', 0),
                        'epuise' => $query->where('suivi_stock', true)->where('stock_quantite', '<=', 0),
                        default => $query,
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(),
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
