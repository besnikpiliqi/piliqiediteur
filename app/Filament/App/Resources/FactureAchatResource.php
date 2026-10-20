<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tva\Services\CalculateurLigne;
use App\Filament\App\Resources\FactureAchatResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDAchat;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Closure;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;

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
    use PorteUnJeton;
    use SaisitUneQuantiteRetournee;
    use SupprimeEnMasseLigneParLigne;

    protected static ?string $model = FactureAchat::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'facture d\'achat';

    protected static ?string $pluralModelLabel = 'factures d\'achat';

    /**
     * Ce que dit l'écran d'un avoir fournisseur sans le n° de l'avoir du
     * fournisseur, dans le formulaire puis dans l'avis de son émission.
     */
    public const AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR = 'Sans le n° de l\'avoir de votre fournisseur, l\'avoir qu\'il enverra par la plateforme de facturation électronique ne pourra pas être rapproché de celui-ci.';

    private const CHOISIR_UNE_FACTURE_RETOURNABLE = 'Choisissez une facture d\'achat validée du magasin dont des produits restent à retourner.';

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make('Facture d\'achat')->schema([
                // Un fournisseur se crée dans sa propre page (voir
                // FournisseurResource), jamais depuis une facture d'achat.
                // Archivé, il n'est plus proposé ; celui de la facture
                // modifiée reste affiché (voir FactureAchat::fournisseur() et
                // AppServiceProvider::limiterLesChoixAuMagasin()), mais elle ne
                // se valide pas tant qu'il n'est pas restauré (voir
                // ValiderFactureAchatAction).
                Select::make('fournisseur_id')
                    ->label('Fournisseur')
                    ->relationProposable('fournisseur', 'nom')
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
                self::totauxDuDocument()->label('Total de la facture d\'achat (à payer au fournisseur)'),
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
                TextEntry::make('cree_par_nom')->label('Créée par'),
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
                // Le total TTC moins ceux des avoirs fournisseur émis (voir
                // FactureAchat::totalTtcApresAvoirsFournisseurEnCentimes()).
                TextEntry::make('total_apres_avoirs_fournisseur')
                    ->label('Total après avoirs fournisseur')
                    ->state(fn (FactureAchat $record): string => app(CalculateurLigne::class)->enEuros($record->totalTtcApresAvoirsFournisseurEnCentimes()))
                    ->visible(fn (FactureAchat $record): bool => $record->avoirsFournisseurEmis()->exists()),
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
                                ->url(fn (AvoirFournisseur $record): ?string => auth()->user()->can('view', $record) ? AvoirFournisseurResource::getUrl('view', ['record' => $record]) : null)
                                ->color('primary'),
                            TextEntry::make('numero_fournisseur')->label('N° de l\'avoir du fournisseur')->state(fn (AvoirFournisseur $record) => $record->numero_fournisseur)->placeholder('—'),
                            TextEntry::make('motif')->label('Motif')->state(fn (AvoirFournisseur $record) => $record->motif)->placeholder('—'),
                            TextEntry::make('total_ttc')->label('Total retourné TTC')->state(fn (AvoirFournisseur $record) => $record->total_ttc)->money('EUR'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('fournisseur.nom')->label('Fournisseur')->rechercheSansAccents(),
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
                    ->form([self::champDuJeton()])
                    ->visible(fn (FactureAchat $record) => $record->statut === StatutFactureAchat::Brouillon && auth()->user()->can('valider', $record))
                    ->action(fn (FactureAchat $record, array $data) => static::executerSansCasser(fn () => app(ValiderFactureAchatAction::class)->handle(auth()->user(), $record, $data['jeton']))),
                self::creerUnAvoirFournisseur(Tables\Actions\Action::make('avoir_fournisseur')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(),
                ]),
            ]);
    }

    /**
     * « Créer un avoir fournisseur » : le seul formulaire d'un retour au
     * fournisseur, et le seul chemin vers le domaine (voir
     * EnregistrerAvoirFournisseurAction), depuis quatre endroits :
     *
     * - le menu « Avoirs fournisseur », sans document : on y choisit
     *   d'abord une facture d'achat validée du magasin dont il reste des
     *   articles à retourner (voir FactureAchat::scopeRetournables()) ;
     * - la liste des factures d'achat et la fiche de l'une d'elles, avec
     *   cette facture ;
     * - « Retourner ce produit », sur chaque ligne retournable de la fiche
     *   d'une facture d'achat, avec cette seule ligne.
     *
     * Chaque ligne proposée l'est pour tout ce qui reste à retourner et en
     * stock (voir lignesRetournables()) ; la quantité ne le dépasse pas. Le
     * n° de l'avoir du fournisseur est demandé avec insistance : vide, un
     * avertissement le réclame, sans refuser l'avoir, puisqu'il servira à
     * rapprocher celui que le fournisseur enverra par la plateforme de
     * facturation électronique. La fenêtre porte le jeton de la demande
     * (R3). Le bouton n'apparaît qu'à qui peut créer un avoir fournisseur et
     * voir la facture d'achat, ou depuis le menu les factures d'achat (voir
     * peutCreerUnAvoirFournisseur()), jamais dans un magasin en lecture
     * seule (voir ControleDesDroits).
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function creerUnAvoirFournisseur(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (?Model $record): string => $record instanceof LigneAchat ? 'Retourner ce produit' : 'Créer un avoir fournisseur')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->modalHeading(fn (?Model $record): string => $record instanceof LigneAchat ? 'Retourner ce produit' : 'Créer un avoir fournisseur')
            ->modalDescription('Seules les lignes et quantités conservées ci-dessous seront retournées, et leur stock décrémenté. Un produit déjà vendu à un client ne peut jamais être inclus. Cette action est irréversible.')
            ->modalSubmitActionLabel('Émettre l\'avoir fournisseur')
            ->form(fn (?Model $record): array => self::formulaireDeLAvoirFournisseur($record))
            ->visible(fn (?Model $record): bool => self::peutCreerUnAvoirFournisseur($record))
            ->action(fn (?Model $record, array $data) => static::executerSansCasser(fn () => self::enregistrerLAvoirFournisseur($record, $data)));
    }

    /**
     * Sous la désignation d'une ligne d'achat, en orange, comme le crédit
     * d'avoir sous un client (voir ClientResource::enEvidence()), ce qu'en
     * a retourné chaque avoir fournisseur émis : « Retourné : 4 (avoir
     * AF-MABOUT-2026-000001 du 17/10/2026) », le numéro menant à la fiche
     * de l'avoir pour qui peut la voir ; rien pour une ligne jamais
     * retournée. Les retours se lisent dans l'ordre de leur émission, comme
     * les avoirs de la facture (voir FactureAchat::avoirsFournisseurEmis()) :
     * par instant d'émission, en UTC, qui ne recule pas quand l'heure de
     * Paris se répète, puis par numéro.
     */
    public static function retoursDeLaLigne(LigneAchat $ligne): ?Htmlable
    {
        $retours = $ligne->lignesAvoirFournisseur()
            ->whereHas('avoirFournisseur', fn (Builder $avoir): Builder => $avoir->where('statut', StatutAvoirFournisseur::Emise))
            ->with('avoirFournisseur')
            ->get()
            ->sortBy(fn (LigneAvoirFournisseur $retour): array => [$retour->avoirFournisseur->date_emission->getTimestamp(), $retour->avoirFournisseur->numero])
            ->map(function (LigneAvoirFournisseur $retour): string {
                $avoir = $retour->avoirFournisseur;
                $numero = auth()->user()?->can('view', $avoir)
                    ? '<a href="'.e(AvoirFournisseurResource::getUrl('view', ['record' => $avoir])).'" style="text-decoration: underline;">'.e($avoir->numero).'</a>'
                    : e($avoir->numero);

                return ClientResource::enEvidence(new HtmlString(e("Retourné : {$retour->quantite} (avoir ").$numero.e(' du '.$avoir->date_emission->format('d/m/Y').')')))->toHtml();
            });

        return $retours->isEmpty() ? null : new HtmlString($retours->implode('<br>'));
    }

    /**
     * La facture d'achat que le bouton retourne : la sienne, celle de sa
     * ligne, ou aucune depuis le menu, où on la choisit.
     */
    private static function factureDuRetour(?Model $record): ?FactureAchat
    {
        return match (true) {
            $record instanceof FactureAchat => $record,
            $record instanceof LigneAchat => $record->factureAchat,
            default => null,
        };
    }

    /**
     * Le bouton ne s'offre qu'à qui peut créer un avoir fournisseur et voir
     * la facture d'achat qu'il crédite : depuis le menu, où il liste les
     * factures retournables, leurs fournisseurs et leurs lignes, à qui voit
     * les factures d'achat (voir CreerAvoirFournisseurAction, qui le
     * revérifie sur la facture choisie).
     */
    private static function peutCreerUnAvoirFournisseur(?Model $record): bool
    {
        $utilisateur = auth()->user();
        $factureAchat = self::factureDuRetour($record);

        if (! $utilisateur->can('create', AvoirFournisseur::class)
            || ! ($factureAchat === null ? $utilisateur->can('viewAny', FactureAchat::class) : $utilisateur->can('view', $factureAchat))) {
            return false;
        }

        return match (true) {
            $record instanceof FactureAchat => $record->statut === StatutFactureAchat::Validee && $record->possedeDesLignesRetournables(),
            $record instanceof LigneAchat => $record->factureAchat->statut === StatutFactureAchat::Validee && $record->quantiteRetournableRestante() > 0,
            default => true,
        };
    }

    /**
     * @return list<Component>
     */
    private static function formulaireDeLAvoirFournisseur(?Model $record): array
    {
        $factureAchat = self::factureDuRetour($record);

        return [
            self::champDuJeton(),
            Select::make('facture_achat_id')
                ->label('Facture d\'achat')
                ->helperText('Une facture d\'achat validée du magasin dont des produits restent à retourner : encore en stock, et pas déjà retournés.')
                ->options(fn (): array => self::facturesAchatRetournables())
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::facturesAchatRetournables($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => ($facture = self::factureAchatRetournable($value)) === null ? null : self::libelleDeLaFactureAchat($facture))
                ->required()
                ->rule('integer')
                ->rule(fn (): Exists => FactureAchat::regleExisteDansLeMagasin(FactureAchat::query()->retournables()), fn (mixed $state): bool => self::estUnIdentifiant($state))
                ->validationMessages([
                    'integer' => self::CHOISIR_UNE_FACTURE_RETOURNABLE,
                    'exists' => self::CHOISIR_UNE_FACTURE_RETOURNABLE,
                ])
                ->live()
                ->afterStateUpdated(fn (Set $set, mixed $state) => $set('lignes', self::lignesDuRepeteur(self::lignesRetournables(self::factureAchatRetournable($state)))))
                ->visible($factureAchat === null),
            Repeater::make('lignes')
                ->label('Lignes à retourner')
                ->schema([
                    Hidden::make('ligne_achat_id'),
                    TextInput::make('description')
                        ->label('Produit / description')
                        ->disabled()
                        ->dehydrated(false),
                    self::champQuantiteRetournee('Quantité à retourner', fn (Get $get): int => self::ligneAchat($get('ligne_achat_id'))?->quantiteRetournableRestante() ?? 0)
                        ->rule(self::regleStockDuProduitRetourne()),
                ])
                ->columns(3)
                ->addable(false)
                ->deletable(true)
                ->default($factureAchat === null ? [] : self::lignesRetournables($factureAchat, $record instanceof LigneAchat ? $record : null))
                ->visible(fn (Get $get): bool => $factureAchat !== null || filled($get('facture_achat_id'))),
            TextInput::make('numero_fournisseur')
                ->label('N° de l\'avoir du fournisseur')
                ->maxLength(CreerAvoirFournisseurAction::LONGUEUR_NUMERO_FOURNISSEUR)
                ->live(onBlur: true)
                ->helperText(fn (?string $state): ?Htmlable => blank($state) ? ClientResource::enEvidence(self::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR) : null),
            Textarea::make('motif')->label('Motif du retour')->required(),
        ];
    }

    /**
     * Crée et émet l'avoir, puis dit son numéro ; sans le n° de l'avoir du
     * fournisseur, l'avis le réclame encore.
     *
     * @param  array<string, mixed>  $data
     */
    private static function enregistrerLAvoirFournisseur(?Model $record, array $data): void
    {
        $factureAchat = self::factureDuRetour($record) ?? FactureAchat::query()->findOrFail($data['facture_achat_id']);
        $avoir = app(EnregistrerAvoirFournisseurAction::class)->handle(
            auth()->user(),
            $factureAchat,
            array_values($data['lignes'] ?? []),
            $data['motif'],
            $data['numero_fournisseur'] ?? null,
            $data['jeton'],
        );
        $avis = Notification::make()->title("Avoir fournisseur {$avoir->numero} émis.");

        ($avoir->numero_fournisseur === null ? $avis->warning()->body(self::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR) : $avis->success())->send();
    }

    /**
     * Les factures d'achat proposées depuis le menu : les cinquante plus
     * récemment validées (voir ValiderFactureAchatAction) de celles qui
     * ont des articles à retourner ou, avec un terme, celles dont le
     * numéro, le n° du fournisseur ou le nom du fournisseur le contient,
     * sans tenir compte de la casse ni des accents.
     *
     * @return array<int, string>
     */
    private static function facturesAchatRetournables(?string $recherche = null): array
    {
        return FactureAchat::query()
            ->retournables()
            ->when(filled($recherche), fn (Builder $factures): Builder => RechercheSansAccents::appliquer($factures, (string) $recherche, ['numero', 'numero_fournisseur', 'fournisseur.nom']))
            ->with('fournisseur')
            ->orderByDesc('rang_validation')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (FactureAchat $facture): array => [$facture->getKey() => self::libelleDeLaFactureAchat($facture)])
            ->all();
    }

    /**
     * La facture d'achat retournable du magasin que désigne le choix, s'il
     * en désigne une ; un identifiant illisible n'est jamais cherché en
     * base, où PostgreSQL le refuserait.
     */
    private static function factureAchatRetournable(mixed $identifiant): ?FactureAchat
    {
        return self::estUnIdentifiant($identifiant) ? FactureAchat::query()->retournables()->with('fournisseur')->find((int) $identifiant) : null;
    }

    /**
     * La ligne d'achat du magasin que désigne une ligne du répéteur, s'il
     * en désigne une ; un identifiant illisible n'est jamais cherché en
     * base, et la quantité de sa ligne est donc plafonnée à zéro.
     */
    private static function ligneAchat(mixed $identifiant): ?LigneAchat
    {
        return self::estUnIdentifiant($identifiant) ? LigneAchat::query()->with('produit')->find((int) $identifiant) : null;
    }

    private static function estUnIdentifiant(mixed $valeur): bool
    {
        return (is_int($valeur) || is_string($valeur)) && filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private static function libelleDeLaFactureAchat(FactureAchat $facture): string
    {
        return $facture->numero.' — '.$facture->fournisseur->nom.(filled($facture->numero_fournisseur) ? " (n° {$facture->numero_fournisseur})" : '');
    }

    /**
     * Les lignes posées dans le répéteur après le choix d'une facture,
     * chacune sous sa propre clé, comme celles qu'il crée lui-même.
     *
     * @param  list<array{ligne_achat_id: int, description: string, quantite: int}>  $lignes
     * @return array<string, array{ligne_achat_id: int, description: string, quantite: int}>
     */
    private static function lignesDuRepeteur(array $lignes): array
    {
        return collect($lignes)->mapWithKeys(fn (array $ligne): array => [(string) Str::uuid() => $ligne])->all();
    }

    /**
     * Chaque ligne encore retournable, ou cette seule ligne, proposée pour
     * tout ce qui peut en être retourné ; deux lignes du même produit se
     * partagent son stock, dans l'ordre de la facture.
     *
     * @return list<array{ligne_achat_id: int, description: string, quantite: int}>
     */
    private static function lignesRetournables(?FactureAchat $factureAchat, ?LigneAchat $seuleLigne = null): array
    {
        $stockRestant = [];
        $lignes = [];

        foreach ($factureAchat?->lignes ?? [] as $ligne) {
            if ($seuleLigne !== null && ! $ligne->is($seuleLigne)) {
                continue;
            }

            $quantite = $ligne->quantiteRetournableRestante();

            if ($ligne->produit->suivi_stock) {
                $stockRestant[$ligne->produit_id] ??= max(0, $ligne->produit->stock_quantite);
                $quantite = min($quantite, $stockRestant[$ligne->produit_id]);
                $stockRestant[$ligne->produit_id] -= $quantite;
            }

            if ($quantite > 0) {
                $lignes[] = ['ligne_achat_id' => $ligne->id, 'description' => $ligne->description, 'quantite' => $quantite];
            }
        }

        return $lignes;
    }

    /**
     * Deux lignes du même produit se partagent son stock : leur total ne
     * peut pas le dépasser (voir AvoirFournisseur::verifierQueLeStockSuffit(),
     * qui le revérifie à l'enregistrement). Une ligne dont l'identifiant est
     * illisible n'est jamais cherchée en base : sa quantité, plafonnée à
     * zéro, est déjà refusée.
     */
    private static function regleStockDuProduitRetourne(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $produit = self::ligneAchat($get('ligne_achat_id'))?->produit;

            if ($produit === null || ! $produit->suivi_stock) {
                return;
            }

            $lignes = collect($get('../../lignes') ?? [])
                ->filter(fn (mixed $ligne): bool => is_array($ligne) && self::estUnIdentifiant($ligne['ligne_achat_id'] ?? null));
            $lignesDuProduit = LigneAchat::query()
                ->whereIn('id', $lignes->map(fn (array $ligne): int => (int) $ligne['ligne_achat_id'])->values())
                ->where('produit_id', $produit->id)
                ->pluck('id')
                ->all();
            $quantite = $lignes
                ->filter(fn (array $ligne): bool => in_array((int) $ligne['ligne_achat_id'], $lignesDuProduit, true))
                ->sum(fn (array $ligne): int => (int) ($ligne['quantite'] ?? 0));

            if ($quantite > $produit->stock_quantite) {
                $fail(AvoirFournisseur::messageStockInsuffisant($produit, $quantite));
            }
        };
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
