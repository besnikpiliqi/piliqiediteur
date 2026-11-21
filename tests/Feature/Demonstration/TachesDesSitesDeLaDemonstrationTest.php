<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Taches\TachesDesSites;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\DemonstrationTestCase;

/**
 * Le planificateur du site de démonstration (lot DEMO-COPIE, C.13) : il ne
 * fait passer que ses propres tâches (TachesDesSites::DU_SITE_DE_DEMONSTRATION),
 * jamais une tâche du site réel ni une tâche d'une copie, qui passent dans
 * chaque copie par demonstration:taches-d-une-copie ; aucune tâche
 * quotidienne entre 2 h et 3 h. Le site réel : voir TachesDesSitesTest.
 */
class TachesDesSitesDeLaDemonstrationTest extends DemonstrationTestCase
{
    public function test_le_site_de_demonstration_ne_planifie_que_ses_propres_taches(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $planifiees = TachesDesSitesTest::commandesPlanifiees(app(Schedule::class));
        $attendues = TachesDesSitesTest::nomsDes(TachesDesSites::DU_SITE_DE_DEMONSTRATION);
        $uniques = array_values(array_unique($planifiees));
        sort($attendues);
        sort($uniques);

        $this->assertSame($attendues, $uniques);

        foreach (TachesDesSitesTest::nomsDes(TachesDesSites::DE_CHAQUE_COPIE) as $tache) {
            $this->assertNotContains($tache, $planifiees, "{$tache} passe dans chaque copie, jamais par le planificateur du site de démonstration.");
        }

        foreach (app(Schedule::class)->events() as $tache) {
            $this->assertInstanceOf(Event::class, $tache);
            $this->assertFalse(str_starts_with($tache->expression, '0 2 ') || preg_match('/^\d+ 2 \* \* \*$/', $tache->expression) === 1, "Aucune tâche quotidienne entre 2 h et 3 h : {$tache->command}.");
        }
    }
}
