<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\DevisResource\Pages;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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

class DevisResource extends Resource
{
    use GereLesExceptionsMetier;

    protected static ?string $model = Devis::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'devis';

    protected static ?string $pluralModelLabel = 'devis';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Devis')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id')))
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE))),
                DatePicker::make('date_validite')->label('Valide jusqu\'au'),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Lignes')->schema([
                // Modifiables même en édition (voir Devis::estModifiable())
                // tant que le devis n'a pas encore été transformé en
                // facture : cette page n'est de toute façon accessible que
                // dans ce cas (voir DevisPolicy::update() et EditDevis).
                Repeater::make('lignes')
                    ->label('')
                    ->schema(self::ligneSchema())
                    ->columns(9)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->required(),
            ]),
        ]);
    }

    /**
     * Partagé avec FactureResource : même structure de ligne (snapshot
     * produit + description/prix/TVA/remise librement modifiables).
     *
     * @return array<Component>
     */
    public static function ligneSchema(): array
    {
        return [
            Select::make('produit_id')
                ->label('Produit')
                ->helperText('Laissez vide pour une ligne libre (main d\'œuvre, service, produit hors catalogue...). Recherchez par nom, référence, ou scannez le code-barres.')
                ->searchable()
                // Recherche côté serveur plutôt qu'une liste statique
                // préchargée : inclut le code-barres, pour qu'une
                // douchette USB (qui se contente de "taper" les chiffres
                // scannés dans le champ actif) retrouve le produit aussi
                // vite qu'en tapant son nom.
                ->getSearchResultsUsing(fn (string $search) => Produit::query()
                    ->where('actif', true)
                    ->where(fn ($query) => $query
                        ->where('nom', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('code_barres', 'like', "%{$search}%"))
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Produit $produit) => [$produit->id => "{$produit->nom} ({$produit->reference})"]))
                ->getOptionLabelUsing(fn ($value) => Produit::find($value)?->nom)
                // Un Form explicitement modelé sur Produit (plutôt qu'un
                // simple tableau de schéma) : ce Select n'utilise pas
                // relationship(), donc Filament ne peut pas déduire tout
                // seul le modèle à utiliser pour la modale -- sans ça,
                // le Select fournisseur_id imbriqué (qui, lui, utilise
                // relationship()) plante en essayant de résoudre sa
                // relation sur un modèle nul.
                ->createOptionForm(fn (Form $form): Form => $form
                    ->model(Produit::class)
                    ->schema(ProduitResource::creationRapideSchema()))
                ->createOptionModalHeading('Nouveau produit')
                ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::PRODUIT_CREATE)))
                ->createOptionUsing(fn (array $data) => Produit::create($data)->getKey())
                ->live()
                ->columnSpan(2)
                ->afterStateUpdated(function (?int $state, Set $set) {
                    $produit = $state ? Produit::find($state) : null;

                    if ($produit === null) {
                        return;
                    }

                    $set('description', $produit->nom);
                    $set('reference', $produit->reference);
                    $set('prix_unitaire_ht', (string) $produit->prix_vente_ht);
                    $set('taux_tva', (string) $produit->taux_tva);
                    $set('prix_unitaire_ttc', (string) $produit->prixVenteTtc());
                }),
            TextInput::make('description')->required()->columnSpan(2),
            // live() et non live(onBlur: true) sur tous les champs de la
            // ligne : le total (et, dans la Caisse, la monnaie à rendre) se
            // met à jour pendant la saisie, pas seulement au clic ailleurs.
            TextInput::make('quantite')->numeric()->required()->default(1)->live(),
            TextInput::make('prix_unitaire_ht')
                ->label('PU HT')
                ->numeric()
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    $set('prix_unitaire_ttc', $state === null || $state === ''
                        ? null
                        : self::ttcDepuisHt((float) $state, self::tauxTvaActuel($get)));
                }),
            // Le vendeur pense souvent en prix affiché/TTC (ex: "10 € pile")
            // plutôt qu'en HT : ce champ n'est qu'une façon alternative de
            // saisir prix_unitaire_ht (seul champ réellement enregistré,
            // voir dehydrated(false)) -- il évite au vendeur de calculer
            // lui-même le HT correspondant au prix TTC visé.
            TextInput::make('prix_unitaire_ttc')
                ->label('PU TTC')
                ->numeric()
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(function (TextInput $component, Get $get) {
                    $component->state(self::ttcDepuisHt((float) ($get('prix_unitaire_ht') ?? 0), self::tauxTvaActuel($get)));
                })
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if ($state === null || $state === '') {
                        return;
                    }

                    $set('prix_unitaire_ht', self::htDepuisTtc((float) $state, self::tauxTvaActuel($get)));
                }),
            TextInput::make('taux_tva')
                ->label('TVA %')
                ->numeric()
                ->required()
                ->default(20)
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    $set('prix_unitaire_ttc', self::ttcDepuisHt((float) ($get('prix_unitaire_ht') ?? 0), (float) ($state ?? 0)));
                }),
            TextInput::make('remise_pourcentage')->label('Remise %')->numeric()->default(0)->live(),
            Placeholder::make('total_ligne')
                ->label('Total TTC')
                ->columnSpanFull()
                // Passe par CalculateurLigne (même service que celui qui
                // persiste réellement la ligne via ConstructeurLigneDocument)
                // plutôt que de recalculer la formule ici : sans ça, cet
                // aperçu pourrait afficher un centime différent de ce qui
                // est effectivement enregistré, selon comment l'arrondi
                // tombe -- un écart inacceptable une fois transmis à l'état.
                ->content(function (Get $get): string {
                    $montants = app(CalculateurLigne::class)->calculer(
                        (float) ($get('prix_unitaire_ht') ?? 0),
                        (float) ($get('quantite') ?? 0),
                        (float) ($get('taux_tva') ?? 0),
                        (float) ($get('remise_pourcentage') ?? 0),
                    );

                    return number_format($montants['montant_ttc'], 2, ',', ' ').' €';
                }),
        ];
    }

    /**
     * TVA par défaut (20 %) tant que le champ taux_tva n'a pas encore été
     * hydraté -- évite une conversion HT/TTC fausse à l'affichage initial
     * d'une ligne vide, cohérent avec son propre ->default(20).
     */
    private static function tauxTvaActuel(Get $get): float
    {
        return (float) ($get('taux_tva') ?? 20);
    }

    private static function ttcDepuisHt(float $ht, float $tauxTva): float
    {
        return round($ht * (1 + $tauxTva / 100), 2);
    }

    private static function htDepuisTtc(float $ttc, float $tauxTva): float
    {
        return round($ttc / (1 + $tauxTva / 100), 2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->searchable(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('date_validite')->label('Validité')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutDevis::class),
            ])
            ->actions([
                // Un devis reste modifiable (lignes comprises) tant qu'il
                // n'a pas été transformé en facture, voir
                // Devis::estModifiable() et DevisPolicy::update().
                Tables\Actions\EditAction::make()
                    ->visible(fn (Devis $record) => $record->estModifiable()),
                Tables\Actions\Action::make('envoyer')
                    ->label('Envoyer')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Brouillon && auth()->user()->can('envoyer', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(EmettreDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('accepter')
                    ->label('Accepter')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('accepter', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(AccepterDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('refuser')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('refuser', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(RefuserDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('convertir')
                    ->label('Convertir en facture')
                    ->icon('heroicon-o-document-currency-euro')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Une nouvelle facture brouillon sera créée avec les mêmes lignes que ce devis.')
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Accepte
                        && $record->factures()->doesntExist()
                        && auth()->user()->can('convertir', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(CreerFactureDepuisDevisAction::class)->handle($record))),
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
            'index' => Pages\ListDevis::route('/'),
            'create' => Pages\CreateDevis::route('/create'),
            'edit' => Pages\EditDevis::route('/{record}/edit'),
        ];
    }
}
