<?php

namespace Tests\Unit;

use App\Domain\Charges\Enums\StatutDuReport;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Charges\Support\ReportDeLaPeriode;
use App\Domain\Charges\Support\ReportDuCreditDeTva;
use App\Domain\Charges\Support\SoldeDeLaPeriode;
use App\Domain\Charges\Support\TextesDuCreditDeTva;
use App\Domain\Charges\Support\TvaReportee;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use Tests\TestCase;

/**
 * Le report du crédit de TVA (lot TVA27, choix K2 de l'éditeur du
 * 15/11/2026, maquette « Message TVA avant PILIQI » approuvée le
 * 18/11/2026 ; voir ReportDuCreditDeTva), sur des montants écrits à la
 * main, en centimes : la chaîne mensuelle de la maquette du report
 * (−16,40 € puis −21,00 € font un crédit de 37,40 €, que 140,00 € de TVA
 * ramènent à 102,60 € à payer), la même partie d'un crédit de départ de
 * 1 250,00 €, « aucun crédit », le trimestre dont les trois mois
 * s'additionnent, l'estimation sur sa propre chaîne, la déclaration qui
 * sert de point de départ et celle d'avant l'arrivée, ignorée. Le calcul
 * n'écrit ni ne lit rien en base : seuls les textes passent par
 * l'application, pour l'écriture des montants.
 */
class ReportDuCreditDeTvaTest extends TestCase
{
    /**
     * La maquette du report : janvier −16,40 €, février −21,00 €, mars
     * 140,00 € ; le crédit grossit, puis le mois positif l'épuise.
     */
    public function test_la_chaine_mensuelle_de_la_maquette(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2027-01', null, ['2027-01' => self::tva(-1640), '2027-02' => self::tva(-2100), '2027-03' => self::tva(14000)]);

        $this->assertSame([-1640, 0, 0, 1640], self::solde($report, '2027-01'));
        $this->assertSame([-2100, 1640, 0, 3740], self::solde($report, '2027-02'));
        $this->assertSame([14000, 3740, 10260, 0], self::solde($report, '2027-03'));
        $this->assertSame(StatutDuReport::SansCreditDeclare, $report->deLaPeriodeDu('2027-03')?->statut);
        $this->assertSame('TVA du mois 140,00 € − crédit reporté 37,40 € = 102,60 € à payer.', TextesDuCreditDeTva::calculDeLaPeriode(CadenceDeLaTva::Mensuelle, '2027-03', self::periode($report, '2027-03')->reelle, StatutDuReport::SansCreditDeclare));
    }

    /**
     * Un crédit de départ de 1 250,00 € (un solde de −1 250,00 €) déclaré
     * pour décembre 2026 : décembre est « déclaré », sans TVA à payer
     * connue ; janvier, 2 240,00 € de TVA du mois, paie 990,00 € (la carte
     * de la maquette) ; un mois en crédit ensuite reprend la chaîne.
     */
    public function test_un_credit_de_depart_de_1_250_euros_entre_dans_la_chaine_a_la_periode_qui_suit_sa_declaration(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2026-11', ['periode' => '2026-12', 'credit' => 125000], [
            '2026-11' => self::tva(184230),
            '2026-12' => self::tva(50000),
            '2027-01' => self::tva(224000),
            '2027-02' => self::tva(-3000),
            '2027-03' => self::tva(1000),
        ]);

        $this->assertSame(StatutDuReport::SansCreditDeclare, self::periode($report, '2026-11')->statut);
        $this->assertSame([184230, 0, 184230, 0], self::solde($report, '2026-11'));
        $this->assertSame(StatutDuReport::Declaree, self::periode($report, '2026-12')->statut);
        $this->assertSame([50000, 0, null, 125000], self::solde($report, '2026-12'));
        $this->assertSame(StatutDuReport::AvecCreditDeclare, self::periode($report, '2027-01')->statut);
        $this->assertSame([224000, 125000, 99000, 0], self::solde($report, '2027-01'));
        $this->assertSame([-3000, 0, 0, 3000], self::solde($report, '2027-02'));
        $this->assertSame([1000, 3000, 0, 2000], self::solde($report, '2027-03'));
        $this->assertSame('TVA du mois 2 240,00 € − crédit reporté 1 250,00 € = 990,00 € à payer.', TextesDuCreditDeTva::calculDeLaPeriode(CadenceDeLaTva::Mensuelle, '2027-01', self::periode($report, '2027-01')->reelle, StatutDuReport::AvecCreditDeclare));
        $this->assertSame('Déclaration de décembre 2026 : 1 250,00 € de crédit à reporter déclaré.', TextesDuCreditDeTva::calculDeLaPeriode(CadenceDeLaTva::Mensuelle, '2026-12', self::periode($report, '2026-12')->reelle, StatutDuReport::Declaree));
    }

    /**
     * « Non, aucun crédit » : un solde de 0 € déclaré remet la chaîne à
     * zéro, même quand PILIQI avait compté un crédit avant ; la période
     * déclarée le dit.
     */
    public function test_aucun_credit_declare_remet_la_chaine_a_zero(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2027-01', ['periode' => '2027-02', 'credit' => 0], ['2027-01' => self::tva(-5000), '2027-02' => self::tva(-2000), '2027-03' => self::tva(3000)]);

        $this->assertSame([-5000, 0, 0, 5000], self::solde($report, '2027-01'));
        $this->assertSame([-2000, 5000, null, 0], self::solde($report, '2027-02'));
        $this->assertSame([3000, 0, 3000, 0], self::solde($report, '2027-03'));
        $this->assertSame('Déclaration de février 2027 : aucun crédit à reporter déclaré.', TextesDuCreditDeTva::calculDeLaPeriode(CadenceDeLaTva::Mensuelle, '2027-02', self::periode($report, '2027-02')->reelle, StatutDuReport::Declaree));
    }

    /**
     * Au trimestriel, les trois mois d'un trimestre s'additionnent avant le
     * report : un 1er trimestre de −50,00 + 10,00 + 10,00 € laisse un crédit
     * de 30,00 €, que le 2e trimestre, 100,00 €, ramène à 70,00 € à payer ;
     * le résultat se lit sur le dernier mois de chaque trimestre, ou sur le
     * dernier mois compté du trimestre en cours.
     */
    public function test_la_chaine_trimestrielle_additionne_les_trois_mois_avant_le_report(): void
    {
        $report = self::calculer(CadenceDeLaTva::Trimestrielle, '2027-01', null, [
            '2027-01' => self::tva(-5000),
            '2027-02' => self::tva(1000),
            '2027-03' => self::tva(1000),
            '2027-04' => self::tva(4000),
            '2027-05' => self::tva(6000),
        ]);

        $this->assertSame([-3000, 0, 0, 3000], self::solde($report, '2027-02'));
        $this->assertSame([10000, 3000, 7000, 0], self::solde($report, '2027-04'));
        $this->assertNull($report->montrePar('2027-01'));
        $this->assertNull($report->montrePar('2027-02'));
        $this->assertSame('2027-01', $report->montrePar('2027-03')?->periode);
        $this->assertNull($report->montrePar('2027-04'));
        $this->assertSame('2027-04', $report->montrePar('2027-05')?->periode);
        $this->assertSame('TVA du trimestre 100,00 € − crédit reporté 30,00 € = 70,00 € à payer.', TextesDuCreditDeTva::calculDeLaPeriode(CadenceDeLaTva::Trimestrielle, '2027-04', self::periode($report, '2027-04')->reelle, StatutDuReport::SansCreditDeclare));
    }

    /**
     * La réelle (charges validées) et l'estimation (avec les charges à
     * valider) ont chacune leur chaîne : 40,00 € de TVA déductible de
     * charges à valider en janvier font un crédit estimé de 30,00 € quand
     * la réelle paie 10,00 € ; février, 20,00 €, paie 20,00 € réels et
     * 0,00 € estimés, le crédit estimé de 30,00 € en laissant 10,00 € ;
     * mars, 30,00 €, paie 30,00 € réels et 20,00 € estimés ; avril,
     * 10,00 €, paie 10,00 € dans les deux chaînes. L'estimation change le
     * résultat de janvier, par ses charges à valider, puis de février et
     * de mars, par le crédit estimé qu'elle reporte, jamais d'avril.
     */
    public function test_l_estimation_a_sa_propre_chaine(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2027-01', null, ['2027-01' => self::tva(1000, 4000), '2027-02' => self::tva(2000), '2027-03' => self::tva(3000), '2027-04' => self::tva(1000)]);

        $this->assertSame([1000, 0, 1000, 0], self::solde($report, '2027-01'));
        $this->assertSame([-3000, 0, 0, 3000], self::solde($report, '2027-01', estimee: true));
        $this->assertSame([2000, 0, 2000, 0], self::solde($report, '2027-02'));
        $this->assertSame([2000, 3000, 0, 1000], self::solde($report, '2027-02', estimee: true));
        $this->assertSame(-1000, self::periode($report, '2027-02')->estimee->signe());
        $this->assertSame([3000, 1000, 2000, 0], self::solde($report, '2027-03', estimee: true));
        $this->assertSame(self::solde($report, '2027-04'), self::solde($report, '2027-04', estimee: true));
        $this->assertSame(
            ['2027-01' => true, '2027-02' => true, '2027-03' => true, '2027-04' => false],
            array_map(fn (string $mois): bool => self::periode($report, $mois)->estimationDifferente(), ['2027-01' => '2027-01', '2027-02' => '2027-02', '2027-03' => '2027-03', '2027-04' => '2027-04']),
        );
    }

    /**
     * Des charges à valider dans la période déclarée ne changent rien : son
     * crédit est celui de sa déclaration dans les deux chaînes, et la
     * période suivante le reprend dans l'une comme dans l'autre.
     */
    public function test_les_charges_a_valider_de_la_periode_declaree_ne_changent_pas_son_resultat(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2027-01', ['periode' => '2027-01', 'credit' => 2000], ['2027-01' => self::tva(1000, 4000), '2027-02' => self::tva(5000)]);

        $this->assertSame([1000, 0, null, 2000], self::solde($report, '2027-01'));
        $this->assertSame(2000, self::periode($report, '2027-01')->estimee->creditAReporter);
        $this->assertFalse(self::periode($report, '2027-01')->estimationDifferente());
        $this->assertSame([5000, 2000, 3000, 0], self::solde($report, '2027-02'));
        $this->assertSame(self::solde($report, '2027-02'), self::solde($report, '2027-02', estimee: true));
        $this->assertFalse(self::periode($report, '2027-02')->estimationDifferente());
    }

    /**
     * Une déclaration d'avant la première période que PILIQI compte ne sert
     * jamais de point de départ, et les mois d'avant la chaîne n'y entrent
     * pas.
     */
    public function test_une_declaration_d_avant_l_arrivee_ne_sert_pas_de_point_de_depart(): void
    {
        $report = self::calculer(CadenceDeLaTva::Mensuelle, '2027-02', ['periode' => '2027-01', 'credit' => 99900], ['2027-01' => self::tva(-7000), '2027-02' => self::tva(1000)]);

        $this->assertNull($report->deLaPeriodeDu('2027-01'));
        $this->assertSame([1000, 0, 1000, 0], self::solde($report, '2027-02'));
        $this->assertSame(StatutDuReport::SansCreditDeclare, self::periode($report, '2027-02')->statut);
    }

    /**
     * Ce mois de TVA, en centimes : sa TVA à payer réelle (collectée moins
     * déductible des achats), et la TVA déductible des charges à valider
     * que l'estimation retire en plus.
     */
    private static function tva(int $reelle, int $deductibleAValider = 0): MontantsDeTva
    {
        return $reelle >= 0
            ? new MontantsDeTva($reelle, 0, 0, 0, 0, $deductibleAValider, true)
            : new MontantsDeTva(0, 0, -$reelle, 0, 0, $deductibleAValider, true);
    }

    /**
     * @param  array{periode: string, credit: int}|null  $ancre
     * @param  array<string, MontantsDeTva>  $parMois
     */
    private static function calculer(CadenceDeLaTva $cadence, string $premierePeriode, ?array $ancre, array $parMois): TvaReportee
    {
        return ReportDuCreditDeTva::calculer($cadence, $premierePeriode, $ancre, $parMois, true);
    }

    private static function periode(TvaReportee $report, string $mois): ReportDeLaPeriode
    {
        return $report->deLaPeriodeDu($mois) ?? self::fail("La période du mois {$mois} n'est pas dans la chaîne.");
    }

    /**
     * Le résultat de la période de ce mois : la TVA de la période, le
     * crédit reporté, la TVA à payer et le crédit à reporter.
     *
     * @return array{int, int, int|null, int}
     */
    private static function solde(TvaReportee $report, string $mois, bool $estimee = false): array
    {
        $periode = self::periode($report, $mois);
        $solde = $estimee ? $periode->estimee : $periode->reelle;

        return self::enTableau($solde);
    }

    /**
     * @return array{int, int, int|null, int}
     */
    private static function enTableau(SoldeDeLaPeriode $solde): array
    {
        return [$solde->tva, $solde->creditReporte, $solde->aPayer, $solde->creditAReporter];
    }
}
