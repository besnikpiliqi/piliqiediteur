<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Filament\App\Resources\AvoirResource\Pages;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use Filament\Forms\Components\Actions\Action as ActionDeChamp;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Un avoir part toujours du numéro d'un ticket ou d'une facture, jamais du
 * client (point 2 de la série 8) : « N° du ticket ou de la facture »
 * cherche, sans tenir compte des majuscules ni des zéros de tête du rang
 * (voir Facture::scopeDeNumero()), parmi les documents émis du magasin
 * actif que la personne voit (un vendeur ne retrouve que ses ventes) et qui
 * ont encore une ligne à créditer ; le client s'affiche ensuite d'après le
 * document, tel qu'il l'a figé à son émission, avec les lignes encore à
 * rendre. D'autres tickets ou factures du même client s'y ajoutent, s'ils
 * peuvent entrer dans le même avoir (voir
 * CreerAvoirAction::incompatibilite()) ; le client affiché est alors celui
 * que l'avoir figera (voir Avoir::documentQuiDonneLeClient()). Le client
 * envoyé à l'émission est celui du premier document, relu par le serveur
 * (voir clientDuPremierDocument()), jamais une valeur venue du navigateur.
 *
 * La même recherche s'ouvre depuis « Nouvel avoir », depuis la fiche d'un
 * ticket ou d'une facture, déjà lancée sur son numéro (voir ViewFacture et
 * CreateAvoir::afterFill()), et par « Retour d'un ticket » à la caisse
 * (voir Caisse), ces deux boutons seulement pour qui peut l'ouvrir (voir
 * peutOuvrirLaRecherche()). L'action rapide « Avoir » de la liste des
 * factures (voir FactureResource) crédite, elle, une seule facture,
 * pré-remplie depuis celle-ci. Toutes créent et émettent l'avoir d'un seul
 * geste (voir EnregistrerAvoirAction). Jamais de page edit : un avoir émis
 * ne se modifie ni ne se supprime jamais (voir AvoirPolicy).
 */
class AvoirResource extends Resource
{
    use EnvoieLesDocuments;
    use GereLesExceptionsMetier;
    use SaisitUneQuantiteRetournee;

    /**
     * Le refus d'un numéro que la personne ne peut pas corriger : inconnu,
     * d'un autre magasin, encore en brouillon, ou vente d'un collègue pour
     * un vendeur qui ne voit que les siennes. Le même message pour tous :
     * il ne dit jamais qu'un tel document existe.
     */
    public const DOCUMENT_INTROUVABLE = 'Aucun ticket ni aucune facture que vous pouvez corriger ne porte ce numéro dans ce magasin.';

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
            // Le jeton de l'avoir (R3) : renvoyé après une réponse perdue,
            // il n'est émis qu'une fois (voir CreateAvoir).
            self::champDuJeton(),
            TextInput::make('recherche')
                ->label(fn (Get $get): string => self::documentsDeLAvoir($get('lignes')) === [] ? 'N° du ticket ou de la facture' : 'Ajouter un autre ticket ou une autre facture')
                ->placeholder('TK-... ou FAC-...')
                ->helperText('Le numéro imprimé sur le ticket ou la facture, en majuscules ou en minuscules ; les zéros avant le rang peuvent s\'omettre (…-2026-12 pour …-2026-000012).')
                ->maxLength(40)
                ->autofocus()
                // Entrée cherche le numéro au lieu d'émettre l'avoir.
                ->extraInputAttributes(fn (TextInput $component): array => ['x-on:keydown.enter.prevent' => "\$wire.mountFormComponentAction('{$component->getKey()}', 'chercher')"])
                ->suffixAction(self::actionChercher())
                ->dehydrated(false)
                ->columnSpanFull(),

            Placeholder::make('client')
                ->label('Client')
                ->content(fn (Get $get): string => self::clientEtDocuments(self::documentsDeLAvoir($get('lignes'))))
                ->visible(fn (Get $get): bool => self::documentsDeLAvoir($get('lignes')) !== [])
                ->columnSpanFull(),

            Repeater::make('lignes')
                ->label('Lignes à créditer')
                ->helperText('Les lignes encore à rendre des documents cherchés. Retirez celles à ne pas créditer, ou baissez leur quantité.')
                ->schema([
                    Hidden::make('ligne_facture_id'),
                    TextInput::make('facture_numero')->label('Document')->disabled()->dehydrated(false),
                    TextInput::make('description')->label('Produit / description')->disabled()->dehydrated(false),
                    self::champQuantiteRetournee('Quantité à créditer', fn (Get $get): ?int => self::ligneVisible($get('ligne_facture_id'))?->quantiteCreditableRestante()),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->addable(false)
                ->deletable(true)
                ->required()
                ->validationMessages(['required' => 'Cherchez un ticket ou une facture : l\'avoir crédite ses lignes.'])
                ->columnSpanFull(),

            Textarea::make('motif')->label('Motif de l\'avoir')->required()->columnSpanFull(),

            Placeholder::make('imputation_du_credit')
                ->hiddenLabel()
                ->content(fn (Get $get): string => self::imputationDuCredit((self::documentsDeLAvoir($get('lignes'))[0] ?? null)?->client))
                ->visible(fn (Get $get): bool => self::documentsDeLAvoir($get('lignes')) !== [])
                ->columnSpanFull(),
        ])->columns(2);
    }

    /**
     * « Chercher » (ou Entrée dans le champ) : ajoute à l'avoir le document
     * du numéro saisi et vide le champ, prêt pour un autre numéro ; un
     * refus s'affiche sous le champ, et rien n'est ajouté.
     *
     * @throws ValidationException
     */
    private static function actionChercher(): ActionDeChamp
    {
        return ActionDeChamp::make('chercher')
            ->label('Chercher')
            ->icon('heroicon-o-magnifying-glass')
            ->action(function (mixed $state, Get $get, Set $set, TextInput $component): void {
                try {
                    $lignes = self::lignesAvecLeDocument($state, $get('lignes'));
                } catch (InvalidArgumentException $refus) {
                    throw ValidationException::withMessages([$component->getStatePath() => $refus->getMessage()]);
                }

                $set('lignes', $lignes);
                $set('recherche', null);
                $component->getLivewire()->resetErrorBag($component->getStatePath());
            });
    }

    /**
     * Les lignes de l'avoir, plus celles du ticket ou de la facture de ce
     * numéro (voir Facture::scopeDeNumero()) encore à rendre qui n'y sont
     * pas déjà, chacune à la quantité qui en reste : un document émis du
     * magasin actif, que la personne voit (voir Facture::scopeVisiblesPar()),
     * qui a encore une ligne à créditer, est du même client que le premier
     * document de l'avoir et peut entrer dans le même avoir que lui (voir
     * CreerAvoirAction::incompatibilite()). Chercher de nouveau un document
     * de l'avoir y remet ainsi une ligne retirée par erreur, sans doublon ;
     * il n'est refusé que si toutes ses lignes à rendre y sont déjà.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException le refus, rédigé pour la personne
     */
    public static function lignesAvecLeDocument(mixed $numero, mixed $lignes): array
    {
        if (! is_string($numero) || blank($numero)) {
            throw new InvalidArgumentException('Saisissez le n° du ticket ou de la facture.');
        }

        $lignes = is_array($lignes) ? $lignes : [];
        $document = Facture::query()->emises()->visiblesPar(auth()->user())->deNumero($numero)->with('lignes')->first();
        $refus = self::refusDuDocument($document, self::documentsDeLAvoir($lignes));

        if ($refus !== null) {
            throw new InvalidArgumentException($refus);
        }

        $dejaDansLAvoir = self::identifiantsDesLignes($lignes)->all();
        $lignesAAjouter = array_filter(self::lignesARendre($document), fn (array $ligne): bool => ! in_array($ligne['ligne_facture_id'], $dejaDansLAvoir, true));

        if ($lignesAAjouter === []) {
            throw new InvalidArgumentException(self::designation($document).' est déjà dans l\'avoir, avec toutes ses lignes à rendre.');
        }

        return [...$lignes, ...$lignesAAjouter];
    }

    /**
     * Pourquoi ce document ne s'ajoute pas à l'avoir qui corrige déjà
     * $documents ; null s'il s'ajoute, ou s'il en est déjà.
     *
     * @param  list<Facture>  $documents
     */
    private static function refusDuDocument(?Facture $document, array $documents): ?string
    {
        if ($document === null) {
            return self::DOCUMENT_INTROUVABLE;
        }

        $designation = self::designation($document);
        [$credite, $pronom, $autre] = $document->serie() === TypeDocument::TicketCaisse ? ['crédité', 'il', 'celui'] : ['créditée', 'elle', 'celle'];
        $premier = $documents[0] ?? null;

        return match (true) {
            ! $document->possedeDesLignesCreditables() => "{$designation} est déjà entièrement {$credite} : {$pronom} n'a plus rien à rendre.",
            $premier === null => null,
            (int) $document->client_id !== (int) $premier->client_id => "{$designation} est {$autre} d'un autre client : un avoir ne corrige que les documents d'un même client.",
            default => CreerAvoirAction::incompatibilite($premier, $document),
        };
    }

    /**
     * « Le ticket TK-LYON1-2026-000001 » ou « La facture
     * FAC-LYON1-2026-000001 », en tête d'un refus.
     */
    private static function designation(Facture $document): string
    {
        return ($document->serie() === TypeDocument::TicketCaisse ? 'Le ticket ' : 'La facture ').$document->numero;
    }

    /**
     * Les lignes du document encore à rendre, à la quantité qui en reste,
     * sous les clés du Repeater.
     *
     * @return array<string, array{ligne_facture_id: int, facture_numero: string, description: string, quantite: int}>
     */
    private static function lignesARendre(Facture $document): array
    {
        return $document->lignes
            ->filter(fn (LigneFacture $ligne): bool => $ligne->quantiteCreditableRestante() > 0)
            ->mapWithKeys(fn (LigneFacture $ligne): array => [(string) Str::uuid() => [
                'ligne_facture_id' => $ligne->id,
                'facture_numero' => $document->numero,
                'description' => $ligne->description,
                'quantite' => $ligne->quantiteCreditableRestante(),
            ]])
            ->all();
    }

    /**
     * Les documents émis dont l'avoir porte des lignes, dans l'ordre où ils
     * ont été ajoutés, relus en base parmi ceux que la personne voit : le
     * premier est la référence des documents qui s'ajoutent (voir
     * refusDuDocument()).
     *
     * @return list<Facture>
     */
    public static function documentsDeLAvoir(mixed $lignes): array
    {
        $identifiants = self::identifiantsDesLignes($lignes);

        if ($identifiants->isEmpty()) {
            return [];
        }

        $lignesVues = LigneFacture::query()
            ->whereKey($identifiants->all())
            ->whereHas('facture', fn (Builder $query) => $query->emises()->visiblesPar(auth()->user()))
            ->with('facture')
            ->get()
            ->keyBy('id');

        return $identifiants
            ->map(fn (int $identifiant): ?Facture => $lignesVues->get($identifiant)?->facture)
            ->filter()
            ->unique(fn (Facture $facture): int => $facture->getKey())
            ->values()
            ->all();
    }

    /**
     * Les identifiants des lignes de facture que portent les lignes de
     * l'avoir, dans leur ordre ; une valeur qui n'est pas un entier est
     * ignorée.
     *
     * @return Collection<int, int>
     */
    private static function identifiantsDesLignes(mixed $lignes): Collection
    {
        return collect(is_array($lignes) ? $lignes : [])
            ->map(fn (mixed $ligne): mixed => is_array($ligne) ? filter_var($ligne['ligne_facture_id'] ?? null, FILTER_VALIDATE_INT) : false)
            ->filter(fn (mixed $identifiant): bool => is_int($identifiant))
            ->values();
    }

    /**
     * « Client comptoir — ticket TK-LYON1-2026-000001 du 17/10/2026 à
     * 10 h 32 » : le client tel que l'avoir le figera, c'est-à-dire tel que
     * l'a figé le plus récent de ses documents (voir
     * Avoir::documentQuiDonneLeClient()), puis chaque document de l'avoir,
     * dans l'ordre où il a été ajouté.
     *
     * @param  list<Facture>  $documents
     */
    private static function clientEtDocuments(array $documents): string
    {
        if ($documents === []) {
            return '';
        }

        $designations = collect($documents)->map(function (Facture $document): string {
            $type = $document->serie() === TypeDocument::TicketCaisse ? 'ticket' : 'facture';

            return $type.' '.$document->numero.' du '.$document->date_emission->format('d/m/Y à H \\h i');
        });

        return collect([Avoir::documentQuiDonneLeClient($documents)->nomDuClient(), $designations->implode(', ')])->filter()->implode(' — ');
    }

    /**
     * Le client de l'avoir : celui du document de sa première ligne, relu
     * par le serveur parmi les documents que la personne voit. Une ligne
     * introuvable est refusée avant toute écriture.
     *
     * @param  array<array-key, array{ligne_facture_id: mixed}>  $lignes
     *
     * @throws InvalidArgumentException
     */
    public static function clientDuPremierDocument(array $lignes): Client
    {
        $premiere = self::ligneVisible(array_values($lignes)[0]['ligne_facture_id'] ?? null);

        if ($premiere === null) {
            throw new InvalidArgumentException('Cette ligne de facture est introuvable.');
        }

        return $premiere->facture->client;
    }

    /**
     * Un avoir se fait sur ce ticket ou cette facture : émis, avec encore
     * une ligne à créditer, par une personne qui peut créer un avoir. Le
     * bouton « Avoir » de la liste des factures, une fenêtre qui ne quitte
     * pas la liste, apparaît ainsi ; celui de la fiche, qui ouvre « Nouvel
     * avoir », exige en plus peutOuvrirLaRecherche().
     */
    public static function peutCrediter(Facture $facture): bool
    {
        return $facture->statut !== StatutFacture::Brouillon
            && $facture->possedeDesLignesCreditables()
            && auth()->user()->can(PermissionRegistry::AVOIR_CREER);
    }

    /**
     * La personne peut ouvrir « Nouvel avoir » et y retrouver un document :
     * elle accède aux avoirs (toute page de la ressource l'exige, voir
     * AvoirPolicy::viewAny()), peut en créer, et voit des tickets ou des
     * factures (les siens, ou ceux de toute l'équipe, comme
     * Facture::scopeVisiblesPar()). « Retour d'un ticket » à la caisse et
     * « Avoir » sur la fiche d'un document n'apparaissent qu'ainsi : jamais
     * vers une erreur 403, ni vers une recherche qui ne trouverait rien.
     */
    public static function peutOuvrirLaRecherche(): bool
    {
        return static::canAccess()
            && static::canCreate()
            && (auth()->user()->can(PermissionRegistry::FACTURE_VIEW) || auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES));
    }

    /**
     * Ce que devient le crédit de l'avoir, annoncé avant son émission, ici
     * comme dans l'action « Avoir » de FactureResource : il réduit d'abord
     * ce qui reste dû des documents qu'il corrige, puis règle les autres
     * factures impayées du client (voir ImputerAvoirSurSesFacturesAction),
     * sauf au client comptoir, dont l'avoir vaut bon d'avoir (voir
     * EnregistrerVenteCaisseAction).
     */
    public static function imputationDuCredit(?Client $client): string
    {
        return $client?->estComptoir()
            ? 'L\'avoir réduit d\'abord ce qui reste dû des tickets qu\'il corrige ; son crédit restant vaut bon d\'avoir : son numéro le fait utiliser lors d\'un prochain achat au comptoir.'
            : 'L\'avoir réduit d\'abord ce qui reste dû des documents qu\'il corrige. Le crédit restant est imputé sur les factures impayées du client, de la plus ancienne à la plus récente.';
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
                TextEntry::make('cree_par_nom')->label('Émis par'),
                TextEntry::make('motif')->label('Motif')->columnSpanFull(),
            ])->columns(2),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
                // Un avoir n'est jamais remboursé par ce logiciel (pas
                // d'intégration bancaire) : tant qu'il reste du crédit non
                // imputé (voir Avoir::montantNonAlloue()), ce doit être
                // visible ici aussi, pas seulement sur la fiche du client ;
                // celui d'un avoir au client comptoir est son bon d'avoir.
                TextEntry::make('montant_non_alloue')
                    ->label(fn (Avoir $record): string => $record->client?->estComptoir() ? 'Bon d\'avoir encore disponible' : 'Crédit encore disponible')
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
                    // Le crédit qu'un paiement saisi par erreur avait
                    // imputé y figure avec sa contre-passation, en négatif,
                    // qui le rend à l'avoir (voir ContrePasserPaiementAction).
                    RepeatableEntry::make('allocations')
                        ->label('')
                        ->schema([
                            TextEntry::make('facture.numero')->label('Facture'),
                            TextEntry::make('montant_alloue')
                                ->label('Montant crédité')
                                ->money('EUR')
                                ->color(fn (AvoirAllocation $record): ?string => (float) $record->montant_alloue < 0 ? 'danger' : null),
                        ])
                        ->columns(2),
                ]),

            self::envoisSection(),
        ]);
    }

    /**
     * Un vendeur ne crédite que ses ventes (B16) : chaque ligne demandée,
     * ici comme dans l'action « Avoir » de FactureResource, appartient à
     * un document que l'utilisateur voit, sinon la demande est refusée
     * (403) avant que rien ne s'enregistre, même avec un identifiant forgé
     * hors des lignes proposées. Une ligne d'un autre magasin n'est pas
     * lue ici (MagasinScope) : EnregistrerAvoirAction la refuse.
     *
     * @param  array<array-key, array{ligne_facture_id: mixed}>  $lignes
     *
     * @throws AuthorizationException
     */
    public static function autoriserLesLignesACrediter(array $lignes): void
    {
        $identifiants = array_map('intval', array_column($lignes, 'ligne_facture_id'));

        foreach (LigneFacture::query()->whereKey($identifiants)->with('facture')->get() as $ligne) {
            Gate::authorize('view', $ligne->facture);
        }
    }

    /**
     * La ligne choisie, ici comme dans l'action « Avoir » de
     * FactureResource, si l'utilisateur voit son document (B16) : sa
     * quantité encore créditable ne se lit pas depuis un identifiant forgé.
     */
    public static function ligneVisible(mixed $ligneFactureId): ?LigneFacture
    {
        $identifiant = filter_var($ligneFactureId, FILTER_VALIDATE_INT);

        return $identifiant === false ? null : LigneFacture::query()
            ->whereHas('facture', fn (Builder $query) => $query->visiblesPar(auth()->user()))
            ->find($identifiant);
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
                Tables\Columns\TextColumn::make('cree_par_nom')->label('Émis par')->toggleable(),
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
