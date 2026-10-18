<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\ClotureCaisseResource\Pages;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Lecture seule : une clôture se crée uniquement via l'action « Clôturer
 * la journée » (voir ListClotureCaisses) ou la commande planifiée
 * app:cloturer-caisses-automatiquement, jamais via un formulaire
 * create/edit classique. Document fiscal au même titre qu'une facture :
 * jamais modifiable ni supprimable une fois créée (voir
 * ClotureCaissePolicy et ClotureCaisse).
 *
 * La fiche montre le récapitulatif écrit à la clôture, sans rien
 * recalculer (points 15 et 24, voir RecapitulatifDeCloture) : ventes et
 * avoirs, net, factures aux professionnels, encaissements par mode, crédit
 * d'avoir utilisé et grands totaux. Chaque ticket et chaque avoir qu'elle
 * compte s'ouvre d'un clic (point 25) pour qui peut voir ce document ; une
 * personne qui ne voit que ses ventes y voit les totaux du magasin et le
 * détail de ses seuls documents, les autres comptés en nombre et en total,
 * sans numéro ni client (règle « Vendeurs »).
 */
class ClotureCaisseResource extends Resource
{
    protected static ?string $model = ClotureCaisse::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'clôture de caisse';

    protected static ?string $pluralModelLabel = 'clôtures de caisse';

    /**
     * Confidentialité par vendeur (voir ClotureCaissePolicy) : sans
     * cloture_caisse.view_toutes, la liste elle-même ne doit montrer que
     * les clôtures déclenchées par l'utilisateur courant.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)) {
            $query->where('cree_par', auth()->id());
        }

        return $query;
    }

    /**
     * « 08/10/2026 (oubliée) et 09/10/2026 » : des journées de caisse
     * (AAAA-MM-JJ), une journée d'avant aujourd'hui encore à clôturer
     * étant une journée oubliée.
     *
     * @param  Collection<int, string>  $journees
     */
    public static function libelleDesJournees(Collection $journees): string
    {
        $aujourdhui = today()->toDateString();

        return $journees
            ->map(fn (string $journee): string => CarbonImmutable::parse($journee)->format('d/m/Y').($journee < $aujourdhui ? ' (oubliée)' : ''))
            ->join(', ', ' et ');
    }

    /**
     * Ce que la clôture de ces journées regroupera, avec les mêmes
     * critères que CloturerCaisseAction : les ventes au comptoir de chaque
     * journée et les avoirs du même jour, pas encore clôturés, et leur
     * total net TTC.
     *
     * @param  Collection<int, string>  $journees
     * @return array{ventes: int, avoirs: int, total_ttc: float}
     */
    public static function enAttenteDeCloture(Collection $journees): array
    {
        $ventes = $journees->flatMap(fn (string $journee) => Facture::query()->enAttenteDeCloture($journee)->get());
        $avoirs = $journees->flatMap(fn (string $journee) => Avoir::query()->enAttenteDeCloture($journee)->get());
        $calculateur = app(CalculateurLigne::class);

        return [
            'ventes' => $ventes->count(),
            'avoirs' => $avoirs->count(),
            'total_ttc' => $calculateur->deduire($calculateur->cumuler($ventes), $calculateur->cumuler($avoirs))['total_ttc'],
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Clôture')->schema([
                TextEntry::make('numero')->label('Numéro'),
                TextEntry::make('date_cloture')->label('Journée clôturée')->date('d/m/Y'),
                TextEntry::make('cloturee_a')->label('Clôturée le')->dateTime('d/m/Y à H:i'),
                TextEntry::make('cree_par_nom')->label('Déclenchée par')->placeholder('Automatique (planifiée à minuit)'),
            ])->columns(2),

            // Le récapitulatif écrit à la clôture (voir
            // RecapitulatifDeCloture), jamais recalculé ici.
            InfolistSection::make('Ventes et avoirs')
                ->description('Montants enregistrés des documents, additionnés une seule fois à la clôture. Le net des ventes aux particuliers est la base de l\'e-reporting ; « TVA par taux » et « Biens et services » le détaillent.')
                ->schema([
                    RepeatableEntry::make('rubriques')
                        ->hiddenLabel()
                        ->state(fn (ClotureCaisse $record): array => self::rubriques($record))
                        ->schema([
                            TextEntry::make('rubrique')->label('Rubrique'),
                            TextEntry::make('nombre')->label('Nombre')->placeholder(''),
                            TextEntry::make('ht')->label('Total HT'),
                            TextEntry::make('tva')->label('TVA'),
                            TextEntry::make('ttc')->label('Total TTC'),
                        ])
                        ->columns(5),
                    FactureResource::ventilationTvaEntry(),
                    FactureResource::ventilationParNatureEntry(),
                ]),

            InfolistSection::make('Encaissements')
                ->description('Paiements enregistrés depuis la clôture précédente jusqu\'à la fin de la journée, par mode, contre-passations déduites : un paiement enregistré après la clôture de sa journée compte dans la suivante. Ventes et encaissements diffèrent quand un client garde un crédit d\'avoir ou paie plus tard.')
                ->schema([
                    RepeatableEntry::make('encaissements')
                        ->hiddenLabel()
                        ->state(fn (ClotureCaisse $record): array => self::encaissements($record))
                        ->schema([
                            TextEntry::make('libelle')->label('Mode de paiement'),
                            TextEntry::make('paiements')->label('Paiements'),
                            TextEntry::make('contre_passations')->label('Contre-passations'),
                            TextEntry::make('montant')->label('Montant encaissé'),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                    TextEntry::make('total_encaisse')
                        ->label('Total encaissé')
                        ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros($record->totalEncaisseEnCentimes())),
                    TextEntry::make('credit_avoir_impute')
                        ->label('Crédit d\'avoir utilisé (n\'entre pas dans les encaissements)')
                        ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros($record->recapitulatif['credit_avoir_impute'])),
                ])
                ->columns(2),

            InfolistSection::make('Grands totaux')
                ->description('Grands totaux perpétuels du magasin après le dernier document que compte la clôture, jamais remis à zéro : toutes ses ventes, factures aux professionnels comprises, avoirs déduits (réel) ou ajoutés en valeur absolue (absolu).')
                ->schema([
                    TextEntry::make('grand_total_reel')
                        ->label('Grand total perpétuel réel')
                        ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros($record->recapitulatif['grands_totaux']['perpetuel_reel'])),
                    TextEntry::make('grand_total_absolu')
                        ->label('Grand total perpétuel absolu')
                        ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros($record->recapitulatif['grands_totaux']['perpetuel_absolu'])),
                ])
                ->columns(2),

            InfolistSection::make('E-reporting')->schema([
                // L'e-reporting n'est pas transmis clôture par clôture : il
                // est agrégé par période (décade/mois/bimestre selon le
                // régime de TVA, voir CalculateurPeriodeEReporting) puis
                // transmis en une seule soumission (voir
                // SoumissionEReporting et la page "Soumissions e-reporting").
                TextEntry::make('soumissionEReporting.numero')
                    ->label('Incluse dans la soumission')
                    ->placeholder('Pas encore incluse (période en cours)'),
            ]),

            // Le détail des documents que la personne peut voir (règle
            // « Vendeurs ») : chaque numéro ouvre sa fiche ; les autres ne
            // se comptent qu'en nombre et en total.
            InfolistSection::make('Ventes agrégées')->schema([
                self::detailDesDocuments('tickets', fn (ClotureCaisse $record): array => self::documentsVisibles($record->factures(), $record->factures()->visiblesPar(auth()->user()))['visibles']),
                TextEntry::make('autres_tickets')
                    ->label('Autres tickets de l\'équipe')
                    ->state(fn (ClotureCaisse $record): ?string => self::documentsVisibles($record->factures(), $record->factures()->visiblesPar(auth()->user()))['autres'])
                    ->visible(fn (?string $state): bool => $state !== null),
            ]),

            InfolistSection::make('Avoirs déduits')
                ->visible(fn (ClotureCaisse $record) => $record->avoirs()->exists())
                ->schema([
                    self::detailDesDocuments('avoirs', fn (ClotureCaisse $record): array => self::documentsVisibles($record->avoirs(), $record->avoirs()->visiblesPar(auth()->user()))['visibles']),
                    TextEntry::make('autres_avoirs')
                        ->label('Autres avoirs de l\'équipe')
                        ->state(fn (ClotureCaisse $record): ?string => self::documentsVisibles($record->avoirs(), $record->avoirs()->visiblesPar(auth()->user()))['autres'])
                        ->visible(fn (?string $state): bool => $state !== null),
                ]),
        ]);
    }

    /**
     * Le tableau des documents que la personne voit, chaque numéro en lien
     * vers sa fiche.
     *
     * @param  Closure(ClotureCaisse): list<array<string, string|null>>  $documents
     */
    private static function detailDesDocuments(string $nom, Closure $documents): RepeatableEntry
    {
        return RepeatableEntry::make($nom)
            ->hiddenLabel()
            ->state($documents)
            ->schema([
                TextEntry::make('numero')->label('Numéro')
                    ->url(fn (TextEntry $component): string => $component->getContainer()->getState()['url'])
                    ->color('primary'),
                TextEntry::make('client_nom')->label('Client'),
                TextEntry::make('ht')->label('Total HT'),
                TextEntry::make('tva')->label('TVA'),
                TextEntry::make('ttc')->label('Total TTC'),
            ])
            ->columns(5);
    }

    /**
     * Les tickets ou les avoirs de la clôture que la personne voit (règle
     * « Vendeurs », voir Facture::scopeVisiblesPar() et
     * Avoir::scopeVisiblesPar()), avec le lien vers leur fiche et leurs
     * montants, les avoirs en négatif ; et, pour les autres, leur seul
     * nombre et leur total TTC (« 2 tickets, 24,00 € TTC »), sans aucun
     * numéro ni client, null quand elle les voit tous. Les totaux
     * s'additionnent en centimes (voir CalculateurLigne).
     *
     * @param  HasMany<Facture>|HasMany<Avoir>  $tous
     * @param  HasMany<Facture>|HasMany<Avoir>  $visibles
     * @return array{visibles: list<array{numero: string, url: string, client_nom: string|null, ht: string, tva: string, ttc: string}>, autres: string|null}
     */
    private static function documentsVisibles(HasMany $tous, HasMany $visibles): array
    {
        $estUnAvoir = $tous->getRelated() instanceof Avoir;
        $calculateur = app(CalculateurLigne::class);
        $sens = $estUnAvoir ? -1 : 1;
        $montant = fn (Facture|Avoir $document, string $colonne): string => $calculateur->enEuros($sens * $calculateur->enCentiemes($document->{$colonne}));
        $idsVisibles = $visibles->pluck('id')->all();
        [$vus, $autres] = $tous->orderBy('numero')->get()->partition(fn (Facture|Avoir $document): bool => in_array($document->getKey(), $idsVisibles, true));
        $nature = $estUnAvoir ? 'avoir' : 'ticket';

        return [
            'visibles' => $vus->map(fn (Facture|Avoir $document): array => [
                'numero' => $document->numero,
                'url' => $estUnAvoir ? AvoirResource::getUrl('view', ['record' => $document]) : FactureResource::getUrl('view', ['record' => $document]),
                'client_nom' => $document->client_nom,
                'ht' => $montant($document, 'total_ht'),
                'tva' => $montant($document, 'total_tva'),
                'ttc' => $montant($document, 'total_ttc'),
            ])->values()->all(),
            'autres' => $autres->isEmpty() ? null : sprintf(
                '%d %s%s, %s TTC',
                $autres->count(),
                $nature,
                $autres->count() > 1 ? 's' : '',
                $calculateur->enEuros($sens * (int) $autres->sum(fn (Facture|Avoir $document): int => $calculateur->enCentiemes($document->total_ttc))),
            ),
        ];
    }

    /**
     * Les lignes de la rubrique « Ventes et avoirs » : chaque rubrique du
     * récapitulatif avec son nombre et ses montants, puis le détail de
     * chacun de ses taux ; le net des ventes aux particuliers après leurs
     * avoirs, que gardent les totaux de la clôture.
     *
     * @return list<array{rubrique: string, nombre: int|null, ht: string, tva: string, ttc: string}>
     */
    private static function rubriques(ClotureCaisse $cloture): array
    {
        $calculateur = app(CalculateurLigne::class);
        $ligne = fn (string $rubrique, ?int $nombre, int $ht, int $tva, int $ttc): array => [
            'rubrique' => $rubrique,
            'nombre' => $nombre,
            'ht' => $calculateur->enEuros($ht),
            'tva' => $calculateur->enEuros($tva),
            'ttc' => $calculateur->enEuros($ttc),
        ];
        $rubrique = fn (string $cle, string $libelle): array => [
            $ligne($libelle, $cloture->recapitulatif[$cle]['nombre'], $cloture->recapitulatif[$cle]['ht'], $cloture->recapitulatif[$cle]['tva'], $cloture->recapitulatif[$cle]['ttc']),
            ...array_map(
                fn (array $taux): array => $ligne('dont TVA à '.self::taux($taux['taux']), null, $taux['ht'], $taux['tva'], $taux['ttc']),
                $cloture->recapitulatif[$cle]['par_taux'],
            ),
        ];

        return [
            ...$rubrique('ventes_particuliers', 'Ventes aux particuliers (tickets)'),
            ...$rubrique('avoirs_particuliers', 'Avoirs sur des tickets'),
            $ligne('Net des ventes aux particuliers (base de l\'e-reporting)', null, $calculateur->enCentiemes($cloture->total_ht), $calculateur->enCentiemes($cloture->total_tva), $calculateur->enCentiemes($cloture->total_ttc)),
            ...$rubrique('factures_professionnels', 'Factures aux professionnels (pour information)'),
            ...$rubrique('avoirs_professionnels', 'Avoirs aux professionnels (pour information)'),
        ];
    }

    /**
     * Les encaissements du récapitulatif, par mode, leur montant écrit en
     * euros.
     *
     * @return list<array{libelle: string, paiements: int, contre_passations: int, montant: string}>
     */
    private static function encaissements(ClotureCaisse $cloture): array
    {
        return array_map(fn (array $mode): array => [
            'libelle' => $mode['libelle'],
            'paiements' => $mode['paiements'],
            'contre_passations' => $mode['contre_passations'],
            'montant' => app(CalculateurLigne::class)->enEuros($mode['montant']),
        ], $cloture->recapitulatif['encaissements']);
    }

    /**
     * Un taux en centièmes de point tel qu'il s'écrit : 2000 donne
     * « 20 % », 550 « 5,5 % ».
     */
    private static function taux(int $centiemes): string
    {
        return rtrim(rtrim(sprintf('%d,%02d', intdiv($centiemes, 100), $centiemes % 100), '0'), ',').' %';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_cloture', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('date_cloture')->label('Journée')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('nombre_ventes')->label('Ventes'),
                Tables\Columns\TextColumn::make('nombre_avoirs')->label('Avoirs'),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR'),
                Tables\Columns\TextColumn::make('encaisse')
                    ->label('Encaissé')
                    ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros($record->totalEncaisseEnCentimes())),
                Tables\Columns\TextColumn::make('cree_par')
                    ->label('Déclenchée par')
                    ->state(fn (ClotureCaisse $record) => $record->cree_par_nom ?? 'Automatique')
                    ->badge()
                    ->color(fn (ClotureCaisse $record) => $record->cree_par === null ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('cloturee_a')->label('Clôturée le')->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClotureCaisses::route('/'),
            'view' => Pages\ViewClotureCaisse::route('/{record}'),
        ];
    }
}
