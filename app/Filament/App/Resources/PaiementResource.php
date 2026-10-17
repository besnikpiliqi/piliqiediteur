<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\PaiementResource\Pages;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;

/**
 * Pas de page "edit" ni d'action de suppression : un paiement enregistré
 * ne se modifie ni ne se supprime jamais (voir PaiementPolicy et
 * ProtegeLesDonneesValidees). Un paiement saisi par erreur se contre-passe
 * (action « Contre-passer », voir ContrePasserPaiementAction), puis on
 * saisit le bon paiement.
 */
class PaiementResource extends Resource
{
    use GereLesExceptionsMetier;
    use PorteUnJeton;

    protected static ?string $model = Paiement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'paiement';

    protected static ?string $pluralModelLabel = 'paiements';

    /**
     * Même règle de confidentialité par vendeur que FactureResource (voir
     * PaiementPolicy::view) : la liste elle-même doit déjà être filtrée,
     * pas seulement l'accès direct à un enregistrement.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['allocations.facture', 'contrePassation']);

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Le jeton du paiement (R3) : renvoyé après une réponse perdue,
            // il n'est enregistré qu'une fois (voir CreatePaiement).
            self::champDuJeton(),
            Section::make('Paiement')->schema([
                Select::make('client_id')
                    ->label('Client')
                    // Archivé, un client n'est plus proposé pour une vente,
                    // mais il règle toujours ce qu'il doit encore (voir
                    // Client::scopeQuiPeuventRegler()).
                    ->options(fn (): array => Client::query()->quiPeuventRegler()->orderBy('nom')->pluck('nom', 'id')->all())
                    ->rule(fn (): Exists => Client::regleExisteDansLeMagasin(Client::query()->quiPeuventRegler()))
                    ->searchable()
                    ->live()
                    // Son crédit d'avoir solde ce que les montants imputés
                    // laissent dû des factures que le paiement règle (voir
                    // EnregistrerPaiementAction).
                    ->helperText(fn (Get $get): ?Htmlable => self::aideDuCreditDisponible(ClientResource::soldeCrediteurDe($get('client_id'))))
                    // Son mode de paiement préféré est présélectionné (B8) ;
                    // sans préférence, le mode déjà choisi reste.
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $modePrefere = Client::withTrashed()->find($state)?->mode_paiement_prefere;

                        if ($modePrefere !== null) {
                            $set('mode_paiement', $modePrefere->value);
                        }
                    })
                    ->required(),
                Select::make('mode_paiement')
                    ->label('Mode de paiement')
                    ->options(ModePaiement::class)
                    ->required(),
                // Le montant reçu est exactement le total imputé sur les
                // factures (B3, voir EnregistrerPaiementAction) ; le reste à
                // imputer se met à jour pendant la saisie. Un montant hors
                // limites n'est refusé que par maxValue, avec son message.
                TextInput::make('montant')
                    ->label('Montant reçu')
                    ->numeric()
                    ->required()
                    ->maxValue(Caisse::MONTANT_MAXIMUM)
                    ->saisieEnDirect()
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $valeur, Closure $echec) use ($get): void {
                        $montant = self::montantSaisi($valeur);
                        $totalImpute = self::totalImpute($get('allocations'));

                        if ($montant !== null && $totalImpute !== null && $montant !== $totalImpute) {
                            $echec(sprintf('Le montant reçu (%s) doit être égal au total imputé sur les factures (%s).', self::enEuros($montant), self::enEuros($totalImpute)));
                        }
                    }),
                TextInput::make('reference')->label('Référence')->maxLength(100),
                // Un paiement vu hier sur le relevé s'enregistre aujourd'hui ;
                // aucun ne se reçoit dans le futur (voir
                // EnregistrerPaiementAction).
                DatePicker::make('recu_le')
                    ->label('Reçu le')
                    ->required()
                    ->default(today())
                    ->maxDate(today())
                    ->validationMessages(['before_or_equal' => 'Un paiement ne peut pas être reçu à une date future.']),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Imputation sur les factures')
                ->description('Répartissez ce paiement sur une ou plusieurs factures du client : le montant reçu est égal au total imputé.')
                ->schema([
                    Repeater::make('allocations')
                        ->label('')
                        ->schema([
                            Select::make('facture_id')
                                ->label('Facture')
                                // Seulement les factures que l'utilisateur
                                // voit (B16, voir Facture::scopeVisiblesPar()) :
                                // un vendeur n'encaisse que les siennes.
                                ->options(function (Get $get) {
                                    $clientId = $get('../../client_id');

                                    if (! $clientId) {
                                        return [];
                                    }

                                    return Facture::query()
                                        ->visiblesPar(auth()->user())
                                        ->where('client_id', $clientId)
                                        ->whereIn('statut', StatutFacture::quiAttendentUnReglement())
                                        ->get()
                                        ->filter(fn (Facture $facture) => $facture->montantRestant() > 0)
                                        ->mapWithKeys(fn (Facture $facture) => [
                                            $facture->id => "{$facture->numero} — restant : ".self::enEuros($facture->montantRestant()),
                                        ]);
                                })
                                ->searchable()
                                ->live()
                                ->required()
                                // Le montant proposé laisse au crédit d'avoir
                                // du client ce qu'il peut solder (voir
                                // montantAProposer()), une fois déduit ce
                                // qu'il soldera des autres factures choisies.
                                ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                                    $facture = self::factureVisible($state);

                                    $set('montant', $facture === null ? null : self::montantAProposer(
                                        $facture->montantRestant(),
                                        self::creditLaissePourLaFacture(ClientResource::soldeCrediteurDe($get('../../client_id')) ?? 0.0, $get('../../allocations'), $facture),
                                    ));
                                }),
                            TextInput::make('montant')
                                ->label('Montant imputé')
                                ->numeric()
                                ->required()
                                ->saisieEnDirect()
                                // Une imputation ne dépasse jamais le reste
                                // dû de la facture choisie : un trop-perçu
                                // ne devient pas un crédit (contrairement à
                                // un avoir), seule une imputation partielle
                                // (acompte) reste permise.
                                ->maxValue(fn (Get $get): float => self::factureVisible($get('facture_id'))?->montantRestant() ?? Caisse::MONTANT_MAXIMUM),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel('Ajouter une facture')
                        ->live()
                        ->required(),
                    Placeholder::make('reste_a_imputer')
                        ->label('Reste à imputer')
                        ->content(function (Get $get): string {
                            $montant = self::montantSaisi($get('montant'));
                            $totalImpute = self::totalImpute($get('allocations'));

                            return $montant === null || $totalImpute === null ? '—' : self::enEuros(app(CalculateurLigne::class)->soustraire($montant, $totalImpute));
                        }),
                ]),
        ]);
    }

    /**
     * La facture choisie dans une imputation, si l'utilisateur la voit
     * (B16) : ni son reste dû ni le montant qui la règle ne se lisent
     * depuis un identifiant forgé.
     */
    private static function factureVisible(mixed $factureId): ?Facture
    {
        $identifiant = filter_var($factureId, FILTER_VALIDATE_INT);

        return $identifiant === false ? null : Facture::query()->visiblesPar(auth()->user())->find($identifiant);
    }

    /**
     * Un montant saisi, au centime ; null s'il n'est pas un nombre ou s'il
     * sort des limites (voir Caisse::MONTANT_MAXIMUM), que sa propre règle
     * refuse.
     */
    private static function montantSaisi(mixed $valeur): ?float
    {
        if (! is_numeric($valeur) || abs((float) $valeur) > Caisse::MONTANT_MAXIMUM) {
            return null;
        }

        return app(CalculateurLigne::class)->arrondirAuCentime((float) $valeur);
    }

    /**
     * Le total des montants imputés saisis, au centime ; une saisie qui
     * n'est pas un nombre n'y compte pas (sa propre règle la refuse). Null
     * quand une saisie sort des limites : aucun total ne s'en déduit.
     *
     * @param  mixed  $allocations  les lignes du répétiteur
     */
    private static function totalImpute(mixed $allocations): ?float
    {
        $montantsSaisis = collect(is_array($allocations) ? $allocations : [])
            ->pluck('montant')
            ->filter(fn (mixed $montant): bool => is_numeric($montant))
            ->map(fn (mixed $montant): ?float => self::montantSaisi($montant));

        if ($montantsSaisis->contains(null)) {
            return null;
        }

        try {
            return app(CalculateurLigne::class)->sommer($montantsSaisis);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Le montant à proposer pour régler une facture : ce qui en reste dû,
     * moins le crédit d'avoir du client, qui soldera ce que le montant
     * reçu laisse dû (voir EnregistrerPaiementAction) ; rien quand ce
     * crédit couvre tout.
     */
    public static function montantAProposer(float $resteDu, float $creditDisponible): ?float
    {
        $montant = app(CalculateurLigne::class)->soustraire($resteDu, max(0.0, $creditDisponible));

        return $montant > 0 ? $montant : null;
    }

    /**
     * Le crédit d'avoir du client qui reste pour solder cette facture, une
     * fois déduit ce qu'il soldera des autres factures du paiement : ce que
     * leur montant imputé laisse dû.
     *
     * @param  mixed  $allocations  les lignes du répétiteur
     */
    private static function creditLaissePourLaFacture(float $creditDisponible, mixed $allocations, Facture $facture): float
    {
        $calculateur = app(CalculateurLigne::class);
        $credit = $creditDisponible;

        foreach (is_array($allocations) ? $allocations : [] as $allocation) {
            $autre = (int) ($allocation['facture_id'] ?? 0) !== $facture->getKey()
                ? self::factureVisible($allocation['facture_id'] ?? null)
                : null;
            $montantImpute = self::montantSaisi($allocation['montant'] ?? null);

            if ($autre !== null && $montantImpute !== null) {
                $credit = $calculateur->soustraire($credit, max(0.0, $calculateur->soustraire($autre->montantRestant(), $montantImpute)));
            }
        }

        return max(0.0, $credit);
    }

    /**
     * L'aide sous le client ou le montant d'un paiement : le crédit d'avoir
     * du client ne s'y impute que sur ce que le montant reçu laisse dû
     * (voir EnregistrerPaiementAction), contrairement aux formulaires de
     * vente, où il s'impute à l'émission (voir
     * ClientResource::soldeCrediteurHelperText()). Pour une seule facture,
     * elle dit quel montant suffit pour la régler.
     */
    public static function aideDuCreditDisponible(?float $creditDisponible, ?float $resteDu = null): ?Htmlable
    {
        if ($creditDisponible === null) {
            return null;
        }

        $credit = 'Crédit disponible pour ce client : '.self::enEuros($creditDisponible).'.';
        $montantQuiSuffit = $resteDu === null ? null : self::montantAProposer($resteDu, $creditDisponible);

        return ClientResource::enEvidence(match (true) {
            $resteDu === null => "{$credit} Il soldera ce que les montants imputés laissent dû sur les factures choisies, de la plus ancienne à la plus récente.",
            $montantQuiSuffit === null => "{$credit} Il soldera ce que le montant reçu laisse dû, et couvre déjà tout ce qui reste dû.",
            default => "{$credit} Il soldera ce que le montant reçu laisse dû : ".self::enEuros($montantQuiSuffit).' suffisent pour régler la facture.',
        });
    }

    private static function enEuros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recu_le')->label('Reçu le')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('mode_paiement')->label('Mode')->badge(),
                Tables\Columns\TextColumn::make('montant')
                    ->money('EUR')
                    ->color(fn (Paiement $record): ?string => (float) $record->montant < 0 ? 'danger' : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('correction')
                    ->label('Correction')
                    ->state(fn (Paiement $record): ?string => match (true) {
                        $record->estUneContrePassation() => 'Contre-passation',
                        $record->contrePassation !== null => 'Contre-passé',
                        default => null,
                    })
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Contre-passation' ? 'danger' : 'gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('allocations')
                    ->label('Factures réglées')
                    ->state(fn (Paiement $record) => $record->allocations
                        ->map(fn ($allocation) => $allocation->facture?->numero ?? '—')
                        ->join(', '))
                    ->wrap(),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->toggleable(),
                Tables\Columns\TextColumn::make('cree_par_nom')->label('Enregistré par')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('mode_paiement')->options(ModePaiement::class),
            ])
            // Ni modification ni suppression (voir le commentaire de
            // classe) : un paiement saisi par erreur se contre-passe.
            ->actions([
                Tables\Actions\Action::make('contre_passer')
                    ->label('Contre-passer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->form([
                        self::champDuJeton(),
                        Textarea::make('motif')->label('Motif')->required()->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(fn (Paiement $record): string => sprintf(
                        'Ce paiement de %s (%s, reçu le %s) sera annulé par un paiement opposé daté d\'aujourd\'hui, et les factures qu\'il réglait redeviendront dues%s. Saisissez ensuite le bon paiement.',
                        self::enEuros((float) $record->montant),
                        $record->mode_paiement->getLabel(),
                        $record->recu_le->format('d/m/Y'),
                        $record->creditsImputes()->exists() ? ' ; le crédit d\'avoir qu\'il y avait imputé redeviendra disponible' : '',
                    ))
                    ->visible(fn (Paiement $record): bool => auth()->user()->can('contrePasser', $record))
                    ->action(fn (Paiement $record, array $data) => static::executerSansCasser(fn () => app(ContrePasserPaiementAction::class)->handle(auth()->user(), $record, $data['motif'], $data['jeton']))),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaiements::route('/'),
            'create' => Pages\CreatePaiement::route('/create'),
        ];
    }
}
