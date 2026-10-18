<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Actions\VerifierNumeroTvaAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\ControleNatureOperation;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages;
use App\Filament\Concerns\AvertitDUneVenteSansStock;
use App\Filament\Concerns\ChoisitLaNatureDeLOperation;
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
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class FactureResource extends Resource
{
    use AvertitDUneVenteSansStock;
    use ChoisitLaNatureDeLOperation;
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
     * Le filtre « À encaisser » de la liste (voir table()).
     */
    public const FILTRE_A_ENCAISSER = 'a_encaisser';

    /**
     * La liste des factures du magasin, filtrée sur celles à encaisser :
     * celle du magasin actif, ou de ce magasin depuis l'espace de la
     * société (voir TableauDeBord).
     */
    public static function urlDesFacturesAEncaisser(?Magasin $magasin = null): string
    {
        return static::getUrl('index', ['tableFilters' => [self::FILTRE_A_ENCAISSER => ['isActive' => true]]], panel: 'app', tenant: $magasin);
    }

    /**
     * Confidentialité entre vendeurs (B16) : la liste elle-même ne montre
     * que les factures que l'utilisateur voit (voir
     * Facture::scopeVisiblesPar()) -- une policy sur l'accès direct ne
     * suffit pas, un montant visible dans un tableau est déjà une fuite.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visiblesPar(auth()->user())
            ->with('client');
    }

    /**
     * Le nombre de factures exonérées dont une preuve manque encore (voir
     * Facture::scopePreuvesAFournir()), parmi celles que l'utilisateur
     * voit (B16) ; aucun badge quand il n'y en a pas.
     */
    public static function getNavigationBadge(): ?string
    {
        $nombre = Facture::query()->visiblesPar(auth()->user())->preuvesAFournir()->count();

        return $nombre === 0 ? null : (string) $nombre;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Factures dont une preuve d\'exonération reste à fournir';
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
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::ajusterLaNatureAuClient($get, $set))
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
                self::champNatureDeLOperation(),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->maxLength(500)->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            self::sectionLivraison(),

            FormSection::make('Lignes')->schema([
                // Lignes modifiables tant que la facture n'est pas émise :
                // cette page n'est accessible qu'à un brouillon (voir
                // FacturePolicy::update()), qui n'a aucune existence légale
                // à préserver.
                ...DevisResource::lignesDeVente(),
                DevisResource::totauxDuDocument(parTaux: true),
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
                TextEntry::make('cree_par_nom')->label('Créée par'),
                self::natureEntry(),
                TextEntry::make('consultation_vies')
                    ->label('Vérification VIES retenue')
                    ->state(fn (Facture $record): ?string => $record->consultationVies?->reference())
                    ->visible(fn (Facture $record): bool => $record->consultation_vies_id !== null),
            ])->columns(3),

            self::livraisonSection(),
            self::preuvesSection(),
            self::detaxeSection(),

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
                    // Un paiement saisi par erreur y figure avec sa
                    // contre-passation, en négatif (voir
                    // ContrePasserPaiementAction).
                    RepeatableEntry::make('allocationsPaiement')
                        ->label('')
                        ->schema([
                            TextEntry::make('nature')
                                ->label('Nature')
                                ->state(fn (PaiementAllocation $record): string => (float) $record->montant_alloue < 0 ? 'Contre-passation' : 'Paiement')
                                ->badge()
                                ->color(fn (string $state): string => $state === 'Contre-passation' ? 'danger' : 'gray'),
                            TextEntry::make('paiement.recu_le')->label('Reçu le')->date('d/m/Y'),
                            TextEntry::make('paiement.mode_paiement')->label('Mode')->badge(),
                            TextEntry::make('montant_alloue')
                                ->label('Montant imputé')
                                ->money('EUR')
                                ->color(fn (PaiementAllocation $record): ?string => (float) $record->montant_alloue < 0 ? 'danger' : null),
                            TextEntry::make('paiement.reference')->label('Référence')->placeholder('—'),
                        ])
                        ->columns(5),
                ]),

            InfolistSection::make('Crédité par avoir')
                ->visible(fn (Facture $record) => $record->allocationsAvoir()->exists())
                ->schema([
                    // Le crédit qu'avait imputé un paiement saisi par
                    // erreur y figure avec sa contre-passation, en négatif
                    // (voir ContrePasserPaiementAction).
                    RepeatableEntry::make('allocationsAvoir')
                        ->label('')
                        ->schema([
                            TextEntry::make('avoir.numero')
                                ->label('Avoir')
                                ->url(fn (AvoirAllocation $record) => AvoirResource::getUrl('view', ['record' => $record->avoir]))
                                ->color('primary'),
                            TextEntry::make('montant_alloue')
                                ->label('Montant crédité')
                                ->money('EUR')
                                ->color(fn (AvoirAllocation $record): ?string => (float) $record->montant_alloue < 0 ? 'danger' : null),
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
     * La nature de l'opération d'une facture ou d'un devis, avec la mention
     * qu'elle imprime (voir NatureOperation).
     */
    public static function natureEntry(): TextEntry
    {
        return TextEntry::make('nature_operation')
            ->label('Nature de l\'opération')
            ->helperText(fn (Facture|Devis $record): ?string => $record->nature_operation->mention());
    }

    /**
     * La livraison d'une facture ou d'un devis qui en demande une (voir
     * NoteSaNatureDOperation::lignesDeLivraison()).
     */
    public static function livraisonSection(): InfolistSection
    {
        return InfolistSection::make('Livraison')
            ->visible(fn (Facture|Devis $record): bool => $record->nature_operation->exigeUneLivraison())
            ->schema([
                TextEntry::make('livraison')
                    ->hiddenLabel()
                    ->state(fn (Facture|Devis $record): array => $record->lignesDeLivraison())
                    ->listWithLineBreaks()
                    ->placeholder('Livraison à renseigner'),
            ]);
    }

    /**
     * Les preuves de l'exonération d'une facture émise d'une nature qui en
     * admet (voir PreuvesRequises) : « Complet », ou ce qui reste à
     * fournir, l'attestation de l'acquéreur avec le jour où elle est
     * attendue ; puis les références enregistrées, relues à chaque
     * affichage, pour qu'une preuve ajoutée depuis la fiche (voir
     * ViewFacture) y paraisse aussitôt.
     */
    private static function preuvesSection(): InfolistSection
    {
        return InfolistSection::make('Preuves de l\'exonération')
            ->description('Les références des documents qui prouvent l\'expédition des biens et justifient l\'exonération. Sans elles, la vente devra être corrigée par un avoir et une nouvelle facture à la TVA française.')
            ->visible(fn (Facture $record): bool => AjouterPreuveOperationAction::refusPour($record) === null)
            ->schema([
                TextEntry::make('etat_des_preuves')
                    ->label('État')
                    ->state(fn (Facture $record): array => self::etatDesPreuves($record))
                    ->listWithLineBreaks()
                    ->color(fn (string $state): string => str_starts_with($state, 'À fournir') ? 'warning' : 'success'),
                RepeatableEntry::make('preuves_enregistrees')
                    ->label('Preuves enregistrées')
                    ->state(fn (Facture $record) => $record->preuvesOperation()->get())
                    ->visible(fn (Facture $record): bool => $record->preuvesOperation()->exists())
                    ->schema([
                        TextEntry::make('type')->label('Type')->state(fn (PreuveOperation $record): string => $record->type->getLabel()),
                        TextEntry::make('reference')->label('Référence')->state(fn (PreuveOperation $record): string => $record->reference),
                        TextEntry::make('date_document')->label('Date du document')->state(fn (PreuveOperation $record) => $record->date_document)->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('emetteur')->label('Établie par')->state(fn (PreuveOperation $record): ?string => $record->emetteur)->placeholder('—'),
                        TextEntry::make('note')
                            ->label('Enregistrée')
                            ->state(fn (PreuveOperation $record): string => 'le '.$record->created_at->format('d/m/Y à H:i').($record->note_par_nom === null ? '' : ' par '.$record->note_par_nom)),
                    ])
                    ->columns(5),
            ]);
    }

    /**
     * Ce que la fiche dit des preuves d'une facture : « Complet », ou une
     * ligne « À fournir » par preuve qui manque, ou rien à fournir pour
     * une vente entièrement corrigée par des avoirs.
     *
     * @return list<string>
     */
    private static function etatDesPreuves(Facture $facture): array
    {
        $preuves = app(PreuvesRequises::class);

        if (! $preuves->concerne($facture)) {
            return ['Aucune preuve à fournir : la vente est entièrement corrigée par des avoirs'];
        }

        $manquantes = $preuves->manquantes($facture);

        return $manquantes === [] ? ['Complet'] : array_map(fn (string $manquante): string => "À fournir : {$manquante}", $manquantes);
    }

    /**
     * Le n° du bordereau de détaxe d'un ticket (voir
     * NoterBordereauDetaxeAction), affiché ici et jamais sur le ticket, qui
     * reproduit le document tel qu'émis : le dernier noté, qui fait foi,
     * avec l'avertissement quand les biens taxés emportés ne dépassent pas
     * 100 € TTC (voir BordereauDetaxe::avertissement()), puis ceux qu'il a
     * remplacés. Relus à chaque affichage, comme les preuves.
     */
    private static function detaxeSection(): InfolistSection
    {
        return InfolistSection::make('Détaxe')
            ->description('Le ticket reste à la TVA française : sa TVA ne se régularise qu\'après le visa du bordereau à la sortie de l\'Union européenne.')
            ->visible(fn (Facture $record): bool => $record->bordereauxDetaxe()->exists())
            ->schema([
                TextEntry::make('bordereau_detaxe')
                    ->label('N° du bordereau de détaxe (BVE)')
                    ->state(fn (Facture $record): ?string => self::bordereauQuiFaitFoi($record)?->numero)
                    ->helperText(fn (Facture $record): ?string => self::bordereauQuiFaitFoi($record)?->avertissement()),
                TextEntry::make('bordereau_detaxe_note')
                    ->label('Noté')
                    ->state(function (Facture $record): ?string {
                        $bordereau = self::bordereauQuiFaitFoi($record);

                        return $bordereau === null ? null : 'le '.$bordereau->created_at->format('d/m/Y à H:i').($bordereau->note_par_nom === null ? '' : ' par '.$bordereau->note_par_nom);
                    }),
                TextEntry::make('bordereaux_detaxe_remplaces')
                    ->label('N° remplacés')
                    ->state(fn (Facture $record): array => $record->bordereauxDetaxe()->get()->slice(0, -1)
                        ->map(fn (BordereauDetaxe $bordereau): string => "{$bordereau->numero}, noté le {$bordereau->created_at->format('d/m/Y à H:i')}")
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->visible(fn (Facture $record): bool => $record->bordereauxDetaxe()->count() > 1),
            ])
            ->columns(3);
    }

    /**
     * Le dernier n° de bordereau de détaxe noté sur le ticket, relu en base,
     * avec qui l'a noté.
     */
    private static function bordereauQuiFaitFoi(Facture $ticket): ?BordereauDetaxe
    {
        return $ticket->bordereauDetaxe()->first()?->setRelation('facture', $ticket);
    }

    /**
     * Lignes d'un document (facture, avoir, devis, facture d'achat, avoir
     * fournisseur) : pour chaque article son PU HT, avec ses décimales
     * utiles (voir CalculateurLigne::prixUnitaireHtExact()), son PU TTC et
     * son taux,
     * puis le HT, la TVA et le TTC de la ligne, tels qu'enregistrés (voir
     * CalculateurLigne). Un achat n'a pas de PU TTC : sa TVA est sa part de
     * celle du total HT de son taux. La remise n'apparaît que si une ligne
     * du document en a une : c'est elle qui explique alors que PU x
     * quantité diffère du total. Sur tout document de vente soumis à la
     * TVA, la note de calcul dit que le HT de chaque taux se calcule sur son
     * TTC et se répartit sur les lignes (voir MentionsFiscales::calcul()).
     * La ligne d'une facture d'achat brouillon
     * qui garde la fiche d'un nouveau produit dit qu'il ne naîtra qu'à sa
     * validation (voir LigneAchat).
     *
     * Une vente sans TVA française (voir
     * ContexteDeVente::documentRetireLaTvaDuPrix()) montre, comme ses PDF,
     * le prix TTC du catalogue de chaque produit et la TVA qui en est
     * retirée, à côté de son taux de 0 % : ce que le client n'a pas payé
     * ne passe pas pour un PU TTC, et le calcul se vérifie (3 x 7,00 € ÷
     * 1,20 = 17,50 €). Un service, saisi sans TVA, n'a ni l'un ni l'autre.
     */
    public static function lignesEntry(?Model $document): RepeatableEntry
    {
        $avecRemise = $document?->lignes->contains(fn (Model $ligne): bool => (float) $ligne->remise_pourcentage != 0) ?? false;
        $estUnAchat = $document instanceof FactureAchat || $document instanceof AvoirFournisseur;
        $estUneVente = $document instanceof Facture || $document instanceof Avoir || $document instanceof Devis;
        $sansTvaFrancaise = $estUneVente && ContexteDeVente::documentRetireLaTvaDuPrix($document);
        // Un produit, ou un service à son prix de référence : un prix du
        // catalogue dont la TVA est retirée (voir lignes.blade.php).
        $prixCatalogueSansTvaFrancaise = fn (Model $ligne): bool => $sansTvaFrancaise && ($ligne->nature === NatureLigne::Bien || (float) $ligne->taux_tva_du_prix > 0);

        return RepeatableEntry::make('lignes')
            ->label('')
            ->helperText($estUneVente ? app(MentionsFiscales::class)->calcul($document) : null)
            ->schema([
                TextEntry::make('description')
                    ->helperText(fn (?Model $record): ?string => $record instanceof LigneAchat && $record->nouveau_produit !== null ? 'Nouveau produit, créé à la validation' : null),
                TextEntry::make('quantite')->label('Quantité')->numeric(),
                TextEntry::make('prix_unitaire_ht')
                    ->label('PU HT')
                    ->formatStateUsing(fn (string $state): string => app(CalculateurLigne::class)->prixUnitaireEnEuros(app(CalculateurLigne::class)->enMillioniemes($state))),
                TextEntry::make('prix_unitaire_ttc')
                    ->label($sansTvaFrancaise ? 'Prix catalogue TTC' : 'PU TTC')
                    ->state(fn (Model $record): mixed => ! $sansTvaFrancaise || $prixCatalogueSansTvaFrancaise($record) ? $record->prix_unitaire_ttc : null)
                    ->placeholder('—')
                    ->money('EUR')
                    ->hidden($estUnAchat),
                TextEntry::make('taux_tva_du_prix')
                    ->label('TVA retirée')
                    ->state(fn (Model $record): mixed => $prixCatalogueSansTvaFrancaise($record) ? $record->taux_tva_du_prix : null)
                    ->placeholder('—')
                    ->numeric(maxDecimalPlaces: 2)
                    ->suffix(' %')
                    ->visible($sansTvaFrancaise),
                TextEntry::make('taux_tva')->label('Taux TVA')->numeric(maxDecimalPlaces: 2)->suffix(' %'),
                TextEntry::make('remise_pourcentage')->label('Remise')->numeric(maxDecimalPlaces: 2)->suffix(' %')->visible($avecRemise),
                TextEntry::make('montant_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('montant_tva')->label('TVA')->money('EUR'),
                TextEntry::make('montant_ttc')->label('Total TTC')->money('EUR'),
            ])
            ->columns(8 + ($avecRemise ? 1 : 0) + ($sansTvaFrancaise ? 1 : 0) - ($estUnAchat ? 1 : 0));
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

    /**
     * Ce que la facture a reçu, paiements et crédit d'avoir compris, écrit
     * comme sa colonne (« 12.50 ») : ses imputations, chargées avec les
     * factures de la liste (voir table()), sommées en centimes (voir
     * Facture::regleEnCentimes()).
     */
    private static function paye(Facture $facture): string
    {
        return app(CalculateurLigne::class)->enDecimal($facture->regleEnCentimes());
    }

    public static function table(Table $table): Table
    {
        return $table
            // Les montants imputés, chargés avec les factures de la liste
            // et sommés en centimes par la colonne « Payé » (jamais une
            // somme que la base ferait sur une colonne décimale), sans une
            // requête par facture. La fiche, qui montre la date, le mode et
            // la référence de chaque paiement, lit ses imputations en
            // entier.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['allocationsPaiement:id,facture_id,montant_alloue', 'allocationsAvoir:id,facture_id,montant_alloue']))
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
                Tables\Columns\TextColumn::make('montant_paye')
                    ->label('Payé')
                    ->state(fn (Facture $record): string => self::paye($record))
                    ->money('EUR'),
                Tables\Columns\TextColumn::make('cree_par_nom')->label('Créée par')->toggleable(),
                Tables\Columns\TextColumn::make('date_echeance')->label('Échéance')->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutFacture::class),
                // Les factures exonérées dont une preuve manque encore (voir
                // Facture::scopePreuvesAFournir()), que compte aussi le
                // badge du menu (voir getNavigationBadge()).
                Tables\Filters\Filter::make('preuves_a_fournir')
                    ->label('Preuves à fournir')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->preuvesAFournir()),
                // Les factures et les tickets émis dont il reste quelque
                // chose à régler (voir Facture::scopeAEncaisser()), où mène
                // l'alerte du tableau de bord (voir
                // FacturesAEncaisserApercu).
                Tables\Filters\Filter::make(self::FILTRE_A_ENCAISSER)
                    ->label('À encaisser')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->aEncaisser()),
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
                self::emettre(),
                Tables\Actions\Action::make('encaisser')
                    ->label('Encaisser')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        self::champDuJeton(),
                        // Le mode de paiement préféré du client est
                        // présélectionné (B8).
                        Select::make('mode_paiement')
                            ->label('Mode de paiement')
                            ->options(ModePaiement::class)
                            ->default(fn (Facture $record): ?string => $record->client?->mode_paiement_prefere?->value)
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
                            // Le crédit d'avoir du client solde ensuite ce
                            // que le montant reçu laisse dû (voir
                            // EnregistrerPaiementAction) : le montant
                            // proposé le lui laisse.
                            ->maxValue(fn (Facture $record) => $record->montantRestant())
                            ->default(fn (Facture $record): ?float => PaiementResource::montantAProposer($record->montantRestant(), ClientResource::soldeCrediteurDe($record->client_id) ?? 0.0))
                            ->helperText(fn (Facture $record): ?Htmlable => PaiementResource::aideDuCreditDisponible(ClientResource::soldeCrediteurDe($record->client_id), $record->montantRestant())),
                        TextInput::make('reference')->label('Référence')->maxLength(100),
                        DatePicker::make('recu_le')
                            ->label('Reçu le')
                            ->required()
                            ->default(today())
                            ->maxDate(today())
                            ->validationMessages(['before_or_equal' => 'Un paiement ne peut pas être reçu à une date future.']),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Facture $record): bool => auth()->user()->can('encaisser', $record))
                    ->action(fn (Facture $record, array $data) => static::executerSansCasser(fn () => app(EnregistrerPaiementAction::class)->handle(auth()->user(), $record->client, [
                        'mode_paiement' => $data['mode_paiement'],
                        'montant' => $data['montant'],
                        'reference' => $data['reference'] ?? null,
                        'recu_le' => $data['recu_le'],
                    ], [
                        ['facture_id' => $record->id, 'montant' => $data['montant']],
                    ], $data['jeton']))),
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
                self::relancer(Tables\Actions\Action::make('relancer')),
                Tables\Actions\Action::make('avoir')
                    ->label('Avoir')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->form(fn (Facture $record) => [
                        self::champDuJeton(),
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
                                self::champQuantiteRetournee('Quantité à créditer', fn (Get $get): ?int => AvoirResource::ligneVisible($get('ligne_facture_id'))?->quantiteCreditableRestante()),
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
                    ->modalDescription(fn (Facture $record): string => 'Seules les lignes et quantités conservées ci-dessous seront créditées, et leur stock restauré. Retirez les lignes à ne pas créditer. Cette action est irréversible. '.AvoirResource::imputationDuCredit($record->client))
                    ->visible(fn (Facture $record): bool => AvoirResource::peutCrediter($record))
                    ->action(function (Facture $record, array $data): void {
                        AvoirResource::autoriserLesLignesACrediter($data['lignes']);
                        static::executerSansCasser(fn () => app(EnregistrerAvoirAction::class)->handle(auth()->user(), $record->client, $data['lignes'], $data['motif'], $data['jeton']));
                    }),
            ])
            // Seuls les brouillons partent : une facture déjà émise se
            // corrige par un avoir (voir l'action "avoir" ci-dessus).
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(),
                ]),
            ]);
    }

    /**
     * Émettre un brouillon. Une vente au-delà du stock (R1) garde la
     * fenêtre ouverte et y montre l'avertissement que renvoie l'émission
     * (« Le stock de Câble USB passera à −2 : confirmer la vente ? »), avec
     * la confirmation qui l'accepte, que la personne autorisée valide ;
     * renouvelé si le stock a encore baissé entre-temps. La fenêtre porte
     * le jeton de la demande (R3) : une émission renvoyée après une réponse
     * perdue rend la facture déjà émise.
     */
    private static function emettre(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('emettre')
            ->label('Émettre')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => filled($arguments['avertissements'] ?? null) ? 'Vendre sans stock ?' : 'Émettre')
            ->modalDescription(fn (array $arguments): string|HtmlString => filled($arguments['avertissements'] ?? null)
                ? static::descriptionDeLAvertissement($arguments)
                : 'Un numéro définitif sera attribué, l\'échéance fixée et le stock décrémenté. Cette action est irréversible : une facture émise ne se corrige plus que par un avoir.')
            ->form([self::champDuJeton()])
            ->visible(fn (Facture $record) => $record->statut === StatutFacture::Brouillon && auth()->user()->can('emettre', $record))
            ->action(function (Facture $record, array $data, array $arguments, Tables\Actions\Action $action): void {
                $avertissement = null;

                static::executerSansCasser(function () use ($record, $data, $arguments, &$avertissement): void {
                    self::verifierLeNumeroTvaSurViesSiBesoin($record);

                    try {
                        app(EmettreFactureAction::class)->handle(auth()->user(), $record, confirmation: static::confirmationDesArguments($arguments), jeton: $data['jeton']);
                    } catch (ConfirmationVenteSansStockRequiseException $exception) {
                        $avertissement = $exception;
                    }
                });

                if ($avertissement === null) {
                    return;
                }

                $nouveaux = static::argumentsDeLAvertissement($avertissement);
                $liste = $action->getLivewire();
                $liste->mountedTableActionsArguments[array_key_last($liste->mountedTableActionsArguments)] = $nouveaux;
                $action->arguments($nouveaux);
                $action->halt();
            });
    }

    /**
     * Une livraison intracommunautaire ou une prestation autoliquidée ne
     * s'émet qu'avec le n° de TVA du client trouvé valide sur VIES le jour
     * même, par la dernière vérification du jour (voir
     * ControleNatureOperation::consultationDuJour()) : quand aucune
     * vérification d'aujourd'hui ne l'a trouvé valide, ou que la dernière
     * l'a trouvé inconnu, l'émission interroge d'abord VIES, hors de toute
     * transaction (voir VerifierNumeroTvaAction). VIES injoignable, rien
     * n'est émis, et le refus le dit.
     */
    private static function verifierLeNumeroTvaSurViesSiBesoin(Facture $facture): void
    {
        $client = $facture->client;

        if ($facture->nature_operation->exigeVies()
            && $client->aUnNumeroTvaVerifiableSurVies()
            && app(ControleNatureOperation::class)->consultationDuJour($client, now()) === null) {
            app(VerifierNumeroTvaAction::class)->handle(auth()->user(), $client);
        }
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
