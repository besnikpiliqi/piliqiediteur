<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Semis\HorlogeDuSemis;
use Database\Seeders\Semis\SemisComplet;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * La durée de l'histoire que sème le semis (lot GRAPH-AN, demande de
 * l'éditeur du 16/11/2026 : trois années de ventes au moins), sans base de
 * données : deux ans d'histoire ancienne avant les 400 jours détaillés, dont
 * le rythme (réassorts tous les quatorze jours, un vendeur sur trois qui se
 * repose…) se compte depuis le premier jour de l'histoire détaillée, pour
 * que les 400 derniers jours restent ceux d'avant les deux années de plus.
 */
class HistoireDuSemisTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_l_histoire_semee_compte_deux_ans_d_histoire_ancienne_puis_les_400_jours_detailles(): void
    {
        $this->assertSame(400, SemisComplet::JOURS_RECENTS);
        $this->assertSame(730, SemisComplet::JOURS_ANCIENS);
        $this->assertSame(SemisComplet::JOURS_ANCIENS + SemisComplet::JOURS_RECENTS, SemisComplet::JOURS);
        $this->assertGreaterThan(3 * 365, SemisComplet::JOURS, 'Plus de trois ans de ventes.');
    }

    public function test_le_rythme_se_compte_depuis_le_premier_jour_de_l_histoire_detaillee(): void
    {
        $this->assertSame(0, SemisComplet::rangRecent(SemisComplet::JOURS_ANCIENS));
        $this->assertSame(17, SemisComplet::rangRecent(SemisComplet::JOURS_ANCIENS + 17), 'Le dix-septième jour de l\'histoire détaillée est celui où les ventes régulières commençaient.');
        $this->assertSame(SemisComplet::JOURS_RECENTS - 1, SemisComplet::rangRecent(SemisComplet::JOURS - 1));
        $this->assertSame(-1, SemisComplet::rangRecent(SemisComplet::JOURS_ANCIENS - 1), 'Avant elle, le rang est négatif : jamais un jour de l\'histoire détaillée.');
    }

    public function test_un_jour_se_retrouve_par_son_rang_dans_l_histoire_et_pas_hors_d_elle(): void
    {
        Carbon::setTestNow(new CarbonImmutable('2026-11-13T09:00:00Z'));
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-13T09:00:00Z'));
        $horloge = new HorlogeDuSemis(SemisComplet::JOURS);

        $this->assertSame('2023-10-10', $horloge->jour(0));
        $this->assertSame('2026-11-12', $horloge->jour(SemisComplet::JOURS - 1), 'L\'histoire finit la veille du semis.');
        $this->assertSame(0, $horloge->rangDuJour('2023-10-10'));
        $this->assertSame(437, $horloge->rangDuJour('2024-12-20'));
        $this->assertSame(SemisComplet::JOURS - 1, $horloge->rangDuJour('2026-11-12'));

        foreach (['2023-10-09', '2026-11-13'] as $horsDeLHistoire) {
            try {
                $horloge->rangDuJour($horsDeLHistoire);
                $this->fail("Le jour {$horsDeLHistoire} n'est pas dans l'histoire semée.");
            } catch (LogicException $refus) {
                $this->assertStringContainsString("Le jour {$horsDeLHistoire} n'est pas dans l'histoire semée", $refus->getMessage());
            }
        }
    }
}
