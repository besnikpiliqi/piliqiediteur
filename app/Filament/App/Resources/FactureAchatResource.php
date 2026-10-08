<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\PrixReference;
use App\Filament\App\Resources\FactureAchatResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDAchat;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
    use AjouteDesLignesDAchat;
    use GereLesExceptionsMetier;
    use SaisitUneQuantiteRetournee;

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
                // Un fournisseur se crée dans sa propre page (voir
                // FournisseurResource), jamais depuis une facture d'achat.
                Select::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationship('fournisseur', 'nom')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('numero_fournisseur')
                    ->label('N° de facture du fournisseur')
                    ->helperText('Le numéro propre au fournisseur, pour le rapprochement -- distinct de notre numéro interne.')
                    ->maxLength(60),
                DatePicker::make('date_facture')->label('Date de la facture'),
                TextInput::make('escompte_pourcentage')
                    ->label('Escompte obtenu')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(0)
                    ->helperText('Réduction du fournisseur pour un paiement rapide : elle baisse le coût d\'achat des produits, pas le total de la facture.'),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            FormSection::make('Produits reçus')->schema([
                ...self::lignesDAchat(),
                self::totauxDuDocument(PrixReference::Ht)->label('Total de la facture d\'achat (à payer au fournisseur)'),
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
                TextEntry::make('escompte_pourcentage')
                    ->label('Escompte obtenu')
                    ->numeric(maxDecimalPlaces: 2)
                    ->suffix(' %')
                    ->visible(fn (FactureAchat $record) => (float) $record->escompte_pourcentage > 0),
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

            InfolistSection::make('Avoirs fournisseur émis')
                ->visible(fn (FactureAchat $record) => $record->avoirsFournisseurEmis()->exists())
                ->schema([
                    RepeatableEntry::make('avoirsFournisseurEmisListe')
                        ->label('')
                        ->state(fn (FactureAchat $record) => $record->avoirsFournisseurEmis()->get())
                        ->schema([
                            // ::make('x') seul ne fonctionne pas ici : voir
                            // le commentaire équivalent dans
                            // FactureResource (RepeatableEntry nourri par un
                            // state() arbitraire, pas une vraie relation du
                            // modèle racine -- state() explicite contourne
                            // le chemin cassé que Filament calcule sinon.
                            TextEntry::make('numero')
                                ->label('Numéro')
                                ->state(fn (AvoirFournisseur $record) => $record->numero)
                                ->url(fn (AvoirFournisseur $record) => AvoirFournisseurResource::getUrl('view', ['record' => $record]))
                                ->color('primary'),
                            TextEntry::make('motif')->label('Motif')->state(fn (AvoirFournisseur $record) => $record->motif)->placeholder('—'),
                            TextEntry::make('total_ttc')->label('Total retourné TTC')->state(fn (AvoirFournisseur $record) => $record->total_ttc)->money('EUR'),
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
                    ->action(fn (FactureAchat $record) => static::executerSansCasser(fn () => app(ValiderFactureAchatAction::class)->handle($record))),
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
                                self::champQuantiteRetournee('Quantité à retourner', fn (Get $get): int => LigneAchat::find($get('ligne_achat_id'))?->quantiteRetournableRestante() ?? 0),
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
                    ->action(fn (FactureAchat $record, array $data) => static::executerSansCasser(function () use ($record, $data) {
                        $avoir = app(CreerAvoirFournisseurAction::class)->handle($record, $data['lignes'], $data['motif']);
                        app(EmettreAvoirFournisseurAction::class)->handle($avoir);
                    })),
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
