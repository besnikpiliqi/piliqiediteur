<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClientResource;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Le règlement en plusieurs parts, d'un seul geste (points 1 et 23), à la
 * caisse (voir Caisse) comme dans « Encaisser » d'une facture (voir
 * FactureResource) : chaque part a un mode, un montant et une référence
 * facultative, chaque mode une seule part, sauf « Avoir », une part par
 * bon d'avoir, dont on saisit le numéro et dont le montant se lit dans
 * l'aperçu du règlement (voir ApercuDuReglement), jamais saisi. « Avoir »
 * n'est pas un mode d'encaissement (voir ModePaiement) : il s'enregistre
 * comme une imputation de crédit. Le crédit du client s'affiche à part,
 * en lecture : imputé d'office, il ne se retire pas.
 *
 * La première part se préremplit avec le mode préféré du client et ce
 * qu'il reste à répartir ; « Ajouter un autre mode » ajoute une part
 * préremplie avec ce qui reste après les autres. Une part restée sans
 * montant ne règle rien. À la caisse, le
 * prélèvement n'est pas proposé, les parts doivent égaler exactement ce
 * qu'il reste à payer, et seule la part en espèces a un montant reçu et
 * une monnaie à rendre ; sur une facture, elles ne dépassent jamais ce
 * qui reste dû. L'écran le dit pendant la saisie (« Il reste 10,00 € à
 * répartir. ») et refuse un règlement qui ne tombe pas juste ;
 * l'enregistrement le revérifie sous le verrou du registre.
 */
trait SaisitUnReglementEnParts
{
    /**
     * La valeur du choix « Avoir » d'une part.
     */
    public const PART_AVOIR = 'avoir';

    /**
     * Le crédit déjà imputé, les parts et ce qu'il reste à répartir.
     *
     * @param  Closure(Get, ?Model, string): ApercuDuReglement  $apercu  l'aperçu du règlement saisi, la racine étant le chemin du formulaire vu du composant (« ../../ » depuis une part)
     * @param  Closure(Get, ?Model, string): ?ModePaiement  $modePrefere  le mode préféré du client (B8)
     * @param  bool  $aLaCaisse  à la caisse : sans prélèvement, parts égales au reste à payer, montant reçu et monnaie de la part en espèces
     * @return list<Component>
     */
    public static function composantsDuReglement(Closure $apercu, Closure $modePrefere, bool $aLaCaisse, string $libelleDuCredit): array
    {
        return [
            Placeholder::make('credit_du_client')
                ->label($libelleDuCredit)
                ->content(fn (Get $get, ?Model $record): string => self::formaterCentimes($apercu($get, $record, '')->creditDuClient))
                ->helperText($aLaCaisse ? 'Imputé d\'office sur la vente.' : 'Déjà imputé sur ce document.')
                ->visible(fn (Get $get, ?Model $record): bool => $apercu($get, $record, '')->creditDuClient > 0),
            Repeater::make('parts')
                ->label('Règlement')
                ->schema(self::schemaDUnePart($apercu, $modePrefere, $aLaCaisse))
                ->columns($aLaCaisse ? 3 : 4)
                ->addActionLabel('Ajouter un autre mode')
                ->reorderable(false)
                ->defaultItems(1)
                ->live()
                ->rule(fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $parts, Closure $echec) use ($get, $record, $apercu, $aLaCaisse): void {
                    $ecart = self::ecartDesParts(is_array($parts) ? $parts : [], $apercu($get, $record, ''), $aLaCaisse);

                    if ($ecart !== null) {
                        $echec($ecart);
                    }
                }),
            Placeholder::make('reste_a_repartir')
                ->label('')
                ->content(fn (Get $get, ?Model $record): Htmlable => ClientResource::enEvidence((string) self::ecartDesParts($get('parts') ?? [], $apercu($get, $record, ''), $aLaCaisse)))
                ->visible(fn (Get $get, ?Model $record): bool => self::ecartDesParts($get('parts') ?? [], $apercu($get, $record, ''), $aLaCaisse) !== null),
        ];
    }

    /**
     * Les parts payées du règlement saisi, sans les parts « Avoir » ni
     * celles restées sans montant (rien ne restait à y payer).
     *
     * @param  mixed  $parts  l'état du répétiteur
     * @return list<PartDeReglement>
     *
     * @throws LogicException
     */
    public static function partsPayees(mixed $parts): array
    {
        $payees = [];

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (! is_array($part) || ($part['mode'] ?? null) === self::PART_AVOIR || blank($part['montant'] ?? null)) {
                continue;
            }

            $mode = self::modeDeLaPart($part['mode'] ?? null);
            $montant = self::montantEnCentimes($part['montant'] ?? null);

            if ($mode === null || $montant === null) {
                throw new LogicException(PartDeReglement::MONTANT_NON_POSITIF);
            }

            $payees[] = new PartDeReglement($mode, $montant, filled($part['reference'] ?? null) ? (string) $part['reference'] : null);
        }

        return $payees;
    }

    /**
     * Les numéros des bons d'avoir des parts « Avoir », dans l'ordre saisi.
     *
     * @param  mixed  $parts  l'état du répétiteur
     * @return list<string>
     */
    public static function bonsDesParts(mixed $parts): array
    {
        return array_values(array_map(
            fn (array $part): string => (string) ($part['bon'] ?? ''),
            array_filter(is_array($parts) ? $parts : [], fn (mixed $part): bool => is_array($part) && ($part['mode'] ?? null) === self::PART_AVOIR),
        ));
    }

    /**
     * Un montant saisi, en centimes ; null s'il n'est pas un montant en
     * euros d'au plus deux décimales.
     */
    public static function montantEnCentimes(mixed $valeur): ?int
    {
        $texte = match (true) {
            is_int($valeur) => (string) $valeur,
            is_float($valeur) => rtrim(rtrim(sprintf('%.6F', $valeur), '0'), '.'),
            is_string($valeur) => str_replace(',', '.', trim($valeur)),
            default => null,
        };

        if ($texte === null || $texte === '') {
            return null;
        }

        try {
            return app(CalculateurLigne::class)->enCentiemes($texte);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Un montant en centimes tel qu'un écran l'écrit (« 1 234,50 € »).
     */
    public static function formaterCentimes(int $centimes): string
    {
        return app(CalculateurLigne::class)->enEuros($centimes);
    }

    /**
     * Les modes que propose une part : à la caisse, jamais le prélèvement ;
     * « Avoir » quand un bon d'avoir peut régler le document.
     *
     * @return array<string, string>
     */
    private static function optionsDesModes(bool $aLaCaisse, bool $avecAvoir): array
    {
        $options = [];

        foreach ($aLaCaisse ? ModePaiement::aLaCaisse() : ModePaiement::cases() as $mode) {
            $options[$mode->value] = $mode->getLabel();
        }

        return $avecAvoir ? [...$options, self::PART_AVOIR => 'Avoir'] : $options;
    }

    /**
     * @param  Closure(Get, ?Model, string): ApercuDuReglement  $apercu
     * @param  Closure(Get, ?Model, string): ?ModePaiement  $modePrefere
     * @return list<Component>
     */
    private static function schemaDUnePart(Closure $apercu, Closure $modePrefere, bool $aLaCaisse): array
    {
        $estAvoir = fn (Get $get): bool => $get('mode') === self::PART_AVOIR;
        $estEspeces = fn (Get $get): bool => self::modeDeLaPart($get('mode')) === ModePaiement::Especes;

        return array_values(array_filter([
            Select::make('mode')
                ->label('Mode')
                ->options(fn (?Model $record): array => self::optionsDesModes($aLaCaisse, $aLaCaisse || self::accepteLesBons($record)))
                ->in(fn (?Model $record): array => array_keys(self::optionsDesModes($aLaCaisse, $aLaCaisse || self::accepteLesBons($record))))
                ->validationMessages(['in' => 'Choisissez un mode proposé.'])
                ->default(fn (Get $get, ?Model $record): ?string => self::modeAPreremplir($get('../../parts'), $modePrefere($get, $record, '../../'), $aLaCaisse))
                ->required()
                ->live()
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $mode, Closure $echec) use ($get): void {
                    $memeMode = array_filter($get('../../parts') ?? [], fn (mixed $part): bool => is_array($part) && ($part['mode'] ?? null) === $mode);

                    if ($mode !== self::PART_AVOIR && count($memeMode) > 1) {
                        $echec('Chaque mode ne règle qu\'une part du règlement.');
                    }
                }),
            TextInput::make('bon')
                ->label('N° du bon d\'avoir')
                ->placeholder('AV-...')
                ->maxLength(40)
                ->visible($estAvoir)
                ->required($estAvoir)
                ->live(onBlur: true)
                ->helperText(function (Get $get, ?Model $record) use ($apercu): ?Htmlable {
                    $refus = self::refusDuBon($apercu($get, $record, '../../'), $get('bon'));

                    return $refus === null ? null : ClientResource::enEvidence($refus);
                })
                ->rule(fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $numero, Closure $echec) use ($get, $record, $apercu): void {
                    $refus = self::refusDuBon($apercu($get, $record, '../../'), $numero);

                    if ($refus !== null) {
                        $echec($refus);
                    }
                }),
            Placeholder::make('montant_du_bon')
                ->label('Montant')
                ->visible($estAvoir)
                ->content(fn (Get $get, ?Model $record): string => self::formaterCentimes($apercu($get, $record, '../../')->bons[self::numeroDuBon($get('bon'))] ?? 0)),
            TextInput::make('montant')
                ->label('Montant')
                ->numeric()
                ->prefix('€')
                ->visible(fn (Get $get): bool => ! $estAvoir($get))
                ->minValue(0.01)
                ->maxValue(Caisse::MONTANT_MAXIMUM)
                ->validationMessages(['min' => PartDeReglement::MONTANT_NON_POSITIF])
                ->rule(fn (): Closure => function (string $attribute, mixed $montant, Closure $echec): void {
                    if (filled($montant) && self::montantEnCentimes($montant) === null) {
                        $echec('Saisissez un montant en euros, avec au plus deux décimales.');
                    }
                })
                ->default(fn (Get $get, ?Model $record): ?string => self::montantAPreremplir($get('../../parts'), $apercu($get, $record, '../../')))
                ->afterStateUpdated(fn (Set $set) => $set('montant_saisi', true))
                ->saisieEnDirect(),
            Hidden::make('montant_saisi')->default(false),
            TextInput::make('reference')
                ->label('Référence')
                ->helperText('Facultative : n° de transaction CB, de chèque...')
                ->maxLength(PartDeReglement::LONGUEUR_DE_LA_REFERENCE)
                ->visible(fn (Get $get): bool => ! $estAvoir($get)),
            $aLaCaisse ? TextInput::make('montant_recu')
                ->label('Montant reçu')
                ->numeric()
                ->prefix('€')
                // Facultatif (sert à calculer la monnaie), mais s'il est
                // saisi il doit couvrir la part en espèces : sinon la vente
                // serait enregistrée payée avec un tiroir-caisse en manque.
                ->minValue(fn (Get $get): float => (self::montantEnCentimes($get('montant')) ?? 0) / 100)
                ->maxValue(Caisse::MONTANT_MAXIMUM)
                ->validationMessages(['min' => 'Le montant reçu ne couvre pas la part en espèces.'])
                ->saisieEnDirect()
                ->visible($estEspeces) : null,
            $aLaCaisse ? Placeholder::make('monnaie_a_rendre')
                ->label('Monnaie à rendre')
                ->visible($estEspeces)
                ->content(function (Get $get): string {
                    $recu = self::montantEnCentimes($get('montant_recu'));
                    $part = self::montantEnCentimes($get('montant'));

                    return $recu === null || $part === null ? '—' : self::formaterCentimes(max(0, $recu - $part));
                }) : null,
        ]));
    }

    /**
     * Hors de la caisse, seul un ticket du client comptoir resté dû accepte
     * un bon d'avoir (Q6), jamais une autre facture.
     */
    private static function accepteLesBons(?Model $record): bool
    {
        return $record instanceof Facture && ($record->client?->estComptoir() ?? false);
    }

    /**
     * Ce qui sépare les parts saisies de ce qu'elles doivent couvrir : à
     * la caisse, exactement le reste à payer ; sur une facture, au plus ce
     * qui reste dû. Null quand le règlement tombe juste.
     *
     * @param  array<array-key, mixed>  $parts
     */
    private static function ecartDesParts(array $parts, ApercuDuReglement $apercu, bool $aLaCaisse): ?string
    {
        $total = 0;

        foreach ($parts as $part) {
            if (is_array($part) && ($part['mode'] ?? null) !== self::PART_AVOIR) {
                $total += self::montantEnCentimes($part['montant'] ?? null) ?? 0;
            }
        }

        if ($aLaCaisse) {
            return ReglementComptant::ecartEntre($total, $apercu->resteARepartir);
        }

        return $total > $apercu->resteARepartir ? sprintf(EncaisserEnPlusieursModesAction::PARTS_EN_TROP, self::formaterCentimes($total), self::formaterCentimes($apercu->resteARepartir)) : null;
    }

    /**
     * Le mode d'une part ajoutée : le mode préféré du client s'il n'est
     * pas déjà pris (et proposé), sinon le premier mode libre.
     */
    private static function modeAPreremplir(mixed $parts, ?ModePaiement $modePrefere, bool $aLaCaisse): ?string
    {
        $pris = array_map(fn (mixed $part): mixed => is_array($part) ? ($part['mode'] ?? null) : null, is_array($parts) ? array_values($parts) : []);
        $proposes = $aLaCaisse ? ModePaiement::aLaCaisse() : ModePaiement::cases();
        $candidats = $modePrefere !== null && in_array($modePrefere, $proposes, true) ? [$modePrefere, ...$proposes] : [ModePaiement::Especes, ...$proposes];

        foreach ($candidats as $mode) {
            if (! in_array($mode->value, $pris, true)) {
                return $mode->value;
            }
        }

        return null;
    }

    /**
     * Le montant d'une part ajoutée : ce qu'il reste à répartir après les
     * autres parts payées ; rien quand elles couvrent déjà tout.
     */
    private static function montantAPreremplir(mixed $parts, ApercuDuReglement $apercu): ?string
    {
        $reste = $apercu->resteARepartir;

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (is_array($part) && ($part['mode'] ?? null) !== self::PART_AVOIR) {
                $reste -= self::montantEnCentimes($part['montant'] ?? null) ?? 0;
            }
        }

        return $reste > 0 ? app(CalculateurLigne::class)->enDecimal($reste) : null;
    }

    /**
     * Le refus du bon d'avoir de ce numéro, tel que l'aperçu du règlement
     * le donne ; null pour un bon accepté ou un numéro encore vide.
     */
    private static function refusDuBon(ApercuDuReglement $apercu, mixed $numero): ?string
    {
        return $apercu->bonsRefuses[self::numeroDuBon($numero)] ?? null;
    }

    /**
     * Le numéro d'un bon d'avoir tel que l'aperçu et la recherche d'un bon
     * le lisent : en majuscules, sans espaces autour.
     */
    private static function numeroDuBon(mixed $numero): string
    {
        return mb_strtoupper(trim((string) $numero));
    }

    private static function modeDeLaPart(mixed $valeur): ?ModePaiement
    {
        return $valeur instanceof ModePaiement ? $valeur : ModePaiement::tryFrom((string) $valeur);
    }
}
