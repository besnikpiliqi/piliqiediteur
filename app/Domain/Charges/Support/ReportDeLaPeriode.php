<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Enums\StatutDuReport;

/**
 * Une période de TVA (un mois au mensuel, un trimestre civil au
 * trimestriel) après le report du crédit (voir ReportDuCreditDeTva) : la
 * somme des TVA de ses mois que PILIQI connaît, son statut, et ses deux
 * résultats, chacun avec sa propre chaîne : la TVA réelle, avec les seules
 * charges validées, et son estimation, avec aussi les charges à valider.
 */
final readonly class ReportDeLaPeriode
{
    public function __construct(
        public string $periode,
        public StatutDuReport $statut,
        public MontantsDeTva $montants,
        public SoldeDeLaPeriode $reelle,
        public SoldeDeLaPeriode $estimee,
    ) {}

    /**
     * L'estimation, avec les charges à valider, change-t-elle son résultat ?
     * Celles de la période, ou celles d'une période d'avant, dont le crédit
     * estimé se reporte jusqu'à elle ; jamais pour la période déclarée qui
     * sert de point de départ, dont le crédit est celui de sa déclaration.
     * Le résultat se déduisant de la TVA de la période et du crédit
     * reporté, ils suffisent à le dire.
     */
    public function estimationDifferente(): bool
    {
        return $this->statut !== StatutDuReport::Declaree
            && ($this->estimee->tva !== $this->reelle->tva || $this->estimee->creditReporte !== $this->reelle->creditReporte);
    }
}
