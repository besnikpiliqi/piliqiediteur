<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Enums\StatutDuReport;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;

/**
 * Le report du crédit de TVA (question de l'éditeur du 15/11/2026, choix
 * K2 ; règle de l'éditeur du 18/11/2026, maquette « Message TVA avant
 * PILIQI » approuvée) : le seul calcul de la chaîne, en centimes entiers,
 * sur les TVA de chaque mois déjà additionnées (voir TvaDeLaPeriode), sans
 * jamais recalculer la TVA d'un total.
 *
 * Pour chaque période (un mois au mensuel, un trimestre civil au
 * trimestriel, dont les trois mois s'additionnent d'abord) : la TVA de la
 * période est la collectée moins la déductible des achats et des
 * charges ; de cette TVA, on retire le crédit reporté de la période
 * d'avant ; positif, le résultat est la TVA à payer, négatif, il devient
 * le crédit à reporter sur la période suivante. La réelle (avec les seules
 * charges validées) et l'estimation (avec aussi les charges à valider) ont
 * chacune leur chaîne.
 *
 * La chaîne part de la première période que PILIQI compte (celle de
 * l'arrivée de la société, voir CreditsDeTva::premierePeriode()), avec un
 * crédit de zéro, que rien ne déclare encore : la société déclare, quand
 * elle le peut, le crédit d'une de ses déclarations, d'une période de son
 * choix depuis son arrivée ; la plus récente sert d'ancre. La période de
 * l'ancre prend son crédit déclaré pour crédit à reporter, sa TVA à payer
 * restant celle de sa déclaration ; chaque période qui la suit part de ce
 * crédit. Le chiffre d'une période ne dépend jamais de la période
 * affichée : la chaîne se calcule toujours depuis son début.
 */
final class ReportDuCreditDeTva
{
    /**
     * @param  CadenceDeLaTva  $cadence  celle du régime de la société
     * @param  string  $premierePeriode  la première période de la chaîne
     * @param  array{periode: string, credit: int}|null  $ancre  la période de la déclaration qui sert d'ancre et son crédit déclaré, en centimes (0 sans crédit)
     * @param  array<string, MontantsDeTva>  $parMois  la TVA de chaque mois « AAAA-MM », du premier mois de la première période (ou d'avant) au dernier mois compté
     * @param  bool  $avecLesCharges  les montants comptent-ils les charges ?
     */
    public static function calculer(CadenceDeLaTva $cadence, string $premierePeriode, ?array $ancre, array $parMois, bool $avecLesCharges): TvaReportee
    {
        ksort($parMois);
        $ancre = $ancre !== null && $ancre['periode'] >= $premierePeriode ? $ancre : null;
        $moisParPeriode = [];

        foreach (array_keys($parMois) as $mois) {
            $moisParPeriode[$cadence->periodeDu($mois)][] = $mois;
        }

        $parPeriode = [];
        $moisQuiFerment = [];
        $reportee = 0;
        $estimee = 0;

        foreach ($moisParPeriode as $periode => $sesMois) {
            if ($periode < $premierePeriode) {
                continue;
            }

            $montants = MontantsDeTva::additionner(array_map(static fn (string $mois): MontantsDeTva => $parMois[$mois], $sesMois), $avecLesCharges);

            if ($ancre !== null && $periode === $ancre['periode']) {
                $parPeriode[$periode] = new ReportDeLaPeriode(
                    $periode,
                    StatutDuReport::Declaree,
                    $montants,
                    new SoldeDeLaPeriode($montants->reelle(), $reportee, null, $ancre['credit']),
                    new SoldeDeLaPeriode($montants->estimee(), $estimee, null, $ancre['credit']),
                );
                $reportee = $ancre['credit'];
                $estimee = $ancre['credit'];
            } else {
                $soldeReel = self::solder($montants->reelle(), $reportee);
                $soldeEstime = self::solder($montants->estimee(), $estimee);
                $parPeriode[$periode] = new ReportDeLaPeriode(
                    $periode,
                    $ancre !== null && $periode > $ancre['periode'] ? StatutDuReport::AvecCreditDeclare : StatutDuReport::SansCreditDeclare,
                    $montants,
                    $soldeReel,
                    $soldeEstime,
                );
                $reportee = $soldeReel->creditAReporter;
                $estimee = $soldeEstime->creditAReporter;
            }

            $moisQuiFerment[$periode] = $sesMois[count($sesMois) - 1];
        }

        return new TvaReportee($cadence, $premierePeriode, $parMois, $parPeriode, $moisQuiFerment);
    }

    /**
     * La TVA d'une période moins le crédit reporté : la TVA à payer quand
     * le résultat est positif ou nul, le crédit à reporter quand il est
     * négatif.
     */
    private static function solder(int $tva, int $creditReporte): SoldeDeLaPeriode
    {
        $net = $tva - $creditReporte;

        return new SoldeDeLaPeriode($tva, $creditReporte, max(0, $net), max(0, -$net));
    }
}
