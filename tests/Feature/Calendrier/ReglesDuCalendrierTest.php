<?php

namespace Tests\Feature\Calendrier;

use Closure;
use Illuminate\Support\Facades\File;
use LogicException;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Les règles du calendrier, vérifiées sur le code des tests : toute heure
 * écrite dans un test dit de quel fuseau elle est, aucun test ne voyage en
 * jours ni ne part de l'heure réelle, et aucun voyage dans le temps ne
 * part d'une horloge libre. Une exception n'est permise que listée ici,
 * avec sa raison.
 */
class ReglesDuCalendrierTest extends TestCase
{
    /**
     * Une heure écrite en clair (« AAAA-MM-JJ HH:MM », avec ou sans
     * secondes, sans décalage), entre apostrophes ou guillemets.
     */
    private const HEURE_ECRITE = '/([\'"])\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?\1/';

    /**
     * Les heures écrites permises hors de aParis() et de enUtc(), par
     * fichier et par méthode, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const HEURES_ECRITES_PERMISES = [
        'tests/Feature/Facturation/NatureOperationTest.php' => [
            'livraisonsIntracommunautairesRefusees' => 'jeu de données : la méthode de test passe chaque heure à aParis()',
        ],
        'tests/Feature/Providers/AppServiceProviderTest.php' => [
            'reglesDeDate' => 'texte saisi dans un formulaire, que lit la règle de validation before:',
        ],
    ];

    /**
     * Les voyages permis depuis une horloge qui n'est pas figée par le
     * test, par fichier et par méthode, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const VOYAGES_PERMIS = [
        'tests/Feature/CaisseSecurisee/PurgerTracesTest.php' => [
            'test_une_trace_qui_n_a_expire_qu_a_l_heure_de_l_application_attend_sans_faire_echouer_la_purge' => 'le déclencheur de purge compare à l\'horloge du serveur de base, que faketime ne décale pas sous PostgreSQL : le test la suit (maintenantSelonLaBase())',
        ],
    ];

    /**
     * Une heure écrite n'apparaît qu'en argument de aParis() ou de
     * enUtc(), quel que soit son usage (voyage, aide, attribut, attente) :
     * sans fuseau, elle se lirait dans le fuseau par défaut de
     * l'application, et un changement de ce fuseau la décalerait en
     * silence. Un voyage ne reçoit jamais une chaîne.
     */
    public function test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite(): void
    {
        $violations = $this->violationsDansLesTests(function (string $ligne): array {
            $sansAides = preg_replace('/\b(?:aParis|enUtc)\(\s*([\'"])[^\'"]*\1/', '', $ligne);

            return array_values(array_filter([
                preg_match(self::HEURE_ECRITE, (string) $sansAides) === 1 ? 'heure écrite sans fuseau' : null,
                preg_match('/travelTo\(\s*[\'"]/', $ligne) === 1 ? 'voyage vers une chaîne' : null,
            ]));
        }, self::HEURES_ECRITES_PERMISES);

        $this->assertSame([], $violations);
    }

    /**
     * Aucun test ne voyage en jours, semaines, mois ou années, ni ne
     * calcule ainsi sur un instant : ce calcul dépendrait du fuseau de
     * l'application. Aucun voyage ne part de l'heure réelle ni de celle du
     * serveur de base, sauf l'exception listée.
     */
    public function test_aucun_test_ne_voyage_en_jours_ni_ne_part_de_l_heure_reelle(): void
    {
        $violations = $this->violationsDansLesTests(static fn (string $ligne): array => array_values(array_filter([
            preg_match('/travel\([^;]*\)->(?:day|days|week|weeks|month|months|year|years)\(/', $ligne) === 1 ? 'voyage en jours' : null,
            preg_match('/->(?:add|sub)(?:Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)\(/', $ligne) === 1 ? 'calcul en jours sur un instant' : null,
            preg_match('/travelTo\(\s*(?:now\(|[\\\\\w]*::now\(|\$this->maintenantSelonLaBase\()/', $ligne) === 1 ? 'voyage parti de l\'heure réelle' : null,
        ])), self::VOYAGES_PERMIS);

        $this->assertSame([], $violations);
    }

    /**
     * travel(), freezeTime() et freezeSecond() refusent de partir d'une
     * horloge libre, qui serait l'heure réelle ; une fois l'horloge figée,
     * ils partent d'elle.
     */
    public function test_un_voyage_dans_le_temps_exige_une_horloge_figee(): void
    {
        foreach (['travel()' => fn () => $this->travel(1)->hours(), 'freezeTime()' => fn () => $this->freezeTime(), 'freezeSecond()' => fn () => $this->freezeSecond()] as $voyage => $partir) {
            $this->assertThrows($partir, LogicException::class, "{$voyage} partirait de l'heure réelle : figez d'abord l'horloge à un instant écrit");
        }

        $this->travelTo(self::enUtc('2026-10-09 08:00:00'));
        $this->travel(1)->hours();
        $this->freezeSecond();
        $this->freezeTime();

        $this->assertMemeInstant(self::enUtc('2026-10-09 09:00:00'), now());
    }

    /**
     * Les lignes des fichiers de tests où la règle trouve un motif
     * interdit, hors des méthodes permises.
     *
     * @param  Closure(string): list<string>  $motifs  les motifs interdits de la ligne
     * @param  array<string, array<string, string>>  $permises
     * @return list<string>
     */
    private function violationsDansLesTests(Closure $motifs, array $permises): array
    {
        $violations = [];

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            $chemin = $this->cheminDansLeDepot($fichier);

            if ($fichier->getExtension() !== 'php' || $chemin === 'tests/Feature/Calendrier/ReglesDuCalendrierTest.php') {
                continue;
            }

            $methode = null;

            foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                if (preg_match('/\bfunction\s+(\w+)\s*\(/', $ligne, $declaration) === 1) {
                    $methode = $declaration[1];
                }

                if (isset($permises[$chemin][$methode])) {
                    continue;
                }

                foreach ($motifs($ligne) as $motif) {
                    $violations[] = sprintf('%s:%d  %s  %s', $chemin, $numero + 1, $motif, trim($ligne));
                }
            }
        }

        return $violations;
    }

    private function cheminDansLeDepot(SplFileInfo $fichier): string
    {
        return str_replace(base_path().'/', '', $fichier->getPathname());
    }
}
