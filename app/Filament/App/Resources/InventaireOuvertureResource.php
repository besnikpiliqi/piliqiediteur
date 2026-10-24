<?php

namespace App\Filament\App\Resources;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Services\ConstructeurLigneInventaire;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use Filament\Actions\DeleteAction;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Exists;

/**
 * L'inventaire d'ouverture du magasin : le stock existant à son
 * démarrage, avec son coût, saisi à la main (le comptage, les ajustements
 * et l'import viendront à l'étape 7). Avec la facture d'achat validée,
 * c'est le seul document dont un produit peut naître (voir
 * OrigineDesProduits). Il se saisit en brouillon, puis se valide une fois
 * pour toutes (voir ValiderInventaireOuvertureAction), jusqu'à la première
 * vente du magasin, par une personne autorisée à valider les achats et à
 * créer des produits (voir InventaireOuverturePolicy). Le menu disparaît
 * après la première vente, sauf pour consulter les inventaires déjà
 * saisis.
 */
class InventaireOuvertureResource extends Resource
{
    use GereLesExceptionsMetier;
    use PorteUnJeton;

    protected static ?string $model = InventaireOuverture::class;

    protected static ?string $slug = 'inventaires-ouverture';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Inventaire d\'ouverture';

    protected static ?string $modelLabel = 'inventaire d\'ouverture';

    protected static ?string $pluralModelLabel = 'inventaires d\'ouverture';

    public const AIDE = 'Le stock déjà en magasin au démarrage, avec son coût d\'achat hors taxe. La validation crée les nouveaux produits et fait entrer leur stock à ce coût, sans TVA ni fournisseur ; un produit qui ne suit pas son stock ne reçoit que ce coût. L\'inventaire d\'ouverture se saisit jusqu\'à la première vente du magasin ; un produit oublié entre ensuite par une facture d\'achat.';

    public const AIDE_SANS_SUIVI_DU_STOCK = 'Ne suit pas son stock : rien n\'entre en stock, le produit ne reçoit que son coût de départ.';

    /**
     * Le menu reste tant que le magasin n'a pas vendu, puis seulement pour
     * consulter les inventaires déjà saisis.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $contexte = app(TenantContext::class);

        return parent::shouldRegisterNavigation()
            && $contexte->has()
            && (! $contexte->magasin()->aVendu() || InventaireOuverture::query()->exists());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make('Inventaire d\'ouverture')
                ->description(self::AIDE)
                ->schema([
                    Textarea::make('notes')->columnSpanFull(),
                ]),

            FormSection::make('Produits en stock')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema(self::ligneSchema())
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => self::libelleDeLaLigne($state))
                    ->addActionLabel('Ajouter un produit')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->required()
                    ->validationMessages(['required' => 'Ajoutez au moins un produit à l\'inventaire d\'ouverture.']),
            ]),
        ]);
    }

    /**
     * Une ligne : un produit du catalogue jamais reçu, ou, laissé vide, un
     * nouveau produit dont la fiche se saisit comme celle d'un produit (voir
     * ProduitResource), puis la quantité en stock et son coût unitaire HT.
     *
     * @return list<Field|Group>
     */
    private static function ligneSchema(): array
    {
        $estUnIdentifiant = fn (mixed $valeur): bool => (is_int($valeur) || is_string($valeur)) && filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        return [
            Select::make('produit_id')
                ->label('Produit déjà au catalogue, jamais reçu')
                ->helperText('Laissez vide pour un nouveau produit, créé à la validation de l\'inventaire.')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => ProduitResource::rechercher(Produit::query()->jamaisRecus(), $search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $estUnIdentifiant($value) ? Produit::withTrashed()->find((int) $value)?->nom : null)
                ->rule('integer')
                ->rule(fn (): Exists => Produit::regleExisteDansLeMagasin(Produit::query()->jamaisRecus()), fn (mixed $state): bool => $estUnIdentifiant($state))
                ->validationMessages([
                    'integer' => 'Choisissez un produit du magasin jamais reçu.',
                    'exists' => 'Choisissez un produit du magasin jamais reçu.',
                ])
                ->live()
                ->columnSpanFull(),
            Group::make([
                ...ProduitResource::champsIdentification(fn (): ?Produit => null),
                ...ProduitResource::prixVenteTtcSchema(),
                ...ProduitResource::champsSuiviDuStock(),
            ])
                ->statePath('produit')
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => blank($get('produit_id'))),
            TextInput::make('quantite')
                ->label('Quantité en stock')
                ->integer()
                ->minValue(1)
                ->maxValue(ConstructeurLigneInventaire::QUANTITE_MAXIMUM)
                ->required()
                ->default(1),
            TextInput::make('cout_unitaire_ht')
                ->label('Coût unitaire HT')
                ->helperText('Ce qu\'un article a coûté, hors taxe : le coût moyen de départ du produit.')
                ->numeric()
                ->minValue(0)
                ->maxValue(Caisse::MONTANT_MAXIMUM)
                ->prefix('€')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    private static function libelleDeLaLigne(array $ligne): ?string
    {
        $produitId = $ligne['produit_id'] ?? null;

        if (filled($produitId) && filter_var($produitId, FILTER_VALIDATE_INT) !== false) {
            return Produit::withTrashed()->find((int) $produitId)?->nom;
        }

        return $ligne['produit']['nom'] ?? null;
    }

    /**
     * Une ligne déjà enregistrée, telle que le formulaire la rouvre : son
     * produit jamais reçu, ou la fiche du nouveau produit que le brouillon
     * garde jusqu'à sa validation.
     *
     * @return array<string, mixed>
     */
    public static function ligneDejaEnregistree(LigneInventaireOuverture $ligne): array
    {
        $fiche = $ligne->nouveau_produit ?? [];

        if (isset($fiche['taux_tva'])) {
            $fiche['taux_tva'] = DevisResource::pourcentageEnTexte($fiche['taux_tva']);
        }

        return [
            'produit_id' => $ligne->produit_id,
            'produit' => $fiche,
            'quantite' => $ligne->quantite,
            'cout_unitaire_ht' => $ligne->cout_unitaire_ht,
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Inventaire d\'ouverture')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore validé)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('valeur_ht')->label('Valeur du stock HT')->money('EUR'),
                TextEntry::make('cree_par_nom')->label('Saisi par')->placeholder('—'),
                TextEntry::make('date_validation')->label('Validé le')->dateTime('d/m/Y à H:i')->placeholder('—'),
                TextEntry::make('valide_par_nom')->label('Validé par')->placeholder('—'),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ])->columns(3),

            InfolistSection::make('Produits en stock')->schema([
                RepeatableEntry::make('lignes')
                    ->label('')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence')
                            ->placeholder('Générée à la validation'),
                        TextEntry::make('description')->label('Produit'),
                        TextEntry::make('quantite')->label('Quantité'),
                        TextEntry::make('cout_unitaire_ht')->label('Coût unitaire HT')->money('EUR'),
                        TextEntry::make('valeur_ht')
                            ->label('Valeur du stock HT')
                            ->money('EUR')
                            ->helperText(fn (LigneInventaireOuverture $record): ?string => $record->suivi_stock ? null : self::AIDE_SANS_SUIVI_DU_STOCK),
                    ])
                    ->columns(5),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('valeur_ht')->label('Valeur du stock HT')->money('EUR'),
                Tables\Columns\TextColumn::make('date_validation')->label('Validé le')->dateTime('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('cree_par_nom')->label('Saisi par')->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                self::actionSupprimer(Tables\Actions\DeleteAction::make()),
                self::actionValider(Tables\Actions\Action::make('valider')),
            ])
            // Aucune action groupée : un inventaire validé ne se supprime
            // jamais (voir InventaireOuverturePolicy::delete()).
            ->bulkActions([]);
    }

    /**
     * « Valider l'inventaire », depuis la liste, la fiche ou la page de
     * modification d'un brouillon : la fenêtre porte le jeton de la demande
     * (R3) ; validé, l'inventaire s'ouvre sur sa fiche. Le bouton
     * n'apparaît qu'à qui peut le valider (voir
     * InventaireOuverturePolicy::valider()), jamais après la première
     * vente du magasin ni dans un magasin en lecture seule.
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function actionValider(MountableAction $action): MountableAction
    {
        return $action
            ->label('Valider l\'inventaire')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Valider l\'inventaire d\'ouverture')
            ->modalDescription('Les nouveaux produits seront créés, leur stock entrera au coût saisi et l\'inventaire recevra son numéro définitif. Cette action est irréversible.')
            ->form([self::champDuJeton()])
            ->visible(fn (InventaireOuverture $record): bool => $record->statut === StatutInventaireOuverture::Brouillon && auth()->user()->can('valider', $record))
            ->action(function (InventaireOuverture $record, array $data, MountableAction $action): void {
                static::executerSansCasser(function () use ($record, $data, $action): void {
                    $inventaire = app(ValiderInventaireOuvertureAction::class)->handle(auth()->user(), $record, $data['jeton']);

                    Notification::make()->title("Inventaire d'ouverture {$inventaire->numero} validé.")->success()->send();
                    $action->redirect(self::getUrl('view', ['record' => $inventaire]));
                });
            });
    }

    /**
     * « Supprimer », depuis la liste ou la page de modification d'un
     * brouillon : par l'action du domaine, qui relit l'inventaire sous son
     * verrou (voir SupprimerInventaireOuvertureAction), pour qu'un
     * brouillon validé entre-temps par une autre personne reste validé,
     * et que la page dise pourquoi.
     *
     * @template TAction of DeleteAction|Tables\Actions\DeleteAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function actionSupprimer(DeleteAction|Tables\Actions\DeleteAction $action): DeleteAction|Tables\Actions\DeleteAction
    {
        return $action->using(fn (InventaireOuverture $record): bool => static::executerOuInterrompre(function () use ($record): bool {
            app(SupprimerInventaireOuvertureAction::class)->handle(auth()->user(), $record);

            return true;
        }));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventairesOuverture::route('/'),
            'create' => Pages\CreateInventaireOuverture::route('/create'),
            'view' => Pages\ViewInventaireOuverture::route('/{record}'),
            'edit' => Pages\EditInventaireOuverture::route('/{record}/edit'),
        ];
    }
}
