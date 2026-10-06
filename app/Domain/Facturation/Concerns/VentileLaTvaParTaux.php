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
 * ceux de leurs factures, donc leur ventilation est celle des lignes de
 * ces factures.
 */
trait VentileLaTvaParTaux
{
    /**
     * @return Attribute<list<array{taux_tva: string, montant_ht: float, montant_tva: float, montant_ttc: float}>, never>
     */
    protected function ventilationTva(): Attribute
    {
        return Attribute::get(fn (): array => $this->lignesAVentiler()
            ->groupBy(fn (Model $ligne): string => number_format((float) $ligne->taux_tva, 2, '.', ''))
            ->sortKeysUsing(fn (string $a, string $b): int => (float) $b <=> (float) $a)
            ->map(function (Collection $lignes, string $tauxTva): array {
                $totaux = app(CalculateurLigne::class)->totaliser(
                    $lignes->map(fn (Model $ligne): array => $ligne->only(['montant_ht', 'montant_tva', 'montant_ttc'])),
                );

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
     * lignes de ses factures.
     *
     * @return Collection<int, Model>
     */
    protected function lignesAVentiler(): Collection
    {
        return $this->lignes;
    }
}
