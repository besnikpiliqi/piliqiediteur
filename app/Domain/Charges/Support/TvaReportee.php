<?php

namespace App\Domain\Charges\Support;

use App\Domain\Tenancy\Enums\CadenceDeLaTva;

/**
 * Le report du crédit de TVA d'une société période après période (voir
 * ReportDuCreditDeTva, son seul constructeur) : le résultat de chaque
 * période de la chaîne. Le résultat d'une période se montre au dernier de
 * ses mois que PILIQI a comptés : le mois lui-même au mensuel, le
 * troisième mois du trimestre, ou le mois en cours pour le trimestre en
 * cours (voir fermeSaPeriode()). Un mois d'avant la première période de la
 * chaîne n'a pas de résultat.
 */
final readonly class TvaReportee
{
    /**
     * @param  array<string, ReportDeLaPeriode>  $parPeriode  la période (son premier mois) => son résultat, dans l'ordre
     * @param  array<string, string>  $moisQuiFerment  la période => le mois qui montre son résultat
     */
    public function __construct(
        public CadenceDeLaTva $cadence,
        private array $parPeriode,
        private array $moisQuiFerment,
    ) {}

    /**
     * Le résultat de la période de ce mois, quel que soit le mois de la
     * période ; null hors de la chaîne.
     */
    public function deLaPeriodeDu(string $mois): ?ReportDeLaPeriode
    {
        return $this->parPeriode[$this->cadence->periodeDu($mois)] ?? null;
    }

    /**
     * Ce mois est-il celui qui montre le résultat de sa période ?
     */
    public function fermeSaPeriode(string $mois): bool
    {
        return ($this->moisQuiFerment[$this->cadence->periodeDu($mois)] ?? null) === $mois;
    }

    /**
     * Le résultat que ce mois montre : celui de sa période quand il la
     * ferme (voir fermeSaPeriode()), sinon null.
     */
    public function montrePar(string $mois): ?ReportDeLaPeriode
    {
        return $this->fermeSaPeriode($mois) ? $this->deLaPeriodeDu($mois) : null;
    }
}
