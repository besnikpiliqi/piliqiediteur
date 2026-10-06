<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Facturation\Services\ResolveurPrixLigne;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use InvalidArgumentException;

/**
 * PU HT, PU TTC et TVA d'une ligne de document (vente comme achat) : les
 * deux prix restent visibles et saisissables, et c'est le dernier saisi qui
 * fait foi pour le calcul de la ligne (voir PrixReference) -- l'autre n'en
 * est que la conversion au centime.
 */
trait SaisitLesPrixDeLigne
{
    /**
     * @return array<Component>
     */
    protected static function champsPrixDeLigne(PrixReference $referenceParDefaut): array
    {
        return [
            Hidden::make('prix_reference')->default($referenceParDefaut->value),
            TextInput::make('prix_unitaire_ht')
                ->label('PU HT')
                ->numeric()
                ->saisieEnDirect()
                ->required(fn (Get $get) => self::referenceDeLigne($get, $referenceParDefaut) === PrixReference::Ht)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if (blank($state)) {
                        return;
                    }

                    $set('prix_reference', PrixReference::Ht->value);

                    try {
                        $set('prix_unitaire_ttc', self::prixEnTexte(app(CalculateurLigne::class)->prixUnitaireTtcDepuisHt((float) $state, self::tauxTvaDeLigne($get))));
                    } catch (InvalidArgumentException) {
                        // Taux de TVA invalide (voir sa validation) : rien à convertir.
                    }
                }),
            TextInput::make('prix_unitaire_ttc')
                ->label('PU TTC')
                ->numeric()
                ->saisieEnDirect()
                ->required(fn (Get $get) => self::referenceDeLigne($get, $referenceParDefaut) === PrixReference::Ttc)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if (blank($state)) {
                        return;
                    }

                    $set('prix_reference', PrixReference::Ttc->value);

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
                // Le prix qui fait foi ne bouge jamais au changement de taux :
                // seul l'autre prix est recalculé.
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($referenceParDefaut) {
                    // Saisie incomplète en cours ("5," en route vers "5,5") :
                    // rien à recalculer tant que le taux n'est pas un nombre
                    // entre 0 et 100.
                    if (blank($state) || (float) $state < 0 || (float) $state > 100) {
                        return;
                    }

                    $calculateur = app(CalculateurLigne::class);
                    $tauxTva = (float) $state;

                    if (self::referenceDeLigne($get, $referenceParDefaut) === PrixReference::Ht) {
                        if (filled($get('prix_unitaire_ht'))) {
                            $set('prix_unitaire_ttc', self::prixEnTexte($calculateur->prixUnitaireTtcDepuisHt((float) $get('prix_unitaire_ht'), $tauxTva)));
                        }

                        return;
                    }

                    if (filled($get('prix_unitaire_ttc'))) {
                        $set('prix_unitaire_ht', self::prixEnTexte($calculateur->prixUnitaireHtDepuisTtc((float) $get('prix_unitaire_ttc'), $tauxTva)));
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
    protected static function totalDeLigne(PrixReference $referenceParDefaut): Placeholder
    {
        return Placeholder::make('total_ligne')
            ->label('Total TTC')
            ->columnSpanFull()
            ->content(fn (Get $get): string => self::montantEnTexte(
                self::montantsDeLigneSaisie(self::ligneSaisie($get), $referenceParDefaut)['montant_ttc'],
            ))
            ->helperText(function (Get $get) use ($referenceParDefaut): string {
                $montants = self::montantsDeLigneSaisie(self::ligneSaisie($get), $referenceParDefaut);

                return self::montantEnTexte($montants['montant_ht']).' HT + '.self::montantEnTexte($montants['montant_tva']).' de TVA · '
                    .'calculé à partir du '.self::referenceDeLigne($get, $referenceParDefaut)->getLabel().' saisi.';
            });
    }

    /**
     * Totaux du document en cours de saisie, sommés séparément comme à
     * l'enregistrement (voir CalculateurLigne::totaliser()).
     */
    public static function totauxDuDocument(PrixReference $referenceParDefaut): Placeholder
    {
        return Placeholder::make('totaux_document')
            ->label('Totaux')
            ->columnSpanFull()
            ->content(function (Get $get) use ($referenceParDefaut): string {
                $totaux = app(CalculateurLigne::class)->totaliser(collect($get('lignes') ?? [])
                    ->map(fn (array $ligne): array => self::montantsDeLigneSaisie($ligne, $referenceParDefaut)));

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
     * @param  array<string, mixed>  $ligne
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public static function montantsDeLigneSaisie(array $ligne, PrixReference $referenceParDefaut): array
    {
        $calculateur = app(CalculateurLigne::class);
        $tauxTva = (float) ($ligne['taux_tva'] ?? 0);

        // Une saisie pas encore valable (quantité vide ou à virgule, taux ou
        // remise hors de 0-100 %) ne donne aucun montant, plutôt qu'un
        // montant faux (1,5 compté comme 1).
        try {
            $quantite = QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null);
            $prix = app(ResolveurPrixLigne::class)->resoudre($ligne, $referenceParDefaut, $tauxTva);

            return $calculateur->calculer(
                $prix['prix_unitaire_ht'],
                $prix['prix_unitaire_ttc'],
                $quantite,
                (float) ($ligne['remise_pourcentage'] ?? 0),
            );
        } catch (InvalidArgumentException) {
            return ['montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function ligneSaisie(Get $get): array
    {
        return [
            'prix_reference' => $get('prix_reference'),
            'prix_unitaire_ht' => $get('prix_unitaire_ht'),
            'prix_unitaire_ttc' => $get('prix_unitaire_ttc'),
            'quantite' => $get('quantite'),
            'taux_tva' => $get('taux_tva'),
            'remise_pourcentage' => $get('remise_pourcentage'),
        ];
    }

    private static function referenceDeLigne(Get $get, PrixReference $referenceParDefaut): PrixReference
    {
        return PrixReference::depuisSaisie(self::ligneSaisie($get), $referenceParDefaut);
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
