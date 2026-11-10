<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Achats\Services\ChiffrageDUnAvoirFournisseur;
use App\Domain\Achats\Services\EcartsDUnTaux;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\TvaDuFournisseur;
use App\Domain\Facturation\Rules\MontantAuCentime;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\FactureAchatResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDAchat;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\JointLesPiecesDuFournisseur;
use App\Filament\Concerns\PorteUnJeton;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use App\Filament\Concerns\SaisitUneQuantiteRetournee;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Checkbox;
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
use InvalidArgumentException;

/**
 * Enregistre les factures reçues des fournisseurs (réception de
 * marchandise). Pas de PDF généré ici, contrairement aux documents de
 * vente : le document légal est celui du fournisseur, joint en pièce (voir
 * JointLesPiecesDuFournisseur) ; cette Resource trace la réception, le
 * stock et la traçabilité pour un éventuel avoir fournisseur (retour).
 */
class FactureAchatResource extends Resource
{
    use AjouteDesLignesDAchat, PrendLeCalendrierDuMagasin;
    use GereLesExceptionsMetier;
    use JointLesPiecesDuFournisseur;
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

    /**
     * La marque d'un document dont un écart avec le fournisseur a été
     * confirmé (voir TvaDuFournisseur).
     */
    public const TVA_A_VERIFIER = 'TVA à vérifier';

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
                DatePicker::make('date_facture')
                    ->label('Date de la facture du fournisseur')
                    ->helperText('Celle qu\'écrit la facture du fournisseur : le téléchargement des documents par période classe l\'achat par elle.')
                    ->required(),
                TextInput::make('escompte_pourcentage')
                    ->label('Escompte obtenu')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(0)
                    ->helperText('Réduction du fournisseur pour un paiement rapide : elle baisse le coût d\'achat des produits, pas le total de la facture.'),
                Textarea::make('notes')->columnSpanFull(),
                self::champDesPieces('Pièces du fournisseur', 'La vraie facture du fournisseur, en PDF ou en photo (JPEG, PNG) : dix fichiers au plus, de 10 Mo au plus chacun. Sans elle, la facture s\'enregistre et se valide, marquée « pièce du fournisseur manquante » jusqu\'à ce qu\'elle soit jointe.'),
            ])->columns(2),

            FormSection::make('Produits reçus')->schema([
                ...self::lignesDAchat(),
                ...self::tvaDeLaFacture(),
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
                self::marqueTvaAVerifier(),
                TextEntry::make('source')->badge(),
                TextEntry::make('fournisseur.nom')->label('Fournisseur'),
                TextEntry::make('numero_fournisseur')->label('N° facture fournisseur')->placeholder('—'),
                TextEntry::make('date_facture')->label('Date de la facture du fournisseur')->jour()->placeholder('—'),
                TextEntry::make('date_validation')->label('Validée le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('cree_par_nom')->label('Créée par'),
                TextEntry::make('escompte_pourcentage')
                    ->label('Escompte obtenu')
                    ->numeric(maxDecimalPlaces: 2)
                    ->suffix(' %')
                    ->visible(fn (FactureAchat $record) => (float) $record->escompte_pourcentage > 0),
            ])->columns(3),

            self::sectionDesPieces(),

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
                self::tvaParTauxEntry(),
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

    /**
     * Chaque facture avec le nombre de ses pièces du fournisseur (voir
     * colonnePiece()), et si elle a déjà un avoir fournisseur et des lignes
     * encore retournables (voir FactureAchat::estModifiable() et
     * possedeDesLignesRetournables()), lus par la même requête que la
     * liste, quel que soit le nombre de factures.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('pieces')
            ->withExists(['avoirsFournisseur', 'lignes as lignes_retournables_exists' => fn (Builder $lignes): Builder => $lignes->scopes('retournables')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('fournisseur.nom')->label('Fournisseur')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('numero_fournisseur')->label('N° fournisseur')->toggleable(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('source')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                self::colonneTvaAVerifier(),
                self::colonnePiece(),
                Tables\Columns\TextColumn::make('date_facture')->label('Date facture')->jour(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutFactureAchat::class),
                Tables\Filters\SelectFilter::make('source')->options(SourceFactureAchat::class),
                self::filtreTvaAVerifier(),
                self::filtrePieceManquante(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Un brouillon reste toujours librement modifiable. Une
                // fois validée, seule une facture saisie manuellement le
                // reste (voir FactureAchat::estModifiable()) -- une
                // récupérée par API ne l'est jamais. La suppression, elle,
                // reste interdite sans exception dès la validation (voir
                // FactureAchatPolicy::delete() et actionSupprimer()).
                Tables\Actions\EditAction::make()
                    ->visible(fn (FactureAchat $record) => $record->estModifiable()),
                self::actionSupprimer(Tables\Actions\DeleteAction::make())
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
                    static::suppressionEnMasse(FactureAchat::class, fn (FactureAchat $factureAchat) => app(SupprimerFactureAchatAction::class)->handle(auth()->user(), $factureAchat)),
                ]),
            ]);
    }

    /**
     * La marque « TVA à vérifier » d'une facture d'achat ou d'un avoir
     * fournisseur (voir TvaDuFournisseur), sur sa fiche : un montant saisi
     * s'écarte au-delà de la tolérance de ce que PILIQI calcule, et l'écart
     * a été confirmé. Visible de tous ceux qui voient les achats, cabinet
     * lié compris, qui n'a pas accès au journal d'audit.
     */
    public static function marqueTvaAVerifier(): TextEntry
    {
        return TextEntry::make('tva_a_verifier')
            ->label('TVA')
            ->state(fn (FactureAchat|AvoirFournisseur $record): ?string => $record->tva_a_verifier ? self::TVA_A_VERIFIER : null)
            ->badge()
            ->color('warning')
            ->helperText('Un montant saisi s\'écarte de ce que PILIQI calcule au-delà de la tolérance, et l\'écart a été confirmé : vérifiez le document du fournisseur. Une TVA facturée à tort n\'est pas déductible.')
            ->visible(fn (FactureAchat|AvoirFournisseur $record): bool => (bool) $record->tva_a_verifier);
    }

    /**
     * La TVA de chaque taux d'une facture d'achat ou d'un avoir fournisseur,
     * sur sa fiche : sa base, la TVA du document du fournisseur, retenue,
     * et, quand elle en diffère, celle que PILIQI calcule depuis les lignes
     * (« TVA du fournisseur 6,00 € — calcul depuis les lignes : 6,01 € ») ;
     * pour un écart confirmé, qui l'a confirmé et quand.
     */
    public static function tvaParTauxEntry(): RepeatableEntry
    {
        $calculateur = app(CalculateurLigne::class);

        return RepeatableEntry::make('tva_du_fournisseur')
            ->label('TVA par taux')
            ->state(fn (FactureAchat|AvoirFournisseur $record) => $record->taux()->get())
            ->schema([
                TextEntry::make('taux_tva')
                    ->label('Taux')
                    ->state(fn (TauxFactureAchat|TauxAvoirFournisseur $record): string => str_replace('.', ',', self::pourcentageEnTexte($record->taux_tva)).' %'),
                TextEntry::make('base_ht')
                    ->label('Base HT')
                    ->state(fn (TauxFactureAchat|TauxAvoirFournisseur $record): string => $calculateur->enEuros($calculateur->enCentiemes($record->base_ht))),
                TextEntry::make('montant_tva')
                    ->label('TVA du fournisseur')
                    ->state(fn (TauxFactureAchat|TauxAvoirFournisseur $record): string => $calculateur->enEuros($calculateur->enCentiemes($record->montant_tva)))
                    ->helperText(fn (TauxFactureAchat|TauxAvoirFournisseur $record): ?string => $record->montant_tva === $record->montant_tva_calcule
                        ? null
                        : 'Calcul depuis les lignes : '.$calculateur->enEuros($calculateur->enCentiemes($record->montant_tva_calcule))),
                TextEntry::make('ecart_confirme')
                    ->label('Écart')
                    ->state(fn (TauxFactureAchat|TauxAvoirFournisseur $record): ?string => $record->ecartConfirme()
                        ? 'Confirmé par '.($record->ecart_confirme_par_nom ?? '—').' le '.self::calendrierDuMagasin()->afficher($record->ecart_confirme_le, 'd/m/Y')
                        : null)
                    ->placeholder('Dans la tolérance'),
            ])
            ->columns(4)
            ->columnSpanFull();
    }

    /**
     * La marque « TVA à vérifier » dans la liste des factures d'achat ou des
     * avoirs fournisseur.
     */
    public static function colonneTvaAVerifier(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('tva_a_verifier')
            ->label('TVA')
            ->state(fn (FactureAchat|AvoirFournisseur $record): ?string => $record->tva_a_verifier ? self::TVA_A_VERIFIER : null)
            ->badge()
            ->color('warning')
            ->placeholder('—');
    }

    /**
     * Le filtre des documents marqués « TVA à vérifier », dans la liste des
     * factures d'achat ou des avoirs fournisseur.
     */
    public static function filtreTvaAVerifier(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('tva_a_verifier')
            ->label(self::TVA_A_VERIFIER)
            ->toggle()
            // Filament passe la requête par le nom du paramètre : $query.
            ->query(fn (Builder $query): Builder => $query->where('tva_a_verifier', true));
    }

    /**
     * « Supprimer » un brouillon, depuis la liste ou la page de
     * modification : par l'action du domaine, qui relit sous verrou le
     * magasin, l'auteur et la facture d'achat (voir
     * SupprimerFactureAchatAction) ; un refus (brouillon validé ou supprimé
     * pendant la requête, magasin fermé, droit retiré) se lit en
     * notification, et la facture d'achat reste. Un brouillon supprimé
     * avant le clic n'est plus dans l'écran : la liste le dit (voir
     * SignaleUneLigneDisparue), et sa page revient à la liste, qui le dit
     * aussi (voir ReponseAUnDocumentDisparu).
     *
     * @template TAction of DeleteAction|Tables\Actions\DeleteAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function actionSupprimer(DeleteAction|Tables\Actions\DeleteAction $action): DeleteAction|Tables\Actions\DeleteAction
    {
        return $action->using(fn (FactureAchat $record): bool => static::executerOuInterrompre(function () use ($record): bool {
            app(SupprimerFactureAchatAction::class)->handle(auth()->user(), $record);

            return true;
        }));
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
            ->action(function (?Model $record, array $data): void {
                try {
                    static::executerSansCasser(fn () => self::enregistrerLAvoirFournisseur($record, $data));
                } finally {
                    PieceDeposee::effacerLesEnvois($data['pieces'] ?? []);
                }
            });
    }

    /**
     * Sous la désignation d'une ligne d'achat, en orange, comme le crédit
     * d'avoir sous un client (voir ClientResource::enEvidence()), ce qu'en
     * a retourné chaque avoir fournisseur émis : « Retourné : 4 (avoir
     * AF-MABOUT-2026-000001 du 17/10/2026) », le numéro menant à la fiche
     * de l'avoir pour qui peut la voir ; rien pour une ligne jamais
     * retournée. Les retours se lisent dans l'ordre de leur émission, comme
     * les avoirs de la facture (voir FactureAchat::avoirsFournisseurEmis()) :
     * par instant d'émission, en UTC, qui ne recule pas quand une heure
     * se répète au passage à l'heure d'hiver, puis par numéro.
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

                return ClientResource::enEvidence(new HtmlString(e("Retourné : {$retour->quantite} (avoir ").$numero.e(' du '.self::calendrierDuMagasin()->afficher($avoir->date_emission, 'd/m/Y').')')))->toHtml();
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
     * La fenêtre de l'avoir fournisseur. Depuis le menu, la liste des
     * factures d'achat retournables se lit une fois, quand la fenêtre se
     * construit, et non par une fonction : avec des options données par une
     * fonction, la liste à recherche de Filament relit ses options au
     * serveur quand son état change, et, la fenêtre ouverte puis fermée une
     * seconde fois, celle de la première ouverture, retirée de la page mais
     * toujours à l'écoute, le faisait sans composant Livewire et levait une
     * erreur dans le navigateur (« Cannot read properties of undefined »).
     * La recherche, elle, interroge toujours le serveur.
     *
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
                ->options($factureAchat === null ? self::facturesAchatRetournables() : [])
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
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $lignes = self::lignesRetournables(self::factureAchatRetournable($state));
                    $set('lignes', self::lignesDuRepeteur($lignes));
                    $set('taux', self::tauxDesRetours($lignes, []));
                })
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
                        ->rule(self::regleStockDuProduitRetourne())
                        ->saisieEnDirect(),
                    TextInput::make('montant_ht')
                        ->label('HT sur la note d\'avoir')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('€')
                        ->rule(new MontantAuCentime)
                        ->helperText(fn (Get $get, TextInput $component): string => self::propositionDuRetour($get, $component))
                        ->saisieEnDirect(),
                ])
                ->columns(4)
                ->addable(false)
                ->deletable(true)
                ->default($factureAchat === null ? [] : self::lignesRetournables($factureAchat, $record instanceof LigneAchat ? $record : null))
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('taux', self::tauxDesRetours(array_values($get('lignes') ?? []), $get('taux') ?? [])))
                ->visible(fn (Get $get): bool => $factureAchat !== null || filled($get('facture_achat_id'))),
            Repeater::make('taux')
                ->label('TVA de la note d\'avoir du fournisseur')
                ->helperText('PILIQI propose la TVA des articles retournés. Si la note d\'avoir du fournisseur en écrit une autre, saisissez la sienne : c\'est elle qui prime.')
                ->schema([
                    Hidden::make('taux_tva'),
                    TextInput::make('montant_tva')
                        ->label(fn (Get $get): string => 'TVA à '.str_replace('.', ',', self::pourcentageEnTexte($get('taux_tva'))).' % sur la note d\'avoir')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('€')
                        ->rule(new MontantAuCentime)
                        ->helperText(fn (Get $get): string => self::propositionDeLaTvaDuRetour($get))
                        ->saisieEnDirect(),
                ])
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->default($factureAchat === null ? [] : self::tauxDesRetours(self::lignesRetournables($factureAchat, $record instanceof LigneAchat ? $record : null), []))
                ->visible(fn (Get $get): bool => filled($get('taux'))),
            TextInput::make('total_ttc_controle')
                ->label('Total TTC de la note d\'avoir (contrôle)')
                ->helperText('Facultatif : le total TTC qu\'écrit la note d\'avoir du fournisseur, pour vérifier vos saisies.')
                ->numeric()
                ->minValue(0)
                ->suffix('€')
                ->rule(new MontantAuCentime)
                ->saisieEnDirect(),
            Checkbox::make('confirmer_les_ecarts')
                ->label('Je confirme ces montants : ce sont ceux de la note d\'avoir du fournisseur. L\'avoir sera marqué « TVA à vérifier ».')
                ->helperText(fn (Get $get): HtmlString => new HtmlString(implode('<br>', array_map(e(...), self::avertissementsDuRetour($get)))))
                ->visible(fn (Get $get): bool => self::avertissementsDuRetour($get) !== [])
                ->accepted()
                ->validationMessages(['accepted' => 'Confirmez les montants de la note d\'avoir, ou corrigez votre saisie.']),
            TextInput::make('numero_fournisseur')
                ->label('N° de l\'avoir du fournisseur')
                ->maxLength(CreerAvoirFournisseurAction::LONGUEUR_NUMERO_FOURNISSEUR)
                ->live(onBlur: true)
                ->helperText(fn (?string $state): ?Htmlable => blank($state) ? ClientResource::enEvidence(self::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR) : null),
            Textarea::make('motif')->label('Motif du retour')->required(),
            self::champDesPieces('Note d\'avoir du fournisseur', 'La vraie note d\'avoir du fournisseur, en PDF ou en photo (JPEG, PNG) : dix fichiers au plus, de 10 Mo au plus chacun. Sans elle, l\'avoir s\'émet, marqué « pièce du fournisseur manquante » jusqu\'à ce qu\'elle soit jointe depuis sa fiche.'),
        ];
    }

    /**
     * Les retours que la fenêtre de l'avoir propose, tels que leur chiffrage
     * les reçoit (voir ChiffrageDUnAvoirFournisseur) : chaque ligne d'achat
     * du magasin désignée, avec une quantité et un HT lisibles ; les autres
     * ne comptent pas encore.
     *
     * @param  array<array-key, mixed>  $lignes
     * @return array<array-key, array{ligne_achat: LigneAchat, quantite: int, montant_ht_saisi: string|null}>
     */
    private static function retoursSaisis(array $lignes): array
    {
        $retours = [];

        foreach ($lignes as $cle => $ligne) {
            $ligneAchat = is_array($ligne) ? self::ligneAchat($ligne['ligne_achat_id'] ?? null) : null;

            try {
                if ($ligneAchat !== null) {
                    $retours[$cle] = [
                        'ligne_achat' => $ligneAchat,
                        'quantite' => QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null),
                        'montant_ht_saisi' => filled($ligne['montant_ht'] ?? null) ? app(CalculateurLigne::class)->enDecimal(SaisieDecimale::montantAuCentime($ligne['montant_ht'])) : null,
                    ];
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $retours;
    }

    /**
     * L'aperçu de l'avoir en cours de saisie : ses retours, avec la TVA que
     * la personne a saisie pour chaque taux, chiffrés comme à l'émission
     * (voir ChiffrageDUnAvoirFournisseur) ; une saisie de TVA qu'aucune note
     * d'avoir ne porte laisse la proposition, que l'émission refusera avec
     * son motif.
     */
    private static function apercuDuRetour(mixed $lignes, mixed $taux): DocumentChiffre
    {
        $retours = self::retoursSaisis(is_array($lignes) ? $lignes : []);
        $chiffrage = app(ChiffrageDUnAvoirFournisseur::class);

        try {
            return $chiffrage->chiffrer($retours, self::tvaEcriteDuFormulaire($taux));
        } catch (InvalidArgumentException) {
            try {
                return $chiffrage->chiffrer($retours, []);
            } catch (InvalidArgumentException) {
                return DocumentChiffre::vide();
            }
        }
    }

    /**
     * Les rangées de TVA de la fenêtre de l'avoir : une par taux des lignes
     * retournées, dans l'ordre où il apparaît (voir rangeesDeLaTva()).
     *
     * @param  array<array-key, mixed>  $lignes
     * @param  array<array-key, mixed>  $tauxSaisis
     * @return array<string, array{taux_tva: mixed, montant_tva: mixed}>
     */
    private static function tauxDesRetours(array $lignes, array $tauxSaisis): array
    {
        $taux = [];

        foreach ($lignes as $ligne) {
            $ligneAchat = is_array($ligne) ? self::ligneAchat($ligne['ligne_achat_id'] ?? null) : null;

            if ($ligneAchat !== null) {
                $taux[] = $ligneAchat->taux_tva;
            }
        }

        return self::rangeesDeLaTva($taux, $tauxSaisis);
    }

    /**
     * Sous le HT d'une ligne retournée : le HT et la TVA que PILIQI propose
     * pour ce retour, et, quand le HT de la note d'avoir s'en écarte de plus
     * de 0,02 €, l'avertissement.
     */
    private static function propositionDuRetour(Get $get, TextInput $champ): string
    {
        $ligne = self::apercuDuRetour($get('../../lignes'), $get('../../taux'))->lignes[$champ->getContainer()->getStatePath(isAbsolute: false)] ?? null;

        if ($ligne === null) {
            return 'Le HT qu\'écrit la note d\'avoir du fournisseur pour cette ligne, s\'il diffère de la proposition de PILIQI.';
        }

        $calculateur = app(CalculateurLigne::class);
        $ecart = abs($calculateur->enCentiemes($ligne['montant_ht']) - $calculateur->enCentiemes($ligne['montant_ht_calcule']));
        $proposition = 'Proposition de PILIQI : '.$calculateur->enEuros($calculateur->enCentiemes($ligne['montant_ht_calcule'])).' HT. Laissez vide pour la garder.';

        return $ecart > EcartsDUnTaux::TOLERANCE_DU_HT_D_UNE_LIGNE ? $proposition.' Écart de '.$calculateur->enEuros($ecart).', au-delà de 0,02 € : il faudra le confirmer.' : $proposition;
    }

    /**
     * Sous la TVA d'un taux de l'avoir : la TVA que PILIQI propose pour les
     * articles retournés, et, au-delà de la tolérance, l'avertissement.
     */
    private static function propositionDeLaTvaDuRetour(Get $get): string
    {
        $document = self::apercuDuRetour($get('../../lignes'), $get('../../taux'));
        $taux = self::tauxDeLaRangee($get);
        $montants = $document->parTaux[$taux] ?? null;

        if ($montants === null) {
            return '';
        }

        $calculateur = app(CalculateurLigne::class);
        $proposition = 'Base HT '.$calculateur->enEuros($calculateur->enCentiemes($montants['montant_ht'])).' · proposition de PILIQI : '.$calculateur->enEuros($calculateur->enCentiemes($document->tvaProposee($taux))).'. Laissez vide pour la garder.';
        $ecart = app(TvaDuFournisseur::class)->ecarts($document)[$taux] ?? null;

        return $ecart !== null && $ecart->tvaHorsTolerance() ? $proposition.' '.implode(' ', array_slice($ecart->avertissements(), -1)) : $proposition;
    }

    /**
     * Les écarts de l'avoir au-delà de la tolérance : chaque avertissement,
     * que la case de confirmation montre.
     *
     * @return list<string>
     */
    private static function avertissementsDuRetour(Get $get): array
    {
        $ecarts = app(TvaDuFournisseur::class)->ecartsAConfirmer(self::apercuDuRetour($get('lignes'), $get('taux')), null);

        return array_merge(...array_map(fn (EcartsDUnTaux $ecart): array => $ecart->avertissements(), $ecarts));
    }

    /**
     * Crée et émet l'avoir, puis dit son numéro ; sans le n° de l'avoir du
     * fournisseur, l'avis le réclame encore.
     *
     * @param  array<string, mixed>  $data
     */
    private static function enregistrerLAvoirFournisseur(?Model $record, array $data): void
    {
        $factureAchat = self::factureDuRetour($record) ?? FactureAchat::query()->whereKey(ValeursLues::cle($data['facture_achat_id']))->firstOrFail();
        $avoir = app(EnregistrerAvoirFournisseurAction::class)->handle(
            auth()->user(),
            $factureAchat,
            array_values($data['lignes'] ?? []),
            $data['motif'],
            $data['numero_fournisseur'] ?? null,
            $data['jeton'],
            self::montantsDuFournisseur($data),
            PieceDeposee::lire($data['pieces'] ?? []),
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

        foreach ($factureAchat?->loadMissing('lignes.produit')->lignes ?? [] as $ligne) {
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

            $saisies = $get('../../lignes');
            $lignes = collect(is_array($saisies) ? $saisies : [])
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
