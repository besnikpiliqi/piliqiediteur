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
use App\Filament\App\Resources\DevisResource\Pages;
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
    protected static ?string $model = Devis::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Ventes';

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
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE))),
                DatePicker::make('date_validite')->label('Valide jusqu\'au'),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Lignes')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema(self::ligneSchema())
                    ->columns(6)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->required()
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create'),
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
                }),
            TextInput::make('description')->required()->columnSpan(2),
            TextInput::make('quantite')->numeric()->required()->default(1)->live(onBlur: true),
            TextInput::make('prix_unitaire_ht')->label('PU HT')->numeric()->required()->live(onBlur: true),
            TextInput::make('taux_tva')->label('TVA %')->numeric()->required()->default(20)->live(onBlur: true),
            TextInput::make('remise_pourcentage')->label('Remise %')->numeric()->default(0)->live(onBlur: true),
            Placeholder::make('total_ligne')
                ->label('Total TTC')
                ->columnSpanFull()
                ->content(function (Get $get): string {
                    $ht = (float) ($get('prix_unitaire_ht') ?? 0) * (float) ($get('quantite') ?? 0);
                    $ht *= 1 - ((float) ($get('remise_pourcentage') ?? 0) / 100);
                    $ttc = $ht * (1 + ((float) ($get('taux_tva') ?? 0) / 100));

                    return number_format($ttc, 2, ',', ' ').' €';
                }),
        ];
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('envoyer')
                    ->label('Envoyer')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Brouillon && auth()->user()->can('envoyer', $record))
                    ->action(fn (Devis $record) => app(EmettreDevisAction::class)->handle($record)),
                Tables\Actions\Action::make('accepter')
                    ->label('Accepter')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('accepter', $record))
                    ->action(fn (Devis $record) => app(AccepterDevisAction::class)->handle($record)),
                Tables\Actions\Action::make('refuser')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('refuser', $record))
                    ->action(fn (Devis $record) => app(RefuserDevisAction::class)->handle($record)),
                Tables\Actions\Action::make('convertir')
                    ->label('Convertir en facture')
                    ->icon('heroicon-o-document-currency-euro')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Une nouvelle facture brouillon sera créée avec les mêmes lignes que ce devis.')
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Accepte
                        && $record->factures()->doesntExist()
                        && auth()->user()->can('convertir', $record))
                    ->action(fn (Devis $record) => app(CreerFactureDepuisDevisAction::class)->handle($record)),
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
