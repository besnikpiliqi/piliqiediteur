<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\AvoirAllocation;
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
                    ->default(Calendrier::deParis()->aujourdhui())
                    ->maxDate(Calendrier::deParis()->aujourdhui())
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
                                // Le montant proposé est ce qui en reste dû :
                                // le crédit d'avoir du client y est déjà
                                // imputé (voir ImputationDuCredit).
                                ->afterStateUpdated(function (mixed $state, Set $set): void {
                                    $montantRestant = self::factureVisible($state)?->montantRestant();

                                    $set('montant', $montantRestant !== null && $montantRestant > 0 ? $montantRestant : null);
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

    private static function enEuros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    /**
     * Ce que la contre-passation de ce paiement va faire (voir
     * ContrePasserPaiementAction) : l'annuler, rouvrir ce qu'il réglait,
     * rendre aux bons d'avoir présentés avec lui le crédit qu'ils y avaient
     * imputé (le paiement de la première part d'un encaissement les signe,
     * voir EncaisserEnPlusieursModesAction), et imputer aussitôt le crédit
     * disponible : celui du client, ou pour le client comptoir, seulement
     * celui des avoirs qui corrigent les tickets rouverts.
     */
    private static function descriptionDeLaContrePassation(Paiement $paiement): string
    {
        $calculateur = app(CalculateurLigne::class);
        $bons = $paiement->creditsImputes()->with('avoir')->orderBy('id')->get()
            ->map(fn (AvoirAllocation $credit): string => sprintf('%s (%s)', $credit->avoir->numero, $calculateur->enEuros($calculateur->enCentiemes($credit->montant_alloue))));
        $phrases = [sprintf(
            'Ce paiement de %s (%s, reçu le %s) sera annulé par un paiement opposé daté d\'aujourd\'hui, et les factures qu\'il réglait redeviendront dues.',
            self::enEuros((float) $paiement->montant),
            $paiement->mode_paiement->getLabel(),
            Calendrier::afficherJour($paiement->recu_le, 'd/m/Y'),
        )];

        if ($bons->count() === 1) {
            $phrases[] = "Le bon d'avoir {$bons->sole()}, présenté avec lui, retrouvera le crédit qu'il y avait imputé, que le ticket devra de nouveau : son numéro pourra resservir.";
        } elseif ($bons->count() > 1) {
            $phrases[] = "Les bons d'avoir {$bons->join(', ', ' et ')}, présentés avec lui, retrouveront le crédit qu'ils y avaient imputé, que le ticket devra de nouveau : leurs numéros pourront resservir.";
        }

        $phrases[] = ($paiement->client?->estComptoir() ?? false)
            ? 'Les avoirs qui corrigent les tickets rouverts s\'y imputeront aussitôt.'
            : 'Le crédit d\'avoir disponible du client s\'y imputera aussitôt.';
        $phrases[] = 'Saisissez ensuite le bon paiement, pour ce qui restera dû.';

        return implode(' ', $phrases);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recu_le')->label('Reçu le')->jour()->sortable(),
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
                    ->modalDescription(fn (Paiement $record): string => self::descriptionDeLaContrePassation($record))
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
