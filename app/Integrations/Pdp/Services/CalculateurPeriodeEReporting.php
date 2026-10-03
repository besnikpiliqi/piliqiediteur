<?php

namespace App\Integrations\Pdp\Services;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Integrations\Pdp\Contracts\PeriodeEReporting;
use Carbon\CarbonImmutable;

/**
 * Détermine la période d'e-reporting (et sa date limite légale de
 * transmission) applicable à un régime de TVA donné -- voir le docblock de
 * RegimeTva. La clôture de caisse quotidienne (voir ClotureCaisse) agrège
 * les ventes JOUR PAR JOUR, mais la transmission au PDP se fait par lots
 * sur une période plus large, dont la durée dépend du régime :
 *
 *  - Réel normal mensuel : par décade (1-10, 11-20, 21-fin de mois),
 *    limite le 20, la fin du mois, et le 10 du mois suivant respectivement.
 *  - Réel normal trimestriel et réel simplifié : par mois calendaire,
 *    limite le 10 (trimestriel) ou le 30 (simplifié) du mois suivant.
 *  - Franchise en base : par bimestre calendaire (janvier-février,
 *    mars-avril, ...), limite le 30 du mois suivant la fin du bimestre.
 *
 * Les dates limites "25-30 du mois suivant" mentionnées par la
 * documentation DGFiP pour le réel simplifié et la franchise en base sont
 * ici fixées au 30 (ou au dernier jour du mois si le mois en compte moins) :
 * une simplification délibérée en l'absence d'une date unique publiée,
 * à affiner si la spécification externe B2B publie une date exacte.
 */
class CalculateurPeriodeEReporting
{
    /**
     * La période la plus récente déjà terminée à la date donnée
     * (généralement "aujourd'hui", depuis la commande planifiée) : jamais
     * la période en cours, qui n'est par définition pas encore terminée.
     */
    public function periodePrecedente(RegimeTva $regime, CarbonImmutable $aujourdHui): PeriodeEReporting
    {
        return match ($regime) {
            RegimeTva::ReelNormalMensuel => $this->decadePrecedente($aujourdHui),
            RegimeTva::ReelNormalTrimestriel => $this->moisPrecedent($aujourdHui, joursLimiteMoisSuivant: 10),
            RegimeTva::ReelSimplifie => $this->moisPrecedent($aujourdHui, joursLimiteMoisSuivant: 30),
            RegimeTva::FranchiseEnBase => $this->bimestrePrecedent($aujourdHui),
        };
    }

    /**
     * @return list<PeriodeEReporting>
     */
    private function decadesDuMois(CarbonImmutable $moisDeReference): array
    {
        $debutMois = $moisDeReference->startOfMonth();
        $finMois = $moisDeReference->endOfMonth();
        $premierDuMoisSuivant = $debutMois->addMonthNoOverflow();

        return [
            new PeriodeEReporting($debutMois, $debutMois->addDays(9), $debutMois->addDays(19)),
            new PeriodeEReporting($debutMois->addDays(10), $debutMois->addDays(19), $finMois),
            new PeriodeEReporting($debutMois->addDays(20), $finMois, $premierDuMoisSuivant->addDays(9)),
        ];
    }

    private function decadePrecedente(CarbonImmutable $aujourdHui): PeriodeEReporting
    {
        $candidates = [
            ...$this->decadesDuMois($aujourdHui->subMonthNoOverflow()),
            ...$this->decadesDuMois($aujourdHui),
        ];

        $passees = array_values(array_filter(
            $candidates,
            fn (PeriodeEReporting $periode) => $periode->fin->lt($aujourdHui->startOfDay()),
        ));

        usort($passees, fn (PeriodeEReporting $a, PeriodeEReporting $b) => $b->fin->getTimestamp() <=> $a->fin->getTimestamp());

        return $passees[0];
    }

    private function moisPrecedent(CarbonImmutable $aujourdHui, int $joursLimiteMoisSuivant): PeriodeEReporting
    {
        $debut = $aujourdHui->subMonthNoOverflow()->startOfMonth();
        $fin = $debut->endOfMonth();
        $limite = $this->dateDuMois($fin->addDay(), $joursLimiteMoisSuivant);

        return new PeriodeEReporting($debut, $fin, $limite);
    }

    private function bimestrePrecedent(CarbonImmutable $aujourdHui): PeriodeEReporting
    {
        $indexBimestreCourant = intdiv($aujourdHui->month - 1, 2);
        $indexBimestrePrecedent = $indexBimestreCourant - 1;
        $annee = $aujourdHui->year;

        if ($indexBimestrePrecedent < 0) {
            $indexBimestrePrecedent = 5;
            $annee--;
        }

        $debut = CarbonImmutable::create($annee, ($indexBimestrePrecedent * 2) + 1, 1)->startOfMonth();
        $fin = $debut->addMonthNoOverflow()->endOfMonth();
        $limite = $this->dateDuMois($fin->addDay(), 30);

        return new PeriodeEReporting($debut, $fin, $limite);
    }

    /**
     * Le jour demandé, plafonné au nombre réel de jours du mois (ex: le 30
     * février n'existe pas) -- voir le docblock de classe sur le choix du
     * jour 30 comme simplification pour le réel simplifié/la franchise.
     */
    private function dateDuMois(CarbonImmutable $mois, int $jour): CarbonImmutable
    {
        $moisDebut = $mois->startOfMonth();

        return $moisDebut->addDays(min($jour, $moisDebut->daysInMonth) - 1);
    }
}
