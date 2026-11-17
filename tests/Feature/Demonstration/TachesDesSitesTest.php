<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Taches\TachesDesSites;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Chaque tâche de bootstrap/app.php est rangée (lot DEMO-COPIE, C.13) : le
 * site réel planifie exactement les tâches de chaque copie et celles du
 * seul site réel ; une tâche planifiée que TachesDesSites ne range pas
 * fait échouer ce test. Les tâches de chaque copie sont du site réel, et
 * les tâches du site de démonstration n'y sont jamais (voir
 * TachesDesSitesDeLaDemonstrationTest).
 */
class TachesDesSitesTest extends TestCase
{
    public function test_le_site_reel_planifie_exactement_ses_taches_rangees(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $planifiees = array_values(array_unique(self::commandesPlanifiees(app(Schedule::class))));
        $rangees = self::nomsDes(TachesDesSites::duSiteReel());
        sort($planifiees);
        sort($rangees);

        $this->assertSame($rangees, $planifiees, 'Chaque tâche planifiée du site réel est rangée dans TachesDesSites, et chaque tâche rangée y est planifiée.');

        foreach (self::nomsDes(TachesDesSites::DU_SITE_DE_DEMONSTRATION) as $tache) {
            if (! in_array($tache, self::nomsDes(TachesDesSites::DU_SITE_REEL_SEUL), true)) {
                $this->assertNotContains($tache, $planifiees, "{$tache} ne passe que sur le site de démonstration.");
            }
        }

        $this->assertSame([], array_diff(self::nomsDes(TachesDesSites::DE_LA_NUIT_DES_COPIES), $rangees));
        $this->assertSame([], array_diff(self::nomsDes(TachesDesSites::DE_LA_CONSTRUCTION_D_UN_MODELE), $rangees));
    }

    /**
     * Le nom de chaque commande planifiée, sans ses options.
     *
     * @return list<string>
     */
    public static function commandesPlanifiees(Schedule $planificateur): array
    {
        $noms = [];

        foreach ($planificateur->events() as $tache) {
            if (preg_match("/artisan'? '?([a-z0-9:-]+)/", (string) $tache->command, $nom) === 1) {
                $noms[] = $nom[1];
            }
        }

        return $noms;
    }

    /**
     * Le nom de commande de chaque classe.
     *
     * @param  list<class-string>  $classes
     * @return list<string>
     */
    public static function nomsDes(array $classes): array
    {
        return array_values(array_unique(array_map(static function (string $classe): string {
            $commande = app($classe);
            self::assertInstanceOf(Command::class, $commande);

            return (string) $commande->getName();
        }, $classes)));
    }
}
