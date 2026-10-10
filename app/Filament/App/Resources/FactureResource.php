<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Facades\Filament;
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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FactureResource extends Resource
{
    use EnvoieLesDocuments;
    use GereLesExceptionsMetier;
    use SaisitUneQuantiteRetournee;
    use SupprimeEnMasseLigneParLigne;

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
        // withSum évite un N+1 sur montantPaye()/montantCrediteParAvoir()
        // pour chaque ligne du tableau.
        $query = parent::getEloquentQuery()
            ->with('client')
            ->withSum('allocationsPaiement as montant_paye_somme', 'montant_alloue')
            ->withSum('allocationsAvoir as montant_credite_somme', 'montant_alloue');

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
                    // Un client archivé ne reçoit plus de nouveau document,
                    // même si ceux qu'il a déjà le gardent (voir
                    // Facture::client() et AppServiceProvider::limiterLesChoixAuMagasin()).
                    ->relationProposable('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id')))
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE))),
                // L'échéance ne se saisit pas : elle se calcule à l'émission,
                // date d'émission + délai du client choisi, sinon délai du
                // magasin (B7, voir EmettreFactureAction).
                Placeholder::make('echeance')
                    ->label('Échéance')
                    ->content(fn (Get $get): string => self::echeanceAnnoncee($get('client_id'))),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            FormSection::make('Lignes')->schema([
                // Lignes modifiables tant que la facture n'est pas émise :
                // cette page n'est accessible qu'à un brouillon (voir
                // FacturePolicy::update()), qui n'a aucune existence légale
                // à préserver.
                ...DevisResource::lignesDeVente(),
                DevisResource::totauxDuDocument(),
            ]),
        ]);
    }

    /**
     * L'échéance qu'aura la facture : la date de son émission plus le
     * délai du client choisi, sinon celui du magasin (voir
     * Magasin::delaiDePaiementPour()). Le client est lu dans le magasin
     * actif, archivé compris pour un brouillon qui le garde.
     */
    private static function echeanceAnnoncee(mixed $clientId): string
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();
        $delai = $magasin->delaiDePaiementPour(blank($clientId) ? null : Client::withTrashed()->find($clientId));

        return 'Fixée à l\'émission : date d\'émission + '.$delai.' jour'.($delai > 1 ? 's' : '');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Facture')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore émise)'),
                TextEntry::make('statut')->badge(),
                self::clientEntry(),
                TextEntry::make('date_emission')->label('Émise le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_echeance')->label('Échéance')->date('d/m/Y')->placeholder('Fixée à l\'émission'),
                TextEntry::make('creePar.name')->label('Créée par'),
            ])->columns(3),

            InfolistSection::make('Lignes')->schema([
                self::lignesEntry($infolist->getRecord()),
            ]),

            InfolistSection::make('Totaux et règlement')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                self::ventilationTvaEntry(),
                // Un avoir n'est jamais remboursé par ce logiciel : son
                // crédit réduit le solde dû exactement comme un
                // encaissement (voir Facture::montantRestant()), c'est
                // d'ailleurs lui qui explique le statut "Partiellement
                // payée"/"Payée" d'une facture jamais réellement encaissée.
                // Le séparer du "Payé" donnerait l'impression contradictoire
                // d'une facture "partiellement payée" à 0 € payé (même
                // raisonnement que la colonne "Payé" de la liste) ; le détail
                // "Crédité par avoir" ci-dessous garde la traçabilité de la
                // part qui vient spécifiquement d'un avoir.
                TextEntry::make('montant_paye')
                    ->label('Payé')
                    ->state(fn (Facture $record) => app(CalculateurLigne::class)->sommer([$record->montantPaye(), $record->montantCrediteParAvoir()]))
                    ->money('EUR'),
                TextEntry::make('montant_credite_par_avoir')
                    ->label('Crédité par avoir')
                    ->state(fn (Facture $record) => $record->montantCrediteParAvoir())
                    ->money('EUR')
                    ->visible(fn (Facture $record) => $record->montantCrediteParAvoir() > 0),
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

            InfolistSection::make('Crédité par avoir')
                ->visible(fn (Facture $record) => $record->allocationsAvoir()->exists())
                ->schema([
                    RepeatableEntry::make('allocationsAvoir')
                        ->label('')
                        ->schema([
                            TextEntry::make('avoir.numero')
                                ->label('Avoir')
                                ->url(fn (AvoirAllocation $record) => AvoirResource::getUrl('view', ['record' => $record->avoir]))
                                ->color('primary'),
                            TextEntry::make('montant_alloue')->label('Montant crédité')->money('EUR'),
                        ])
                        ->columns(2),
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
                // Une facture peut recevoir plusieurs avoirs successifs,
                // chacun partiel (voir CreerAvoirAction).
                ->visible(fn (Facture $record) => $record->avoirsEmisConcernes()->exists())
                ->schema([
                    RepeatableEntry::make('avoirsEmisListe')
                        ->label('')
                        // Un avoir peut couvrir plusieurs factures (voir
                        // CreerAvoirAction) : seules ses lignes qui
                        // créditent CETTE facture -- et leur total -- ont
                        // leur place ici.
                        ->state(fn (Facture $record) => $record->avoirsEmisConcernes()->get()->each(
                            fn (Avoir $avoir) => $avoir->setRelation('lignes', $avoir->lignes()
                                ->whereHas('ligneFacture', fn ($query) => $query->where('facture_id', $record->id))
                                ->get()),
                        ))
                        ->schema([
                            // ::make('x') seul ne fonctionne pas ici : une
                            // fois le nom imbriqué sous un RepeatableEntry
                            // nourri par un state() arbitraire (pas une
                            // vraie relation Eloquent du modèle racine), le
                            // chemin résolu par Filament ("avoirsEmisListe.
                            // {clé}.x") n'a aucun sens appliqué à un Avoir,
                            // et renvoie toujours vide -- state() explicite,
                            // qui reçoit directement le modèle de l'item,
                            // contourne complètement ce calcul de chemin.
                            TextEntry::make('numero')
                                ->label('Numéro')
                                ->state(fn (Avoir $record) => $record->numero)
                                ->url(fn (Avoir $record) => AvoirResource::getUrl('view', ['record' => $record]))
                                ->color('primary'),
                            TextEntry::make('motif')->label('Motif')->state(fn (Avoir $record) => $record->motif)->placeholder('—'),
                            TextEntry::make('total_ttc')
                                ->label('Crédité sur cette facture (TTC)')
                                ->state(fn (Avoir $record) => app(CalculateurLigne::class)->sommer($record->lignes->pluck('montant_ttc')))
                                ->money('EUR'),
                            // Le résumé (numéro/motif/total) ne dit pas CE QUI
                            // a été retourné : le détail des lignes créditées
                            // (produit, quantité, montant) doit être visible
                            // directement ici, pas seulement sur la page
                            // dédiée de l'avoir.
                            RepeatableEntry::make('lignes')
                                ->label('Produits retournés')
                                ->columnSpanFull()
                                ->state(fn (Avoir $record) => $record->lignes)
                                ->schema([
                                    TextEntry::make('description')->state(fn (LigneAvoir $record) => $record->description),
                                    TextEntry::make('quantite')->label('Quantité')->state(fn (LigneAvoir $record) => $record->quantite)->numeric(),
                                    TextEntry::make('montant_ht')->label('Total HT')->state(fn (LigneAvoir $record) => $record->montant_ht)->money('EUR'),
                                    TextEntry::make('montant_tva')->label('TVA')->state(fn (LigneAvoir $record) => $record->montant_tva)->money('EUR'),
                                    TextEntry::make('montant_ttc')->label('Total TTC')->state(fn (LigneAvoir $record) => $record->montant_ttc)->money('EUR'),
                                ])
                                ->columns(5),
                        ])
                        ->columns(3),
                ]),

            self::envoisSection(),
        ]);
    }

    /**
     * Lignes d'un document (facture, avoir, devis, facture d'achat, avoir
     * fournisseur) : pour chaque article son PU HT, son PU TTC et son taux,
     * puis le HT, la TVA et le TTC de la ligne (voir CalculateurLigne). Un
     * achat n'a pas de PU TTC : sa TVA se calcule sur le HT de la ligne. La
     * remise n'apparaît que si une ligne du document en a une : c'est elle
     * qui explique alors que PU x quantité diffère du total. Sur un
     * document de vente à un professionnel, calculé depuis le HT de chaque
     * ligne, la note de calcul de ses PDF l'explique aussi (voir
     * MentionsFiscales::calcul()).
     */
    public static function lignesEntry(?Model $document): RepeatableEntry
    {
        $avecRemise = $document?->lignes->contains(fn (Model $ligne): bool => (float) $ligne->remise_pourcentage != 0) ?? false;
        $estUnAchat = $document instanceof FactureAchat || $document instanceof AvoirFournisseur;

        return RepeatableEntry::make('lignes')
            ->label('')
            ->helperText($document instanceof Facture || $document instanceof Avoir || $document instanceof Devis ? app(MentionsFiscales::class)->calcul($document) : null)
            ->schema([
                TextEntry::make('description'),
                TextEntry::make('quantite')->label('Quantité')->numeric(),
                TextEntry::make('prix_unitaire_ht')->label('PU HT')->money('EUR'),
                TextEntry::make('prix_unitaire_ttc')->label('PU TTC')->money('EUR')->hidden($estUnAchat),
                TextEntry::make('taux_tva')->label('Taux TVA')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                TextEntry::make('remise_pourcentage')->label('Remise')->numeric(maxDecimalPlaces: 2)->suffix(' %')->visible($avecRemise),
                TextEntry::make('montant_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('montant_tva')->label('TVA')->money('EUR'),
                TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
            ])
            ->columns(($avecRemise ? 9 : 8) - ($estUnAchat ? 1 : 0));
    }

    /**
     * Le nom du client (voir FigeLeClient::nomDuClient()) : celui figé à
     * l'émission, celui de la fiche tant que le document est un brouillon.
     * Un document numéroté dont la copie a été effacée en base s'affiche
     * quand même, sans nom. Partagé par les fiches des factures et des
     * avoirs.
     */
    public static function clientEntry(): TextEntry
    {
        return TextEntry::make('client_nom')
            ->label('Client')
            ->state(fn (Facture|Avoir $record): ?string => $record->nomDuClient())
            ->placeholder('—');
    }

    /**
     * La même colonne dans les listes des factures et des avoirs ; la
     * recherche, sans casse ni accents, trouve le nom imprimé sur le
     * document comme celui de la fiche du client aujourd'hui.
     */
    public static function clientColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('client_nom')
            ->label('Client')
            ->state(fn (Facture|Avoir $record): ?string => $record->nomDuClient())
            ->placeholder('—')
            ->searchable(query: RechercheSansAccents::dans('client_nom', 'client.nom'));
    }

    /**
     * Récapitulatif par taux (voir VentileLaTvaParTaux), partagé par les
     * fiches de tous les documents à lignes.
     */
    public static function ventilationTvaEntry(): RepeatableEntry
    {
        return RepeatableEntry::make('ventilation_tva')
            ->label('TVA par taux')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('taux_tva')->label('Taux')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                TextEntry::make('montant_ht')->label('Base HT')->money('EUR'),
                TextEntry::make('montant_tva')->label('TVA')->money('EUR'),
                TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
            ])
            ->columns(4);
    }

    /**
     * Biens et services séparés, puis par taux : ce que la déclaration des
     * ventes aux particuliers (e-reporting) distingue (voir
     * VentileLaTvaParTaux::ventilationParNature()).
     */
    public static function ventilationParNatureEntry(): RepeatableEntry
    {
        return RepeatableEntry::make('ventilation_par_nature')
            ->label('Biens et services')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('nature')->label('Nature'),
                TextEntry::make('taux_tva')->label('Taux')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                TextEntry::make('montant_ht')->label('Base HT')->money('EUR'),
                TextEntry::make('montant_tva')->label('TVA')->money('EUR'),
                TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
            ])
            ->columns(5);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                self::clientColumn(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                // Un avoir n'est jamais remboursé par ce logiciel : son
                // crédit réduit le solde dû exactement comme un encaissement
                // (voir Facture::montantRestant()), c'est d'ailleurs lui qui
                // explique le statut "Partiellement payée"/"Payée" d'une
                // facture jamais réellement encaissée. Le séparer du "Payé"
                // ici donnerait l'impression contradictoire d'une facture
                // "partiellement payée" à 0 € payé.
                Tables\Columns\TextColumn::make('montant_paye_somme')
                    ->label('Payé')
                    ->state(fn (Facture $record) => app(CalculateurLigne::class)->sommer([$record->montant_paye_somme, $record->montant_credite_somme]))
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
                // et supprimable (voir FacturePolicy), sa suppression
                // inscrite au journal des événements (voir
                // Facture::delete()). Dès l'émission, ces deux actions
                // disparaissent définitivement : une facture émise se
                // corrige par un avoir, jamais en la modifiant ou en la
                // supprimant.
                Tables\Actions\EditAction::make()
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon)
                    ->using(fn (Facture $record): ?bool => static::executerOuInterrompre(fn (): ?bool => $record->delete())),
                Tables\Actions\Action::make('emettre')
                    ->label('Émettre')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Un numéro définitif sera attribué, l\'échéance fixée et le stock décrémenté. Cette action est irréversible : une facture émise ne se corrige plus que par un avoir.')
                    ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon && auth()->user()->can('emettre', $record))
                    ->action(fn (Facture $record) => static::executerSansCasser(fn () => app(EmettreFactureAction::class)->handle($record))),
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
                            // Un encaissement ne doit jamais dépasser le
                            // solde dû : un éventuel trop-perçu ne devient
                            // pas un crédit (contrairement à un avoir) --
                            // le vendeur doit saisir au plus le restant dû,
                            // mais peut librement saisir moins (acompte).
                            ->maxValue(fn (Facture $record) => $record->montantRestant())
                            ->default(fn (Facture $record) => $record->montantRestant()),
                        TextInput::make('reference')->label('Référence')->maxLength(100),
                        DatePicker::make('recu_le')
                            ->label('Reçu le')
                            ->required()
                            ->default(today())
                            ->maxDate(today())
                            ->validationMessages(['before_or_equal' => 'Un paiement ne peut pas être reçu à une date future.']),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Facture $record) => $record->montantRestant() > 0
                        && $record->statut->attendUnReglement()
                        && auth()->user()->can(PermissionRegistry::PAIEMENT_CREER))
                    ->action(fn (Facture $record, array $data) => static::executerSansCasser(fn () => app(EnregistrerPaiementAction::class)->handle($record->client, [
                        'mode_paiement' => $data['mode_paiement'],
                        'montant' => $data['montant'],
                        'reference' => $data['reference'] ?? null,
                        'recu_le' => $data['recu_le'],
                    ], [
                        ['facture_id' => $record->id, 'montant' => $data['montant']],
                    ]))),
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
                self::relancer(Tables\Actions\Action::make('relancer')),
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
                                self::champQuantiteRetournee('Quantité à créditer', fn (Get $get): int => LigneFacture::find($get('ligne_facture_id'))?->quantiteCreditableRestante() ?? 0),
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
                    ->visible(fn (Facture $record) => $record->statut !== StatutFacture::Brouillon
                        && $record->possedeDesLignesCreditables()
                        && auth()->user()->can(PermissionRegistry::AVOIR_CREER))
                    ->action(fn (Facture $record, array $data) => static::executerSansCasser(
                        fn () => app(EnregistrerAvoirAction::class)->handle($record->client, $data['lignes'], $data['motif']),
                    )),
            ])
            // Seuls les brouillons partent : une facture déjà émise se
            // corrige par un avoir (voir l'action "avoir" ci-dessus).
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(),
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
