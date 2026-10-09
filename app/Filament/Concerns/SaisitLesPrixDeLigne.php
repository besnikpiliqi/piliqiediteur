<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use InvalidArgumentException;

/**
 * Prix et totaux d'une ligne de document, à l'écran : calculés comme à
 * l'enregistrement réel, par le même calculateur (voir
 * montantsDeLigneSaisie()).
 */
trait SaisitLesPrixDeLigne
{
    private const AUCUN_MONTANT = ['montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0];

    /**
     * PU HT, PU TTC et TVA d'une ligne de vente : seul le PU TTC se saisit
     * (le prix affiché au client, repris tel quel de la fiche du produit),
     * le PU HT n'en est que la conversion au centime, affichée à côté.
     *
     * @return array<Component>
     */
    protected static function champsPrixDeLigne(): array
    {
        return [
            TextInput::make('prix_unitaire_ht')
                ->label('PU HT')
                ->numeric()
                // Une ligne de service (voir DevisResource::ligneSchema()) ne
                // montre que son TTC.
                ->visible(fn (Get $get): bool => blank($get('service_id')))
                ->disabled(),
            TextInput::make('prix_unitaire_ttc')
                ->label('PU TTC')
                ->numeric()
                ->minValue(0)
                ->saisieEnDirect()
                ->required()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if (blank($state)) {
                        // Sur une ligne de service, le PU HT est caché : s'il
                        // gardait sa valeur, l'aperçu afficherait encore
                        // l'ancien total alors que le TTC a été effacé.
                        if (filled($get('service_id'))) {
                            $set('prix_unitaire_ht', null);
                        }

                        return;
                    }

                    try {
                        $set('prix_unitaire_ht', self::prixEnTexte(app(CalculateurLigne::class)->prixUnitaireHtDepuisTtc((float) $state, self::tauxTvaDeLigne($get))));
                    } catch (InvalidArgumentException) {
                        // Taux de TVA invalide (voir sa validation) : rien à convertir.
                    }
                }),
            TextInput::make('taux_tva')
                ->label('TVA %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required()
                ->default(20)
                ->saisieEnDirect()
                // Le PU TTC ne bouge jamais au changement de taux : seul son
                // PU HT est recalculé.
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    // Saisie incomplète en cours ("5," en route vers "5,5") :
                    // rien à recalculer tant que le taux n'est pas un nombre
                    // entre 0 et 100.
                    if (blank($state) || (float) $state < 0 || (float) $state > 100 || blank($get('prix_unitaire_ttc'))) {
                        return;
                    }

                    try {
                        $set('prix_unitaire_ht', self::prixEnTexte(app(CalculateurLigne::class)->prixUnitaireHtDepuisTtc((float) $get('prix_unitaire_ttc'), (float) $state)));
                    } catch (InvalidArgumentException) {
                        // Prix hors limites (voir sa validation) : rien à convertir.
                    }
                }),
        ];
    }

    /**
     * Passe par le même résolveur et le même calculateur que
     * l'enregistrement réel de la ligne : l'aperçu ne peut jamais afficher
     * un centime différent de ce qui sera enregistré. Montre le HT et la
     * TVA de la ligne à côté de son TTC, comme la facture.
     */
    protected static function totalDeLigne(): Placeholder
    {
        return Placeholder::make('total_ligne')
            ->label('Total TTC')
            ->columnSpanFull()
            ->content(fn (Get $get): string => self::montantEnTexte(
                self::montantsDeLigneSaisie(self::ligneSaisie($get))['montant_ttc'],
            ))
            ->helperText(function (Get $get): string {
                $montants = self::montantsDeLigneSaisie(self::ligneSaisie($get));

                return self::montantEnTexte($montants['montant_ht']).' HT + '.self::montantEnTexte($montants['montant_tva']).' de TVA';
            });
    }

    /**
     * Totaux du document en cours de saisie, sommés séparément comme à
     * l'enregistrement (voir CalculateurLigne::totaliser()).
     */
    public static function totauxDuDocument(): Placeholder
    {
        return Placeholder::make('totaux_document')
            ->label('Totaux')
            ->columnSpanFull()
            ->content(function (Get $get): string {
                try {
                    $totaux = app(CalculateurLigne::class)->totaliser(collect($get('lignes') ?? [])
                        ->map(fn (array $ligne): array => self::montantsDeLigneSaisie($ligne)));
                } catch (InvalidArgumentException) {
                    return '—';
                }

                return 'Total HT '.self::montantEnTexte($totaux['total_ht'])
                    .' · TVA '.self::montantEnTexte($totaux['total_tva'])
                    .' · Total TTC '.self::montantEnTexte($totaux['total_ttc']);
            });
    }

    private static function montantEnTexte(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    /**
     * Une ligne de vente en cours de saisie, calculée comme à
     * l'enregistrement (voir ConstructeurLigneDocument) : depuis son PU TTC.
     * Une saisie pas encore valable (prix vide ou négatif, quantité vide ou
     * à virgule, taux ou remise hors de 0-100 %) ne donne aucun montant,
     * plutôt qu'un montant faux (1,5 compté comme 1).
     *
     * @param  array<string, mixed>  $ligne
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public static function montantsDeLigneSaisie(array $ligne): array
    {
        $calculateur = app(CalculateurLigne::class);

        try {
            $prixUnitaireTtc = $calculateur->arrondirAuCentime((float) (filled($ligne['prix_unitaire_ttc'] ?? null)
                ? $ligne['prix_unitaire_ttc']
                : throw new InvalidArgumentException('PU TTC à saisir.')));

            if ($prixUnitaireTtc < 0) {
                throw new InvalidArgumentException('PU TTC négatif.');
            }

            return $calculateur->montantsDeVente(
                $calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, (float) ($ligne['taux_tva'] ?? 0)),
                $prixUnitaireTtc,
                QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null),
                (float) ($ligne['remise_pourcentage'] ?? 0),
            );
        } catch (InvalidArgumentException) {
            return self::AUCUN_MONTANT;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function ligneSaisie(Get $get): array
    {
        return [
            'prix_unitaire_ht' => $get('prix_unitaire_ht'),
            'prix_unitaire_ttc' => $get('prix_unitaire_ttc'),
            'quantite' => $get('quantite'),
            'taux_tva' => $get('taux_tva'),
            'remise_pourcentage' => $get('remise_pourcentage'),
        ];
    }

    /**
     * Même présentation qu'un prix relu en base (cast decimal:2) : "24.00",
     * pas "24".
     */
    protected static function prixEnTexte(float $prix): string
    {
        return number_format($prix, 2, '.', '');
    }

    /**
     * Un taux de TVA ou une remise tel qu'on l'écrit dans le champ : "20",
     * "5.5", "0" -- jamais "20.00" comme le relit le cast decimal:2.
     */
    public static function pourcentageEnTexte(float|string|null $pourcentage): string
    {
        return (string) (float) $pourcentage;
    }

    /**
     * TVA par défaut (20 %) tant que le champ taux_tva n'a pas encore été
     * hydraté, cohérent avec son propre ->default(20).
     */
    private static function tauxTvaDeLigne(Get $get): float
    {
        return (float) ($get('taux_tva') ?? 20);
    }
}
