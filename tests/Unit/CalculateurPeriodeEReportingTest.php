<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Integrations\Pdp\Services\CalculateurPeriodeEReporting;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CalculateurPeriodeEReportingTest extends TestCase
{
    private function calculateur(): CalculateurPeriodeEReporting
    {
        return new CalculateurPeriodeEReporting;
    }

    public function test_decade_precedente_au_milieu_du_mois(): void
    {
        // Le 15 octobre : la décade 1-10 est la dernière terminée.
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelNormalMensuel, CarbonImmutable::parse('2026-10-15'));

        $this->assertSame('2026-10-01', $periode->debut->toDateString());
        $this->assertSame('2026-10-10', $periode->fin->toDateString());
        $this->assertSame('2026-10-20', $periode->dateLimiteTransmission->toDateString());
    }

    public function test_decade_precedente_en_debut_de_mois_retombe_sur_le_mois_precedent(): void
    {
        // Le 2 octobre : la décade en cours (1-10 d'octobre) n'est pas
        // terminée, la dernière terminée est la décade 21-31 de septembre.
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelNormalMensuel, CarbonImmutable::parse('2026-10-02'));

        $this->assertSame('2026-09-21', $periode->debut->toDateString());
        $this->assertSame('2026-09-30', $periode->fin->toDateString());
        $this->assertSame('2026-10-10', $periode->dateLimiteTransmission->toDateString());
    }

    public function test_decade_precedente_juste_apres_la_decade_du_milieu(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelNormalMensuel, CarbonImmutable::parse('2026-10-21'));

        $this->assertSame('2026-10-11', $periode->debut->toDateString());
        $this->assertSame('2026-10-20', $periode->fin->toDateString());
        $this->assertSame('2026-10-31', $periode->dateLimiteTransmission->toDateString());
    }

    public function test_mois_precedent_pour_le_reel_normal_trimestriel(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelNormalTrimestriel, CarbonImmutable::parse('2026-11-05'));

        $this->assertSame('2026-10-01', $periode->debut->toDateString());
        $this->assertSame('2026-10-31', $periode->fin->toDateString());
        $this->assertSame('2026-11-10', $periode->dateLimiteTransmission->toDateString());
    }

    public function test_mois_precedent_pour_le_reel_simplifie(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelSimplifie, CarbonImmutable::parse('2026-11-05'));

        $this->assertSame('2026-10-01', $periode->debut->toDateString());
        $this->assertSame('2026-10-31', $periode->fin->toDateString());
        $this->assertSame('2026-11-30', $periode->dateLimiteTransmission->toDateString());
    }

    /**
     * La période rapportée (janvier) a 31 jours, mais le mois de la date
     * limite (février, qui n'a pas de 30e jour en 2027, année non
     * bissextile) doit plafonner au dernier jour réel du mois plutôt que
     * de déborder sur mars.
     */
    public function test_mois_precedent_plafonne_le_30_quand_le_mois_de_la_limite_est_plus_court(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::ReelSimplifie, CarbonImmutable::parse('2027-02-05'));

        $this->assertSame('2027-01-01', $periode->debut->toDateString());
        $this->assertSame('2027-01-31', $periode->fin->toDateString());
        $this->assertSame('2027-02-28', $periode->dateLimiteTransmission->toDateString());
    }

    public function test_bimestre_precedent_pour_la_franchise_en_base(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::FranchiseEnBase, CarbonImmutable::parse('2026-07-15'));

        $this->assertSame('2026-05-01', $periode->debut->toDateString());
        $this->assertSame('2026-06-30', $periode->fin->toDateString());
        $this->assertSame('2026-07-30', $periode->dateLimiteTransmission->toDateString());
    }

    /**
     * Le premier bimestre de l'année doit retomber sur le dernier
     * bimestre de l'année PRÉCÉDENTE, pas planter sur un index négatif.
     */
    public function test_bimestre_precedent_a_cheval_sur_le_changement_dannee(): void
    {
        $periode = $this->calculateur()->periodePrecedente(RegimeTva::FranchiseEnBase, CarbonImmutable::parse('2027-01-15'));

        $this->assertSame('2026-11-01', $periode->debut->toDateString());
        $this->assertSame('2026-12-31', $periode->fin->toDateString());
        $this->assertSame('2027-01-30', $periode->dateLimiteTransmission->toDateString());
    }
}
