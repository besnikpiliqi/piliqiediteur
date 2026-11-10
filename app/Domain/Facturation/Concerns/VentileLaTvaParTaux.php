<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

/**
 * Récapitulatif par taux de TVA d'un document à lignes (mention obligatoire
 * sur une facture qui mélange plusieurs taux) : pour chaque taux, la somme
 * exacte des HT, des TVA et des TTC de ses lignes -- les totaux du document
 * en sont donc toujours la somme visible.
 *
 * Exposé comme attribut (ventilation_tva) pour que les fiches Filament et
 * les PDF le lisent comme n'importe quelle autre donnée du document. Les
 * totaux d'une clôture de caisse sont ceux de ses tickets moins ceux de ses
 * avoirs, donc sa ventilation est celle des lignes de ces tickets moins
 * celles de ces avoirs ; ce que l'e-reporting en déclarera, par catégorie
 * et par taux, se lit à part (voir EReportingDesParticuliers).
 */
trait VentileLaTvaParTaux
{
    /**
     * @return Attribute<list<array{taux_tva: string, montant_ht: float, montant_tva: float, montant_ttc: float}>, never>
     */
    protected function ventilationTva(): Attribute
    {
        return Attribute::get(fn (): array => self::ventilerParTaux($this->lignesAVentiler()));
    }

    /**
     * Les lignes du document lui-même ; une clôture de caisse, qui en
     * regroupe d'autres, ventile les lignes de ses tickets, et en négatif
     * celles de ses avoirs.
     *
     * @return Collection<int, array{taux_tva: string, montant_ht: string, montant_tva: string, montant_ttc: string}>
     */
    protected function lignesAVentiler(): Collection
    {
        return self::montantsDesLignes($this->lignes);
    }

    /**
     * @template TLigne of LigneFacture|LigneAvoir|LigneDevis
     *
     * @param  Collection<int, TLigne>  $lignes
     * @return Collection<int, array{taux_tva: string, montant_ht: string, montant_tva: string, montant_ttc: string}>
     */
    protected static function montantsDesLignes(Collection $lignes): Collection
    {
        return $lignes->map(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): array => [
            'taux_tva' => $ligne->taux_tva,
            'montant_ht' => $ligne->montant_ht,
            'montant_tva' => $ligne->montant_tva,
            'montant_ttc' => $ligne->montant_ttc,
        ])->toBase();
    }

    /**
     * Les lignes d'avoirs, qui se déduisent des ventes : leurs montants en
     * négatif, au centime.
     *
     * @template TLigne of LigneFacture|LigneAvoir|LigneDevis
     *
     * @param  Collection<int, TLigne>  $lignes
     * @return Collection<int, array{taux_tva: string, montant_ht: string, montant_tva: string, montant_ttc: string}>
     */
    protected static function montantsDeduits(Collection $lignes): Collection
    {
        $calculateur = app(CalculateurLigne::class);
        $oppose = fn (string $montant): string => $calculateur->enDecimal(-$calculateur->enCentiemes($montant));

        return $lignes->map(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): array => [
            'taux_tva' => $ligne->taux_tva,
            'montant_ht' => $oppose($ligne->montant_ht),
            'montant_tva' => $oppose($ligne->montant_tva),
            'montant_ttc' => $oppose($ligne->montant_ttc),
        ])->toBase();
    }

    /**
     * @param  Collection<int, array{taux_tva: string, montant_ht: string, montant_tva: string, montant_ttc: string}>  $lignes
     * @return list<array{taux_tva: string, montant_ht: float, montant_tva: float, montant_ttc: float}>
     */
    private static function ventilerParTaux(Collection $lignes): array
    {
        return array_values($lignes
            ->groupBy(fn (array $ligne): string => number_format((float) $ligne['taux_tva'], 2, '.', ''))
            ->sortKeysUsing(fn (string $a, string $b): int => (float) $b <=> (float) $a)
            ->map(function (Collection $lignesDuTaux, string $tauxTva): array {
                $totaux = app(CalculateurLigne::class)->totaliser($lignesDuTaux);

                return [
                    'taux_tva' => $tauxTva,
                    'montant_ht' => $totaux['total_ht'],
                    'montant_tva' => $totaux['total_tva'],
                    'montant_ttc' => $totaux['total_ttc'],
                ];
            })
            ->all());
    }
}
