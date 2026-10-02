<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Filament\App\Resources\FactureResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FactureResource extends Resource
{
    protected static ?string $model = Facture::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-euro';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?string $modelLabel = 'facture';

    protected static ?string $pluralModelLabel = 'factures';

    /**
     * Confidentialité par vendeur (voir FacturePolicy) : sans
     * FACTURE_VIEW_TOUTES, la liste elle-même ne doit montrer que les
     * factures créées par l'utilisateur courant -- une policy sur l'accès
     * direct ne suffit pas, un montant visible dans un tableau est déjà
     * une fuite.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Facture')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('date_echeance')->label('Échéance'),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Lignes')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema(DevisResource::ligneSchema())
                    ->columns(6)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->required()
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create'),
            ]),
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
                Tables\Columns\TextColumn::make('montant_paye')->label('Payé')->money('EUR'),
                Tables\Columns\TextColumn::make('creePar.name')->label('Créée par')->toggleable(),
                Tables\Columns\TextColumn::make('date_echeance')->label('Échéance')->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutFacture::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon),
                Tables\Actions\Action::make('emettre')
                    ->label('Émettre')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Un numéro définitif sera attribué et le stock sera décrémenté. Cette action est irréversible.')
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon && auth()->user()->can('emettre', $record))
                    ->action(fn (Facture $record) => app(EmettreFactureAction::class)->handle($record)),
                Tables\Actions\Action::make('telecharger_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Facture $record) => $record->statut !== StatutFacture::Brouillon)
                    ->action(function (Facture $record) {
                        $generateur = app(FacturePdfGenerator::class);

                        return response()->streamDownload(
                            fn () => print ($generateur->generer($record)->output()),
                            $generateur->nomFichier($record),
                        );
                    }),
                Tables\Actions\Action::make('annuler')
                    ->label('Annuler')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('motif')->label('Motif de l\'annulation')->required(),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Facture $record) => $record->statut->peutTransitionnerVers(StatutFacture::Annulee)
                        && auth()->user()->can('annuler', $record))
                    ->action(fn (Facture $record, array $data) => app(AnnulerFactureAction::class)->handle($record, $data['motif'])),
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
            'index' => Pages\ListFactures::route('/'),
            'create' => Pages\CreateFacture::route('/create'),
            'edit' => Pages\EditFacture::route('/{record}/edit'),
        ];
    }
}
