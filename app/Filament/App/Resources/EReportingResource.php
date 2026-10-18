<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\ApercuEReporting;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EReportingResource\Pages;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * La page « E-reporting » (point 26) : les journées clôturées du magasin
 * qui ont des opérations à déclarer, chacune avec l'aperçu de ce que
 * l'e-reporting des ventes aux particuliers déclarera (voir
 * EReportingDesParticuliers) et son statut, « non transmis » tant que la
 * plateforme agréée n'est pas raccordée (étape 2). La plateforme tient le
 * calendrier de l'e-reporting : PILIQI lui enverra chaque clôture
 * journalière ayant des opérations, une seule fois.
 *
 * Lecture seule, comme la clôture elle-même, et réservée à qui voit toutes
 * les clôtures du magasin : un aperçu additionne les ventes de toute
 * l'équipe. La fiche d'une clôture montre le même aperçu (voir
 * ClotureCaisseResource).
 */
class EReportingResource extends Resource
{
    protected static ?string $model = ClotureCaisse::class;

    protected static ?string $slug = 'e-reporting';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'E-reporting';

    protected static ?string $modelLabel = 'journée à déclarer';

    protected static ?string $pluralModelLabel = 'journées à déclarer';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $breadcrumb = 'E-reporting';

    /**
     * L'aperçu de chaque clôture affichée, calculé une fois par requête
     * pour toutes les entrées et colonnes qui le lisent.
     *
     * @var WeakMap<ClotureCaisse, ApercuEReporting>|null
     */
    private static ?WeakMap $apercus = null;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && (int) $record->getAttribute('magasin_id') === app(TenantContext::class)->id();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Seules les journées du magasin actif qui ont des opérations à
     * déclarer ; aucune dans le magasin de démonstration.
     */
    public static function getEloquentQuery(): Builder
    {
        return EReportingDesParticuliers::journeesADeclarer(app(TenantContext::class)->magasin());
    }

    public static function apercu(ClotureCaisse $cloture): ApercuEReporting
    {
        self::$apercus ??= new WeakMap;

        return self::$apercus[$cloture] ??= app(EReportingDesParticuliers::class)->apercu($cloture);
    }

    /**
     * La section « Aperçu de l'e-reporting (non transmis) », partagée par
     * la fiche d'une clôture et celle d'une journée de cette page : les
     * totaux journaliers par catégorie et par taux, en français, avec le
     * contrôle de leur total contre le net de la clôture, les encaissements
     * des prestations de services, ceux qui restent à répartir, et le
     * statut ; pour le magasin de démonstration, la seule mention qu'il
     * n'est jamais déclaré.
     */
    public static function sectionDeLApercu(): InfolistSection
    {
        return InfolistSection::make('Aperçu de l\'e-reporting (non transmis)')
            ->description('Ce que l\'e-reporting déclarera pour la journée : les ventes aux particuliers, avoirs déduits, totalisées par catégorie et par taux de TVA, sans nom, sans numéro de ticket ni moyen de paiement. Les factures aux professionnels n\'y entrent pas : elles partiront une à une comme factures électroniques.')
            ->schema([
                TextEntry::make('ereporting_demonstration')
                    ->hiddenLabel()
                    ->state(EReportingDesParticuliers::MENTION_DEMONSTRATION)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->demonstration)
                    ->columnSpanFull(),
                TextEntry::make('ereporting_rien')
                    ->hiddenLabel()
                    ->state('Rien à déclarer pour cette journée.')
                    ->visible(fn (ClotureCaisse $record): bool => ! self::apercu($record)->demonstration && ! self::apercu($record)->aDeclarer())
                    ->columnSpanFull(),
                RepeatableEntry::make('ereporting_transactions')
                    ->label('Totaux journaliers')
                    ->state(fn (ClotureCaisse $record): array => self::transactions(self::apercu($record)))
                    ->schema([
                        TextEntry::make('date')->label('Date'),
                        TextEntry::make('categorie')->label('Catégorie'),
                        TextEntry::make('taux')->label('Taux'),
                        TextEntry::make('ht')->label('Base HT'),
                        TextEntry::make('tva')->label('TVA'),
                        TextEntry::make('remarque')->label('Motif et remarque')->placeholder('—'),
                    ])
                    ->columns(6)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->transactions !== [])
                    ->columnSpanFull(),
                TextEntry::make('ereporting_total')
                    ->label('Total déclaré')
                    ->state(fn (ClotureCaisse $record): string => self::montants(self::apercu($record)->totalHt(), self::apercu($record)->totalTva()))
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->transactions !== []),
                TextEntry::make('ereporting_controle')
                    ->label('Contrôle')
                    ->state(fn (ClotureCaisse $record): string => self::controle($record))
                    ->color(fn (ClotureCaisse $record): string => app(EReportingDesParticuliers::class)->egaleLeNetDe(self::apercu($record), $record) ? 'success' : 'danger')
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->transactions !== []),
                TextEntry::make('ereporting_encaissements_mention')
                    ->label('Encaissements des prestations de services')
                    ->state(EReportingDesParticuliers::MENTION_ENCAISSEMENTS)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->encaissementsDeServices !== [])
                    ->columnSpanFull(),
                RepeatableEntry::make('ereporting_encaissements')
                    ->hiddenLabel()
                    ->state(fn (ClotureCaisse $record): array => array_map(fn (array $encaissement): array => [
                        'date' => self::date($encaissement['date']),
                        'taux' => ClotureCaisseResource::taux($encaissement['taux']),
                        'montant' => app(CalculateurLigne::class)->enEuros($encaissement['montant']),
                    ], self::apercu($record)->encaissementsDeServices))
                    ->schema([
                        TextEntry::make('date')->label('Date d\'encaissement'),
                        TextEntry::make('taux')->label('Taux'),
                        TextEntry::make('montant')->label('Montant encaissé'),
                    ])
                    ->columns(3)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->encaissementsDeServices !== [])
                    ->columnSpanFull(),
                TextEntry::make('ereporting_a_repartir_mention')
                    ->label('Encaissements à répartir')
                    ->state(EReportingDesParticuliers::MENTION_A_REPARTIR)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->encaissementsARepartir !== [])
                    ->columnSpanFull(),
                RepeatableEntry::make('ereporting_a_repartir')
                    ->hiddenLabel()
                    ->state(fn (ClotureCaisse $record): array => array_map(fn (array $encaissement): array => [
                        'date' => self::date($encaissement['date']),
                        'montant' => app(CalculateurLigne::class)->enEuros($encaissement['montant']),
                    ], self::apercu($record)->encaissementsARepartir))
                    ->schema([
                        TextEntry::make('date')->label('Date'),
                        TextEntry::make('montant')->label('Montant reçu'),
                    ])
                    ->columns(2)
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->encaissementsARepartir !== [])
                    ->columnSpanFull(),
                TextEntry::make('ereporting_statut')
                    ->label('Statut')
                    ->state(EReportingDesParticuliers::STATUT)
                    ->badge()
                    ->color('gray')
                    ->visible(fn (ClotureCaisse $record): bool => self::apercu($record)->aDeclarer()),
            ])
            ->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Journée')->schema([
                TextEntry::make('date_cloture')->label('Journée clôturée')->date('d/m/Y'),
                TextEntry::make('numero')
                    ->label('Clôture')
                    ->url(fn (ClotureCaisse $record): string => ClotureCaisseResource::getUrl('view', ['record' => $record]))
                    ->color('primary'),
            ])->columns(2),
            self::sectionDeLApercu(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_cloture', 'desc')
            ->emptyStateHeading('Aucune journée à déclarer')
            ->emptyStateDescription(fn (): string => app(TenantContext::class)->magasin()->est_demonstration
                ? EReportingDesParticuliers::MENTION_DEMONSTRATION
                : 'Une journée clôturée apparaît ici dès qu\'elle compte une vente ou un avoir à un particulier, ou l\'encaissement d\'une prestation de services.')
            ->columns([
                Tables\Columns\TextColumn::make('date_cloture')->label('Journée')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('numero')->label('Clôture'),
                Tables\Columns\TextColumn::make('apercu')
                    ->label('Aperçu')
                    ->state(fn (ClotureCaisse $record): array => self::lignesDeLApercu(self::apercu($record)))
                    ->listWithLineBreaks()
                    ->bulleted(),
                Tables\Columns\TextColumn::make('base_ht_declaree')
                    ->label('Base HT')
                    ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros(self::apercu($record)->totalHt())),
                Tables\Columns\TextColumn::make('tva_declaree')
                    ->label('TVA')
                    ->state(fn (ClotureCaisse $record): string => app(CalculateurLigne::class)->enEuros(self::apercu($record)->totalTva())),
                Tables\Columns\TextColumn::make('statut_ereporting')
                    ->label('Statut')
                    ->state(EReportingDesParticuliers::STATUT)
                    ->badge()
                    ->color('gray'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEReporting::route('/'),
            'view' => Pages\ViewEReporting::route('/{record}'),
        ];
    }

    /**
     * Les lignes de l'aperçu, en une phrase chacune : « Livraisons de
     * biens, 20 % : 83,33 € HT, 16,67 € de TVA », puis les encaissements
     * des prestations de services et ceux qui restent à répartir.
     *
     * @return list<string>
     */
    private static function lignesDeLApercu(ApercuEReporting $apercu): array
    {
        $calculateur = app(CalculateurLigne::class);

        return [
            ...array_map(fn (array $transaction): string => sprintf(
                '%s, %s : %s%s',
                $transaction['categorie']->getLabel(),
                ClotureCaisseResource::taux($transaction['taux']),
                self::montants($transaction['ht'], $transaction['tva']),
                $transaction['motif'] === null ? '' : " ({$transaction['motif']})",
            ), $apercu->transactions),
            ...array_map(fn (array $encaissement): string => sprintf(
                'Prestations de services encaissées le %s à %s : %s (à confirmer)',
                self::date($encaissement['date']),
                ClotureCaisseResource::taux($encaissement['taux']),
                $calculateur->enEuros($encaissement['montant']),
            ), $apercu->encaissementsDeServices),
            ...array_map(fn (array $encaissement): string => sprintf(
                'Encaissement à répartir le %s : %s',
                self::date($encaissement['date']),
                $calculateur->enEuros($encaissement['montant']),
            ), $apercu->encaissementsARepartir),
        ];
    }

    /**
     * Les transactions de l'aperçu telles qu'elles s'affichent : la date de
     * la journée, la catégorie et le taux en toutes lettres, les montants
     * en euros, le motif suivi de la remarque.
     *
     * @return list<array{date: string, categorie: string, taux: string, ht: string, tva: string, remarque: string|null}>
     */
    private static function transactions(ApercuEReporting $apercu): array
    {
        $calculateur = app(CalculateurLigne::class);

        return array_map(fn (array $transaction): array => [
            'date' => self::date($apercu->journee),
            'categorie' => $transaction['categorie']->getLabel(),
            'taux' => ClotureCaisseResource::taux($transaction['taux']),
            'ht' => $calculateur->enEuros($transaction['ht']),
            'tva' => $calculateur->enEuros($transaction['tva']),
            'remarque' => $transaction['motif'] === null ? null : trim("{$transaction['motif']}. {$transaction['remarque']}"),
        ], $apercu->transactions);
    }

    /**
     * Le contrôle du total de l'aperçu contre le net de la clôture.
     */
    private static function controle(ClotureCaisse $cloture): string
    {
        $calculateur = app(CalculateurLigne::class);

        return app(EReportingDesParticuliers::class)->egaleLeNetDe(self::apercu($cloture), $cloture)
            ? 'Égal au net des ventes aux particuliers de la clôture.'
            : 'Différent du net des ventes aux particuliers de la clôture ('.self::montants($calculateur->enCentiemes($cloture->total_ht), $calculateur->enCentiemes($cloture->total_tva)).') : à signaler.';
    }

    private static function montants(int $ht, int $tva): string
    {
        $calculateur = app(CalculateurLigne::class);

        return "{$calculateur->enEuros($ht)} HT, {$calculateur->enEuros($tva)} de TVA";
    }

    /**
     * Une journée AAAA-MM-JJ telle qu'elle s'écrit : 05/10/2026.
     */
    private static function date(string $jour): string
    {
        return CarbonImmutable::parse($jour)->format('d/m/Y');
    }
}
