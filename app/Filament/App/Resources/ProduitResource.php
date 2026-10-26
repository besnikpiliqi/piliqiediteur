<?php

namespace App\Filament\App\Resources;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ProduitResource\Pages;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
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
    use ArchiveLaFiche, SupprimeEnMasseLigneParLigne;

    protected static ?string $model = Produit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'produit';

    protected static ?string $pluralModelLabel = 'produits';

    /**
     * Voir StockLedgerService::entrerALInventaireDOuverture(),
     * StockLedgerService::receptionner() et
     * StockLedgerService::retournerAuFournisseur().
     */
    private const AIDE_CMUP = 'Le coût moyen du stock : celui de l\'achat ou de l\'inventaire d\'ouverture qui a créé le produit, recalculé à chaque facture d\'achat validée, au prix réellement payé, remise et escompte déduits, et à chaque retour au fournisseur, au coût de la ligne d\'achat retournée.';

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
                // Archivés compris et sans tenir compte de la casse, comme
                // l'index unique de la table (voir
                // Produit::referenceDejaPrise()) ; parmi les produits du
                // magasin seulement (MagasinScope).
                ->rule(static fn (TextInput $component): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($component, $produitModifie): void {
                    if (is_string($value) && Produit::referenceDejaPrise($value, $component->evaluate($produitModifie))) {
                        $fail('Cette référence est déjà celle d\'un autre produit, peut-être archivé : affichez les produits archivés pour le restaurer.');
                    }
                }),
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
     * référence ou code-barres, sans casse ni accents (voir
     * RechercheSansAccents), pour qu'une douchette USB (qui se contente de
     * « taper » les chiffres scannés dans le champ actif) retrouve le
     * produit aussi vite qu'en tapant son nom.
     *
     * @param  Builder<Produit>  $produits
     * @return array<int, string>
     */
    public static function rechercher(Builder $produits, string $recherche): array
    {
        return RechercheSansAccents::appliquer($produits, $recherche, ['nom', 'reference', 'code_barres'])
            ->orderBy('nom')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Produit $produit): array => [$produit->id => "{$produit->nom} ({$produit->reference})"])
            ->all();
    }

    /**
     * Prix de vente saisi et enregistré en TTC, pas HT (contrairement au
     * prix d'achat) : c'est le prix affiché au client qui compte pour un
     * vendeur qui fixe un tarif, et c'est lui qu'une ligne de vente reprend
     * tel quel. C'est le seul prix de vente enregistré, arrondi une fois au
     * centime (voir Produit::arrondirLePrixDeVente()) ; son HT n'est
     * qu'affiché (voir detailPrixDeVente()).
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
            // Saisi librement (B2), le taux du territoire du magasin par
            // défaut : un taux qui n'y est pas en vigueur est signalé, sans
            // être refusé (X8).
            TextInput::make('taux_tva')
                ->label('Taux de TVA (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required()
                ->default(fn (): string => DevisResource::pourcentageEnTexte(app(TenantContext::class)->magasin()->tauxTvaParDefaut()))
                ->formatStateUsing(fn (mixed $state): mixed => blank($state) ? $state : DevisResource::pourcentageEnTexte($state))
                ->helperText(fn (mixed $state): ?string => self::aideSurLeTauxDeTva($state))
                ->saisieEnDirect(),
        ];
    }

    /**
     * En franchise en base, le rappel que les ventes se font sans TVA au
     * prix TTC indiqué (B10) ; et un taux saisi qui n'est pas en vigueur
     * sur le territoire du magasin (X8).
     */
    private static function aideSurLeTauxDeTva(mixed $taux): ?string
    {
        $magasin = app(TenantContext::class)->magasin();
        $aides = array_filter([
            $magasin->societe->estEnFranchiseDeTva()
                ? 'Votre société est en franchise en base : vos ventes sont facturées sans TVA ('.RegimeTva::ARTICLE_FRANCHISE.'), au prix TTC indiqué.'
                : null,
            $magasin->territoireTva()?->aideSiTauxInhabituel($taux),
        ]);

        return $aides === [] ? null : implode(' ', $aides);
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
     * "Soit 33,33 € HT + 6,67 € de TVA" : le détail du prix au taux de la
     * fiche, celui qu'une ligne de vente reprendra pour cet article hors
     * franchise en base (voir ConstructeurLigneDocument).
     */
    public static function detailPrixDeVente(Get $get): ?string
    {
        $tauxTva = self::tauxDeTvaSaisi($get('taux_tva'));

        if (blank($get('prix_vente_ttc')) || $tauxTva === null) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);

        try {
            $prixUnitaireTtc = SaisieDecimale::enCentiemes($get('prix_vente_ttc'));
            $prixUnitaireHt = $calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, SaisieDecimale::enCentiemes($tauxTva));
        } catch (InvalidArgumentException) {
            return null;
        }

        return 'Soit '.self::euros($calculateur->enDecimal($prixUnitaireHt)).' HT + '
            .self::euros($calculateur->enDecimal($prixUnitaireTtc - $prixUnitaireHt)).' de TVA';
    }

    /**
     * Un montant écrit comme sa colonne (« 1234.50 »), tel qu'on le lit :
     * « 1 234,50 € ».
     */
    private static function euros(string $montant): string
    {
        return number_format((float) $montant, 2, ',', ' ').' €';
    }

    /**
     * La fiche d'un produit existant, la seule page de formulaire de cette
     * ressource : tout s'y modifie, sauf ce qui n'y est qu'affiché : son
     * fournisseur, celui de son dernier achat validé ; son coût moyen, que
     * seuls les achats, les retours au fournisseur et les inventaires font
     * évoluer ; son stock, que tiennent ses mouvements (ventes, retours,
     * achats, inventaires). Un produit reçu sur une facture d'achat
     * récupérée automatiquement garde aussi ce que son fournisseur en dit
     * (voir Produit::vientDUneFactureAchatApi()) : ces champs-là restent
     * affichés mais ne se modifient pas. Son prix de vente, sa TVA et le
     * suivi de son stock restent ceux du magasin.
     */
    public static function form(Form $form): Form
    {
        $produit = $form->getRecord();
        $figeParSonFournisseur = $produit instanceof Produit && $produit->vientDUneFactureAchatApi();
        $calculateur = app(CalculateurLigne::class);

        return $form->schema([
            Section::make('Identification')
                ->description($figeParSonFournisseur ? 'Reçu sur une facture d\'achat récupérée automatiquement : sa référence et son nom sont ceux du fournisseur.' : null)
                ->schema([
                    ...array_map(
                        fn (Field $champ): Field => $champ->disabled($figeParSonFournisseur),
                        self::champsIdentification(fn (?Produit $record): ?Produit => $record),
                    ),
                    // Celui de son dernier achat validé, même archivé depuis
                    // (voir Produit::rattacherAuFournisseur()) : il ne se
                    // choisit jamais ici.
                    Placeholder::make('fournisseur')
                        ->label('Fournisseur')
                        ->content(fn (?Produit $record): string => $record?->fournisseur?->nom ?? 'Aucun achat validé')
                        ->helperText('Celui de sa dernière facture d\'achat validée, saisie à la main ou reçue automatiquement.'),
                ])->columns(2),

            Section::make('Prix et TVA')->schema([
                // Posé par la validation qui a créé le produit, au coût de
                // sa ligne d'achat ou d'inventaire d'ouverture ; ensuite
                // seuls les achats le font évoluer : chaque facture d'achat
                // validée ou corrigée, et chaque retour au fournisseur (voir
                // StockLedgerService). Il ne se modifie jamais ici :
                // enregistré au dix-millième d'euro, il s'affiche au
                // centime, comme tout prix unitaire (voir
                // CalculateurLigne::prixUnitaireAuCentime()).
                TextInput::make('cmup_ht')
                    ->label('Coût moyen pondéré (CMUP) HT')
                    ->prefix('€')
                    ->formatStateUsing(fn (?string $state): ?string => blank($state) ? $state : $calculateur->enDecimal($calculateur->prixUnitaireAuCentime($calculateur->enMillioniemes($state))))
                    ->helperText(self::AIDE_CMUP)
                    ->disabled(),
                ...self::prixVenteTtcSchema(),
            ])->columns(3),

            Section::make('Stock')->schema([
                ...self::champsSuiviDuStock(),
                TextInput::make('stock_quantite')
                    ->label('Stock actuel')
                    ->disabled()
                    ->visible(fn (Get $get): bool => (bool) $get('suivi_stock'))
                    ->helperText('Tenu à jour par les ventes, les retours clients, les factures d\'achat, les avoirs fournisseur et l\'inventaire d\'ouverture : il ne se saisit pas ici.'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Référence')->rechercheSansAccents()->sortable(),
                Tables\Columns\TextColumn::make('nom')->rechercheSansAccents()->sortable(),
                Tables\Columns\TextColumn::make('prix_vente_ttc')->label('PV TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('taux_tva')->label('TVA')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                // Une quantité négative, laissée par une vente sans stock
                // (R1), reste en rouge, « À régulariser », jusqu'à un achat,
                // une réception ou un inventaire qui la ramène à zéro ou
                // au-dessus.
                Tables\Columns\TextColumn::make('stock_quantite')
                    ->label('Stock')
                    ->formatStateUsing(fn (Produit $record) => $record->suivi_stock
                        ? Number::format($record->stock_quantite, locale: config('app.locale'))
                        : '—')
                    ->description(fn (Produit $record): ?string => $record->estEnStockNegatif() ? 'À régulariser' : null)
                    ->badge()
                    ->color(fn (Produit $record) => ! $record->suivi_stock
                        ? 'gray'
                        : ($record->enAlerteStock() ? 'danger' : 'success')),
            ])
            ->filters([
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
                        'negatif' => 'Stock négatif',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'en_stock' => $query->where('suivi_stock', true)->where('stock_quantite', '>', 0),
                        'epuise' => $query->epuises(),
                        'negatif' => $query->enStockNegatif(),
                        default => $query,
                    }),
                static::filtreDesArchives(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::actionRestaurer(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::archivageEnMasse(),
                ]),
            ]);
    }

    /**
     * Un produit ne se crée pas ici : il naît seulement de la validation
     * d'une facture d'achat ou d'un inventaire d'ouverture (voir
     * OrigineDesProduits), qui exige aussi le droit de créer des produits
     * (voir ProduitPolicy::create()). Aucune action de création ne
     * s'affiche donc dans cette ressource, et elle n'a pas de page de
     * création.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProduits::route('/'),
            'edit' => Pages\EditProduit::route('/{record}/edit'),
        ];
    }
}
