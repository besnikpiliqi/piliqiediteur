<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Filament\App\Resources\DevisResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDeVente;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SaisitLesPrixDeLigne;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DevisResource extends Resource
{
    use AjouteDesLignesDeVente;
    use GereLesExceptionsMetier;
    use SaisitLesPrixDeLigne;

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
                ...self::lignesDeVente(),
                self::totauxDuDocument(PrixReference::Ttc),
            ]),
        ]);
    }

    /**
     * Partagé avec FactureResource et la Caisse. Une ligne vend un produit
     * du catalogue ou un service du magasin (voir AjouteDesLignesDeVente) :
     * sa description vient du catalogue et ne se corrige que dans la fiche
     * du produit ou du service, et seul le PU TTC se saisit -- le PU HT en
     * est la conversion. Une ligne de service n'a ni produit à chercher ni
     * PU HT affiché.
     *
     * @return array<Component>
     */
    public static function ligneSchema(): array
    {
        return [
            Hidden::make('service_id'),
            Select::make('produit_id')
                ->label('Produit')
                ->helperText('Recherchez par nom, référence, ou scannez le code-barres.')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => ProduitResource::rechercher(Produit::query()->where('actif', true), $search))
                ->getOptionLabelUsing(fn ($value) => Produit::find($value)?->nom)
                ->required(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->live()
                ->columnSpan(2)
                ->visible(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->afterStateUpdated(function (?int $state, Set $set) {
                    $produit = $state ? Produit::find($state) : null;

                    if ($produit === null) {
                        return;
                    }

                    $set('description', $produit->nom);
                    $set('reference', $produit->reference);
                    $set('prix_unitaire_ht', (string) $produit->prix_vente_ht);
                    $set('prix_unitaire_ttc', (string) $produit->prix_vente_ttc);
                    $set('taux_tva', self::pourcentageEnTexte($produit->taux_tva));
                }),
            TextInput::make('description')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) ? 'Service' : 'Description')
                ->disabled()
                // Reprend la place du produit et du PU HT, absents d'une
                // ligne de service.
                ->columnSpan(fn (Get $get): int => self::estUneLigneDeService($get) ? 5 : 2),
            // live() et non live(onBlur: true) sur tous les champs de la
            // ligne : le total (et, dans la Caisse, la monnaie à rendre) se
            // met à jour pendant la saisie, pas seulement au clic ailleurs.
            TextInput::make('quantite')->integer()->minValue(1)->required()->default(1)->saisieEnDirect(),
            ...self::champsPrixDeLigne(),
            TextInput::make('remise_pourcentage')->label('Remise %')->numeric()->minValue(0)->maxValue(100)->default(0)->saisieEnDirect(),
            self::totalDeLigne(),
        ];
    }

    public static function estUneLigneDeService(Get $get): bool
    {
        return filled($get('service_id'));
    }

    /**
     * Un devis transformé en facture n'est plus modifiable : sans cette
     * fiche, ses lignes et ses totaux ne seraient plus visibles nulle part.
     * Mêmes colonnes que la facture (voir FactureResource::lignesEntry()).
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Devis')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore envoyé)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('client.nom')->label('Client'),
                TextEntry::make('date_emission')->label('Envoyé le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_validite')->label('Valide jusqu\'au')->date('d/m/Y')->placeholder('—'),
            ])->columns(3),

            InfolistSection::make('Lignes')->schema([
                FactureResource::lignesEntry($infolist->getRecord()),
            ]),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
            ])->columns(3),
        ]);
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
                Tables\Actions\ViewAction::make(),
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
            'view' => Pages\ViewDevis::route('/{record}'),
            'edit' => Pages\EditDevis::route('/{record}/edit'),
        ];
    }
}
