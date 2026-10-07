<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Récapitulatif par taux de TVA d'un document à lignes (mention obligatoire
 * sur une facture qui mélange plusieurs taux) : pour chaque taux, la somme
 * exacte des HT, des TVA et des TTC de ses lignes -- les totaux du document
 * en sont donc toujours la somme visible.
 *
 * Exposé comme attribut (ventilation_tva) pour que les fiches Filament et
 * les PDF le lisent comme n'importe quelle autre donnée du document. Les
 * totaux d'une clôture de caisse ou d'une soumission d'e-reporting sont
 * ceux de leurs factures moins ceux de leurs avoirs, donc leur ventilation
 * est celle des lignes de ces factures moins celles de ces avoirs.
 */
trait VentileLaTvaParTaux
{
    /**
     * @return Attribute<list<array{taux_tva: string, montant_ht: float, montant_tva: float, montant_ttc: float}>, never>
     */
    protected function ventilationTva(): Attribute
    {
        return Attribute::get(fn (): array => $this->lignesAVentiler()
            ->groupBy(fn (array $ligne): string => number_format((float) $ligne['taux_tva'], 2, '.', ''))
            ->sortKeysUsing(fn (string $a, string $b): int => (float) $b <=> (float) $a)
            ->map(function (Collection $lignes, string $tauxTva): array {
                $totaux = app(CalculateurLigne::class)->totaliser($lignes);

                return [
                    'taux_tva' => $tauxTva,
                    'montant_ht' => $totaux['total_ht'],
                    'montant_tva' => $totaux['total_tva'],
                    'montant_ttc' => $totaux['total_ttc'],
                ];
            })
            ->values()
            ->all());
    }

    /**
     * Les lignes du document lui-même ; un document qui en regroupe
     * d'autres (clôture de caisse, soumission d'e-reporting) ventile les
     * lignes de ses factures, et en négatif celles de ses avoirs.
     *
     * @return Collection<int, array{taux_tva: mixed, montant_ht: mixed, montant_tva: mixed, montant_ttc: mixed}>
     */
    protected function lignesAVentiler(): Collection
    {
        return self::montantsDesLignes($this->lignes);
    }

    /**
     * @param  Collection<int, Model>  $lignes
     * @return Collection<int, array{taux_tva: mixed, montant_ht: mixed, montant_tva: mixed, montant_ttc: mixed}>
     */
    protected static function montantsDesLignes(Collection $lignes): Collection
    {
        return $lignes->map(fn (Model $ligne): array => $ligne->only(['taux_tva', 'montant_ht', 'montant_tva', 'montant_ttc']))->toBase();
    }

    /**
     * Les lignes d'avoirs, qui se déduisent des ventes.
     *
     * @param  Collection<int, Model>  $lignes
     * @return Collection<int, array{taux_tva: mixed, montant_ht: float, montant_tva: float, montant_ttc: float}>
     */
    protected static function montantsDeduits(Collection $lignes): Collection
    {
        return $lignes->map(fn (Model $ligne): array => [
            'taux_tva' => $ligne->taux_tva,
            'montant_ht' => -(float) $ligne->montant_ht,
            'montant_tva' => -(float) $ligne->montant_tva,
            'montant_ttc' => -(float) $ligne->montant_ttc,
        ])->toBase();
    }
}
