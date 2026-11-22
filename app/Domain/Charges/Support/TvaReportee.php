<?php

namespace App\Domain\Charges\Support;

use App\Domain\Tenancy\Enums\CadenceDeLaTva;

/**
 * La TVA d'une société mois par mois, avec le report de son crédit période
 * après période (voir ReportDuCreditDeTva, son seul constructeur) : la TVA
 * de chaque mois que la chaîne a comptée, et le résultat de chaque période
 * de la chaîne. Le résultat d'une période se montre au dernier de ses mois
 * que PILIQI a comptés : le mois lui-même au mensuel, le troisième mois du
 * trimestre, ou le mois en cours pour le trimestre en cours (voir
 * fermeSaPeriode()). Un mois d'avant la première période de la chaîne n'a
 * que sa TVA du mois.
 */
final readonly class TvaReportee
{
    /**
     * @param  array<string, MontantsDeTva>  $parMois  « AAAA-MM » => la TVA du mois, dans l'ordre
     * @param  array<string, ReportDeLaPeriode>  $parPeriode  la période (son premier mois) => son résultat, dans l'ordre
     * @param  array<string, string>  $moisQuiFerment  la période => le mois qui montre son résultat
     */
    public function __construct(
        public CadenceDeLaTva $cadence,
        public string $premierePeriode,
        public array $parMois,
        public array $parPeriode,
        private array $moisQuiFerment,
    ) {}

    /**
     * La TVA de ce mois, ou null quand la chaîne ne l'a pas comptée.
     */
    public function duMois(string $mois): ?MontantsDeTva
    {
        return $this->parMois[$mois] ?? null;
    }

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
