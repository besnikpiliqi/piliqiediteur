<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\FactureAchatResource\Pages;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section as FormSection;
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
use Illuminate\Database\Eloquent\Collection;

/**
 * Enregistre les factures reçues des fournisseurs (réception de
 * marchandise). Pas de PDF généré ici, contrairement aux documents de
 * vente : le PDF légal est celui du fournisseur, cette Resource ne sert
 * qu'à tracer la réception, le stock et la traçabilité pour un éventuel
 * avoir fournisseur (retour).
 */
class FactureAchatResource extends Resource
{
    protected static ?string $model = FactureAchat::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'facture d\'achat';

    protected static ?string $pluralModelLabel = 'factures d\'achat';

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make('Facture d\'achat')->schema([
                Select::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationship('fournisseur', 'nom')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('nom')->required()->maxLength(150),
                        TextInput::make('siret')->label('SIRET')->maxLength(14),
                    ])
                    ->createOptionModalHeading('Nouveau fournisseur'),
                TextInput::make('numero_fournisseur')
                    ->label('N° de facture du fournisseur')
                    ->helperText('Le numéro propre au fournisseur, pour le rapprochement -- distinct de notre numéro interne.')
                    ->maxLength(60),
                DatePicker::make('date_facture')->label('Date de la facture'),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            FormSection::make('Lignes')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema([
                        Select::make('produit_id')
                            ->label('Produit')
                            ->helperText('Recherchez par nom, référence, ou scannez le code-barres.')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(fn (string $search) => Produit::query()
                                ->where(fn ($query) => $query
                                    ->where('nom', 'like', "%{$search}%")
                                    ->orWhere('reference', 'like', "%{$search}%")
                                    ->orWhere('code_barres', 'like', "%{$search}%"))
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Produit $produit) => [$produit->id => "{$produit->nom} ({$produit->reference})"]))
                            ->getOptionLabelUsing(fn ($value) => Produit::find($value)?->nom)
                            // Form explicitement modelé sur Produit : voir
                            // le commentaire équivalent dans
                            // DevisResource::ligneSchema().
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
                                $set('prix_unitaire_ht', (string) $produit->prix_achat_ht);
                                $set('taux_tva', (string) $produit->taux_tva);
                            }),
                        TextInput::make('description')->required()->columnSpan(2),
                        // live() et non live(onBlur: true) : le total de la
                        // ligne se met à jour pendant la saisie.
                        TextInput::make('quantite')->numeric()->required()->default(1)->live(),
                        TextInput::make('prix_unitaire_ht')->label('PU HT')->numeric()->required()->live(),
                        TextInput::make('taux_tva')->label('TVA %')->numeric()->required()->default(20)->live(),
                        TextInput::make('remise_pourcentage')->label('Remise %')->numeric()->default(0)->live(),
                        Placeholder::make('total_ligne')
                            ->label('Total TTC')
                            ->columnSpanFull()
                            // Voir le commentaire équivalent dans
                            // DevisResource::ligneSchema() : passer par
                            // CalculateurLigne garantit que cet aperçu
                            // correspond exactement au montant réellement
                            // enregistré pour la ligne.
                            ->content(function (Get $get): string {
                                $montants = app(CalculateurLigne::class)->calculer(
                                    (float) ($get('prix_unitaire_ht') ?? 0),
                                    (float) ($get('quantite') ?? 0),
                                    (float) ($get('taux_tva') ?? 0),
                                    (float) ($get('remise_pourcentage') ?? 0),
                                );

                                return number_format($montants['montant_ttc'], 2, ',', ' ').' €';
                            }),
                    ])
                    ->columns(6)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->required(),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Facture d\'achat')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore validée)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('source')->badge(),
                TextEntry::make('fournisseur.nom')->label('Fournisseur'),
                TextEntry::make('numero_fournisseur')->label('N° facture fournisseur')->placeholder('—'),
                TextEntry::make('date_facture')->label('Date facture')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('date_validation')->label('Validée le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('creePar.name')->label('Créée par'),
            ])->columns(3),

            InfolistSection::make('Lignes')->schema([
                RepeatableEntry::make('lignes')
                    ->label('')
                    ->schema([
                        TextEntry::make('description'),
                        TextEntry::make('quantite'),
                        TextEntry::make('prix_unitaire_ht')->label('PU HT')->money('EUR'),
                        TextEntry::make('taux_tva')->label('TVA')->suffix(' %'),
                        TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
                    ])
                    ->columns(5),
            ]),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
            ])->columns(3),

            InfolistSection::make('Avoirs fournisseur émis')
                ->visible(fn (FactureAchat $record) => $record->avoirsFournisseurEmis()->exists())
                ->schema([
                    RepeatableEntry::make('avoirsFournisseurEmisListe')
                        ->label('')
                        ->state(fn (FactureAchat $record) => $record->avoirsFournisseurEmis()->get())
                        ->schema([
                            TextEntry::make('numero')->label('Numéro'),
                            TextEntry::make('motif')->label('Motif')->placeholder('—'),
                            TextEntry::make('total_ttc')->label('Total retourné TTC')->money('EUR'),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->searchable(),
                Tables\Columns\TextColumn::make('fournisseur.nom')->label('Fournisseur')->searchable(),
                Tables\Columns\TextColumn::make('numero_fournisseur')->label('N° fournisseur')->toggleable(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('source')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('date_facture')->label('Date facture')->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutFactureAchat::class),
                Tables\Filters\SelectFilter::make('source')->options(SourceFactureAchat::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Un brouillon reste toujours librement modifiable. Une
                // fois validée, seule une facture saisie manuellement le
                // reste (voir FactureAchat::estModifiable()) -- une
                // récupérée par API ne l'est jamais. La suppression, elle,
                // reste interdite sans exception dès la validation (voir
                // FactureAchatPolicy::delete()).
                Tables\Actions\EditAction::make()
                    ->visible(fn (FactureAchat $record) => $record->estModifiable()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (FactureAchat $record) => $record->statut === StatutFactureAchat::Brouillon),
                Tables\Actions\Action::make('valider')
                    ->label('Valider la réception')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Un numéro définitif sera attribué et le stock des produits sera incrémenté. Cette action est irréversible.')
                    ->visible(fn (FactureAchat $record) => $record->statut === StatutFactureAchat::Brouillon && auth()->user()->can('valider', $record))
                    ->action(fn (FactureAchat $record) => app(ValiderFactureAchatAction::class)->handle($record)),
                Tables\Actions\Action::make('avoir_fournisseur')
                    ->label('Avoir fournisseur')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->form(fn (FactureAchat $record) => [
                        // Sélection des lignes/quantités à retourner : un
                        // produit déjà vendu est automatiquement exclu, voir
                        // LigneAchat::quantiteRetournableRestante().
                        Repeater::make('lignes')
                            ->label('Lignes à retourner')
                            ->schema([
                                Hidden::make('ligne_achat_id'),
                                TextInput::make('description')
                                    ->label('Produit / description')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('quantite')
                                    ->label('Quantité à retourner')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001)
                                    ->maxValue(fn (Get $get) => LigneAchat::find($get('ligne_achat_id'))?->quantiteRetournableRestante() ?? 0),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->deletable(true)
                            ->default(
                                $record->lignes
                                    ->map(fn (LigneAchat $ligne) => [
                                        'ligne_achat_id' => $ligne->id,
                                        'description' => $ligne->description,
                                        'quantite' => $ligne->quantiteRetournableRestante(),
                                    ])
                                    ->filter(fn (array $ligne) => $ligne['quantite'] > 0)
                                    ->values()
                                    ->all()
                            ),
                        Textarea::make('motif')->label('Motif du retour')->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Seules les lignes et quantités conservées ci-dessous seront retournées, et leur stock décrémenté. Un produit déjà vendu à un client ne peut jamais être inclus. Cette action est irréversible.')
                    ->visible(fn (FactureAchat $record) => $record->statut === StatutFactureAchat::Validee
                        && $record->possedeDesLignesRetournables()
                        && auth()->user()->can(PermissionRegistry::AVOIR_FOURNISSEUR_CREER))
                    ->action(function (FactureAchat $record, array $data) {
                        $avoir = app(CreerAvoirFournisseurAction::class)->handle($record, $data['lignes'], $data['motif']);
                        app(EmettreAvoirFournisseurAction::class)->handle($avoir);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $records
                                ->filter(fn (FactureAchat $record) => auth()->user()->can('delete', $record))
                                ->each(fn (FactureAchat $record) => $record->delete());
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFacturesAchat::route('/'),
            'create' => Pages\CreateFactureAchat::route('/create'),
            'view' => Pages\ViewFactureAchat::route('/{record}'),
            'edit' => Pages\EditFactureAchat::route('/{record}/edit'),
        ];
    }
}
