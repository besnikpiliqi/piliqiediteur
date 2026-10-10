<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Filament\App\Resources\AvoirResource\Pages;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
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
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Exists;

/**
 * Création possible de deux façons : depuis l'action rapide "Avoir" de
 * FactureResource (pour le cas simple d'une seule facture, pré-rempli
 * depuis celle-ci), ou depuis cette Resource (pour regrouper des lignes de
 * plusieurs documents d'un même client, tous tickets de caisse ou tous
 * factures, voir le docblock de CreerAvoirAction). Les deux créent et
 * émettent l'avoir d'un seul geste (voir EnregistrerAvoirAction). Jamais
 * de page edit : un avoir émis ne se modifie ni ne se supprime jamais
 * (voir AvoirPolicy).
 */
class AvoirResource extends Resource
{
    use EnvoieLesDocuments;
    use GereLesExceptionsMetier;
    use SaisitUneQuantiteRetournee;

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
                // Archivé, un client n'est plus proposé pour une vente, mais
                // ce qu'il a acheté se retourne toujours (voir
                // Client::scopeQuiPeuventEtreCredites()).
                ->options(fn (): array => Client::query()->quiPeuventEtreCredites()->orderBy('nom')->pluck('nom', 'id')->all())
                ->rule(fn (): Exists => Client::regleExisteDansLeMagasin(Client::query()->quiPeuventEtreCredites()))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (?int $state, Set $set): void {
                    $series = $state ? self::seriesCreditablesPourClient($state) : [];
                    $serie = count($series) === 1 ? array_key_first($series) : null;

                    $set('serie', $serie);
                    $set('lignes', $serie ? self::lignesCreditablesPourClient($state, TypeDocument::from($serie)) : []);
                })
                ->columnSpanFull(),

            // Choisie d'office quand le client n'a de lignes créditables que
            // dans une série ; sinon le magasin choisit avant que les lignes
            // ne soient proposées (voir CreerAvoirAction, qui refuse tout
            // mélange).
            Radio::make('serie')
                ->label('Documents à créditer')
                ->helperText('Un avoir crédite des tickets de caisse ou des factures, jamais les deux : faites un avoir pour chacun.')
                ->options(fn (Get $get): array => self::seriesCreditablesPourClient((int) $get('client_id')))
                ->visible(fn (Get $get): bool => filled($get('client_id')) && self::seriesCreditablesPourClient((int) $get('client_id')) !== [])
                ->inline()
                ->inlineLabel(false)
                ->required()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $set('lignes', $state ? self::lignesCreditablesPourClient((int) $get('client_id'), TypeDocument::from($state)) : []))
                ->columnSpanFull(),

            Repeater::make('lignes')
                ->label('Lignes à créditer')
                ->helperText('Toutes les lignes encore créditables de ces documents. Retirez celles à ne pas créditer.')
                ->schema([
                    Hidden::make('ligne_facture_id'),
                    TextInput::make('facture_numero')->label('Document')->disabled()->dehydrated(false),
                    TextInput::make('description')->label('Produit / description')->disabled()->dehydrated(false),
                    self::champQuantiteRetournee('Quantité à créditer', fn (Get $get): int => LigneFacture::find($get('ligne_facture_id'))?->quantiteCreditableRestante() ?? 0),
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
                FactureResource::clientEntry(),
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
                FactureResource::lignesEntry($infolist->getRecord()),
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

            self::envoisSection(),
        ]);
    }

    /**
     * Les séries (tickets de caisse, factures) dans lesquelles le client a
     * encore des lignes créditables, avec leur libellé.
     *
     * @return array<string, string>
     */
    private static function seriesCreditablesPourClient(int $clientId): array
    {
        $seriesPresentes = self::lignesCreditables($clientId)->map(fn (LigneFacture $ligne): TypeDocument => $ligne->facture->serie());

        return collect([TypeDocument::TicketCaisse->value => 'Tickets de caisse', TypeDocument::Facture->value => 'Factures'])
            ->filter(fn (string $libelle, string $serie): bool => $seriesPresentes->contains(TypeDocument::from($serie)))
            ->all();
    }

    /**
     * @return list<array{ligne_facture_id: int, facture_numero: ?string, description: string, quantite: int}>
     */
    private static function lignesCreditablesPourClient(int $clientId, TypeDocument $serie): array
    {
        return self::lignesCreditables($clientId)
            ->filter(fn (LigneFacture $ligne): bool => $ligne->facture->serie() === $serie)
            ->map(fn (LigneFacture $ligne): array => [
                'ligne_facture_id' => $ligne->id,
                'facture_numero' => $ligne->facture->numero,
                'description' => $ligne->description,
                'quantite' => $ligne->quantiteCreditableRestante(),
            ])
            ->values()
            ->all();
    }

    /**
     * Les lignes des documents émis du client qu'aucun avoir n'a encore
     * entièrement créditées.
     *
     * @return Collection<int, LigneFacture>
     */
    private static function lignesCreditables(int $clientId): Collection
    {
        return LigneFacture::query()
            ->creditables()
            ->whereHas('facture', fn (Builder $query) => $query->emises()->where('client_id', $clientId))
            ->with('facture')
            ->get();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->rechercheSansAccents(),
                FactureResource::clientColumn(),
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
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
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
