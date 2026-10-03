<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Filament\App\Resources\FactureResource\Pages;
use Filament\Forms\Components\Actions\Action;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FactureResource extends Resource
{
    protected static ?string $model = Facture::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-euro';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 3;

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
        // withSum évite un N+1 sur montantPaye() pour chaque ligne du tableau.
        $query = parent::getEloquentQuery()->withSum('allocationsPaiement as montant_paye_somme', 'montant_alloue');

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make('Facture')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE))),
                DatePicker::make('date_echeance')->label('Échéance'),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            FormSection::make('Lignes')->schema([
                // Contrairement à Devis, les lignes restent modifiables en
                // édition (voir EditFacture) : cette page n'est accessible
                // que pour un brouillon (voir FacturePolicy::update()), qui
                // n'a aucune existence légale à préserver.
                Repeater::make('lignes')
                    ->label('')
                    ->schema(DevisResource::ligneSchema())
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
            InfolistSection::make('Facture')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore émise)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('client.nom')->label('Client'),
                TextEntry::make('date_emission')->label('Émise le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_echeance')->label('Échéance')->date('d/m/Y')->placeholder('—'),
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

            InfolistSection::make('Totaux et règlement')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                TextEntry::make('montant_paye')->label('Payé')->state(fn (Facture $record) => $record->montantPaye())->money('EUR'),
                TextEntry::make('montant_restant')->label('Restant dû')->state(fn (Facture $record) => $record->montantRestant())->money('EUR'),
            ])->columns(3),

            InfolistSection::make('Paiements reçus')
                ->visible(fn (Facture $record) => $record->allocationsPaiement()->exists())
                ->schema([
                    RepeatableEntry::make('allocationsPaiement')
                        ->label('')
                        ->schema([
                            TextEntry::make('paiement.recu_le')->label('Reçu le')->date('d/m/Y'),
                            TextEntry::make('paiement.mode_paiement')->label('Mode')->badge(),
                            TextEntry::make('montant_alloue')->label('Montant alloué')->money('EUR'),
                            TextEntry::make('paiement.reference')->label('Référence')->placeholder('—'),
                        ])
                        ->columns(4),
                ]),

            InfolistSection::make('Transmission électronique')->schema([
                TextEntry::make('transmission_pdp_particulier')
                    ->label('Circuit réglementaire')
                    ->state("Client particulier : cette facture est incluse dans l'e-reporting agrégé de la prochaine clôture de caisse journalière, pas de transmission PDP individuelle.")
                    ->visible(fn (Facture $record) => ! $record->relevantDeLeInvoicing()),
                TextEntry::make('transmission_pdp_statut')
                    ->label('Statut de transmission au PDP')
                    ->state(fn (Facture $record) => $record->transmissionsPdp->sortByDesc('soumise_le')->first()?->statut)
                    ->badge()
                    ->placeholder('Pas encore transmise')
                    ->visible(fn (Facture $record) => $record->relevantDeLeInvoicing()),
                TextEntry::make('transmission_pdp_erreur')
                    ->label('Détail')
                    ->state(fn (Facture $record) => $record->transmissionsPdp->sortByDesc('soumise_le')->first()?->message_erreur)
                    ->visible(fn (Facture $record) => filled($record->transmissionsPdp->sortByDesc('soumise_le')->first()?->message_erreur)),
            ]),

            InfolistSection::make('Avoirs émis')
                // Un avoir peut être partiel (voir CreerAvoirAction) : une
                // facture peut donc avoir reçu plusieurs avoirs successifs,
                // pas seulement un unique avoir total.
                ->visible(fn (Facture $record) => $record->avoirsEmis()->exists())
                ->schema([
                    RepeatableEntry::make('avoirsEmisListe')
                        ->label('')
                        ->state(fn (Facture $record) => $record->avoirsEmis()->get())
                        ->schema([
                            TextEntry::make('numero')->label('Numéro'),
                            TextEntry::make('motif')->label('Motif')->placeholder('—'),
                            TextEntry::make('total_ttc')->label('Total crédité TTC')->money('EUR'),
                        ])
                        ->columns(3),
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
                Tables\Columns\TextColumn::make('montant_paye_somme')
                    ->label('Payé')
                    ->state(fn (Facture $record) => round((float) ($record->montant_paye_somme ?? 0), 2))
                    ->money('EUR'),
                Tables\Columns\TextColumn::make('creePar.name')->label('Créée par')->toggleable(),
                Tables\Columns\TextColumn::make('date_echeance')->label('Échéance')->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutFacture::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Un brouillon n'a jamais reçu de numéro légal : il reste
                // librement modifiable (lignes comprises, voir EditFacture)
                // et supprimable (voir FacturePolicy). Dès l'émission, ces
                // deux actions disparaissent définitivement : une facture
                // émise se corrige par un avoir, jamais en la modifiant ou
                // en la supprimant.
                Tables\Actions\EditAction::make()
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon),
                Tables\Actions\Action::make('emettre')
                    ->label('Émettre')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Un numéro définitif sera attribué et le stock sera décrémenté. Cette action est irréversible.')
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon && auth()->user()->can('emettre', $record))
                    ->action(fn (Facture $record) => app(EmettreFactureAction::class)->handle($record)),
                Tables\Actions\Action::make('encaisser')
                    ->label('Encaisser')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        Select::make('mode_paiement')
                            ->label('Mode de paiement')
                            ->options(ModePaiement::class)
                            ->required(),
                        TextInput::make('montant')
                            ->label('Montant reçu')
                            ->numeric()
                            ->required()
                            ->default(fn (Facture $record) => $record->montantRestant()),
                        TextInput::make('reference')->label('Référence'),
                        DatePicker::make('recu_le')->label('Reçu le')->required()->default(now()),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Facture $record) => $record->montantRestant() > 0
                        && in_array($record->statut, [
                            StatutFacture::Emise,
                            StatutFacture::Envoyee,
                            StatutFacture::PartiellementPayee,
                            StatutFacture::EnRetard,
                        ], true)
                        && auth()->user()->can(PermissionRegistry::PAIEMENT_CREER))
                    ->action(fn (Facture $record, array $data) => app(EnregistrerPaiementAction::class)->handle([
                        'client_id' => $record->client_id,
                        'mode_paiement' => $data['mode_paiement'],
                        'montant' => $data['montant'],
                        'reference' => $data['reference'] ?? null,
                        'recu_le' => $data['recu_le'],
                    ], [
                        ['facture_id' => $record->id, 'montant' => $data['montant']],
                    ])),
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
                Tables\Actions\Action::make('avoir')
                    ->label('Avoir')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->form(fn (Facture $record) => [
                        // Sélection des lignes/quantités à créditer plutôt
                        // qu'un crédit total forcé : un avoir partiel est
                        // légal (voir le docblock de CreerAvoirAction).
                        // Pré-rempli avec la quantité restante créditable
                        // de chaque ligne ; retirer une ligne ou réduire sa
                        // quantité exclut le reste du crédit.
                        Repeater::make('lignes')
                            ->label('Lignes à créditer')
                            ->schema([
                                Hidden::make('ligne_facture_id'),
                                TextInput::make('description')
                                    ->label('Produit / description')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('quantite')
                                    ->label('Quantité à créditer')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001)
                                    ->maxValue(fn (Get $get) => LigneFacture::find($get('ligne_facture_id'))?->quantiteCreditableRestante() ?? 0),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->deletable(true)
                            ->default(
                                $record->lignes
                                    ->map(fn (LigneFacture $ligne) => [
                                        'ligne_facture_id' => $ligne->id,
                                        'description' => $ligne->description,
                                        'quantite' => $ligne->quantiteCreditableRestante(),
                                    ])
                                    ->filter(fn (array $ligne) => $ligne['quantite'] > 0)
                                    ->values()
                                    ->all()
                            ),
                        Textarea::make('motif')->label('Motif de l\'avoir')->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Seules les lignes et quantités conservées ci-dessous seront créditées, et leur stock restauré. Retirez les lignes à ne pas créditer. Cette action est irréversible.')
                    ->visible(fn (Facture $record) => in_array($record->statut, [
                        StatutFacture::Emise,
                        StatutFacture::Envoyee,
                        StatutFacture::PartiellementPayee,
                        StatutFacture::Payee,
                        StatutFacture::EnRetard,
                    ], true)
                        && $record->possedeDesLignesCreditables()
                        && auth()->user()->can(PermissionRegistry::AVOIR_CREER))
                    ->action(function (Facture $record, array $data) {
                        $avoir = app(CreerAvoirAction::class)->handle($record, $data['lignes'], $data['motif']);
                        app(EmettreAvoirAction::class)->handle($avoir);
                    }),
            ])
            // Pour corriger une erreur déjà émise, on émet un avoir (voir
            // l'action "avoir" ci-dessus et AvoirResource) : la suppression
            // en masse ne doit jamais pouvoir toucher une facture déjà
            // numérotée. DeleteBulkAction de Filament ne revérifie PAS la
            // policy par enregistrement sélectionné (seulement au niveau du
            // bouton via deleteAny()) : sans ce filtre explicite, un
            // utilisateur pourrait sélectionner un mélange de brouillons et
            // de factures émises et supprimer ces dernières aussi.
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $records
                                ->filter(fn (Facture $record) => auth()->user()->can('delete', $record))
                                ->each(fn (Facture $record) => $record->delete());
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFactures::route('/'),
            'create' => Pages\CreateFacture::route('/create'),
            'view' => Pages\ViewFacture::route('/{record}'),
            'edit' => Pages\EditFacture::route('/{record}/edit'),
        ];
    }
}
