<?php

namespace App\Domain\Charges\Support;

/**
 * La TVA d'une société sur un mois (voir TvaDeLaPeriode), en centimes : la
 * TVA collectée des ventes nettes des avoirs et celle autoliquidée des
 * charges validées d'un fournisseur établi hors de France, la TVA
 * déductible des achats de marchandises, celle des charges validées et
 * celle des charges à valider, avec l'autoliquidation de celles-ci. La TVA
 * à payer réelle ne compte que les charges validées ; l'estimation compte
 * aussi les charges à valider. Sans les charges (un compte qui ne les voit
 * pas), elles valent zéro, et l'écran le dit.
 */
final readonly class MontantsDeTva
{
    public function __construct(
        public int $collecteeDesVentes,
        public int $autoliquideeValidee,
        public int $deductibleDesAchats,
        public int $deductibleDesChargesValidees,
        public int $autoliquideeAValider,
        public int $deductibleDesChargesAValider,
        public bool $avecLesCharges,
    ) {}

    public function collectee(): int
    {
        return $this->collecteeDesVentes + $this->autoliquideeValidee;
    }

    public function deductible(): int
    {
        return $this->deductibleDesAchats + $this->deductibleDesChargesValidees;
    }

    /**
     * La TVA à payer réelle : collectée moins déductible des achats et des
     * charges validées.
     */
    public function reelle(): int
    {
        return $this->collectee() - $this->deductible();
    }

    /**
     * La TVA à payer estimée : la réelle, avec aussi les charges à valider
     * (leur autoliquidation due, leur TVA déductible).
     */
    public function estimee(): int
    {
        return $this->reelle() + $this->autoliquideeAValider - $this->deductibleDesChargesAValider;
    }

    /**
     * Des charges à valider changent-elles l'estimation ?
     */
    public function aDesChargesAValider(): bool
    {
        return $this->autoliquideeAValider !== 0 || $this->deductibleDesChargesAValider !== 0;
    }

    /**
     * La somme de ces montants, ceux des mois d'un trimestre (voir
     * ReportDuCreditDeTva), additionnés en centimes, chaque part avec la
     * sienne ; avec les charges quand chacun les compte.
     *
     * @param  list<self>  $montants
     */
    public static function additionner(array $montants, bool $avecLesCharges): self
    {
        $somme = new self(0, 0, 0, 0, 0, 0, $avecLesCharges);

        foreach ($montants as $unMontant) {
            $somme = new self(
                $somme->collecteeDesVentes + $unMontant->collecteeDesVentes,
                $somme->autoliquideeValidee + $unMontant->autoliquideeValidee,
                $somme->deductibleDesAchats + $unMontant->deductibleDesAchats,
                $somme->deductibleDesChargesValidees + $unMontant->deductibleDesChargesValidees,
                $somme->autoliquideeAValider + $unMontant->autoliquideeAValider,
                $somme->deductibleDesChargesAValider + $unMontant->deductibleDesChargesAValider,
                $avecLesCharges && $unMontant->avecLesCharges,
            );
        }

        return $somme;
    }
}
