<?php

namespace App\Domain\Charges\Support;

/**
 * Le résultat d'une période de TVA, réelle ou estimée, après le report du
 * crédit (voir ReportDuCreditDeTva), en centimes : la TVA de la période
 * (collectée moins déductible), le crédit reporté de la période d'avant,
 * puis, de la TVA de la période moins ce crédit, la TVA à payer quand le
 * résultat est positif, le crédit de TVA à reporter sur la période
 * suivante quand il est négatif. La TVA à payer d'une période déclarée
 * (l'ancre) est celle de sa déclaration, que PILIQI ne connaît pas : null.
 */
final readonly class SoldeDeLaPeriode
{
    public function __construct(
        public int $tva,
        public int $creditReporte,
        public ?int $aPayer,
        public int $creditAReporter,
    ) {}

    /**
     * La TVA à payer au-dessus de zéro, ou le crédit à reporter en dessous :
     * ce que dessine le graphique.
     */
    public function signe(): int
    {
        return ($this->aPayer ?? 0) - $this->creditAReporter;
    }
}
