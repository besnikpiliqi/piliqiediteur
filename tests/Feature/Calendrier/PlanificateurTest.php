<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\Territoire;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les tâches planifiées (bootstrap/app.php) partent à l'heure de Paris,
 * été comme hiver, une seule fois les jours de changement d'heure ;
 * aucune tâche quotidienne n'est planifiée entre 2 h et 3 h, heure qui se
 * répète en octobre et n'existe pas en mars. Les tâches du calendrier des
 * magasins passent toutes les heures, et le minuit de chaque territoire
 * tombe sur l'un de leurs passages.
 */
class PlanificateurTest extends TestCase
{
    /**
     * Chaque tâche planifiée : sa commande, son expression et son fuseau.
     *
     * @var list<array{string, string}>
     */
    private const TACHES = [
        ['app:cloturer-caisses-automatiquement', '0 * * * *'],
        ['app:marquer-factures-en-retard', '15 * * * *'],
        ['app:expirer-devis', '30 * * * *'],
        ['caisse:verifier', '0 3 * * *'],
        ['app:verifier-journal-audit', '30 3 * * *'],
        ['app:purger-traces', '0 4 * * *'],
        ['app:purger-journal-audit', '15 4 * * *'],
        ['app:purger-actions-traitees', '30 4 * * *'],
        ['app:fermer-demonstrations-inactives', '45 4 * * *'],
        ['app:effacer-pieces-verification', '0 * * * *'],
        ['app:purger-demandes-inscription', '0 * * * *'],
        ['app:clore-inscriptions-sans-code', '0 * * * *'],
        ['app:reverifier-sirene', '0 * * * *'],
        ['app:suivre-envois-recommandes', '0 * * * *'],
        ['app:reverifier-sirene --toutes', '0 5 1 * *'],
        ['app:relancer-factures-impayees', '0 * * * *'],
    ];

    /**
     * Le planning entier, ni plus ni moins : une tâche ajoutée sans son
     * horaire attendu fait échouer le test.
     */
    public function test_chaque_tache_planifiee_a_son_horaire_attendu(): void
    {
        $taches = array_map(static fn (Event $evenement): array => [self::commande($evenement), $evenement->expression, $evenement->timezone], $this->evenements());

        $this->assertSame(array_map(static fn (array $tache): array => [...$tache, Calendrier::deParis()->fuseau()], self::TACHES), $taches);
    }

    /**
     * Chaque tâche quotidienne part à son heure de Paris, une seule fois
     * dans la journée, en été, en hiver et les jours de changement d'heure
     * (25 heures le 25 octobre 2026, 23 heures le 28 mars 2027) ; la tâche
     * mensuelle part le 1er à 5 h à Paris, en hiver comme en été ; les
     * tâches horaires partent chaque heure à leur minute, aux deux passages
     * de l'heure répétée et après l'heure sautée compris.
     */
    public function test_les_taches_quotidiennes_et_mensuelles_partent_a_l_heure_de_paris_en_ete_en_hiver_et_les_jours_de_changement_d_heure(): void
    {
        $journees = [
            'été' => [self::enUtc('2026-07-13 22:00:00'), 24, self::enUtc('2026-07-14 01:00:00')],
            'hiver' => [self::enUtc('2026-12-14 23:00:00'), 24, self::enUtc('2026-12-15 02:00:00')],
            'passage à l\'heure d\'hiver' => [self::enUtc('2026-10-24 22:00:00'), 25, self::enUtc('2026-10-25 02:00:00')],
            'passage à l\'heure d\'été' => [self::enUtc('2027-03-27 23:00:00'), 23, self::enUtc('2027-03-28 01:00:00')],
        ];

        foreach ($journees as $journee => [$minuit, $heures, $verificationDeLaCaisse]) {
            $this->assertDepartsDesTachesQuotidiennes($journee, $minuit, $heures, $verificationDeLaCaisse);
        }

        $mensuelle = collect($this->evenements())->sole(static fn (Event $evenement): bool => self::commande($evenement) === 'app:reverifier-sirene --toutes');
        foreach ([[self::enUtc('2027-01-01 04:00:00'), true], [self::enUtc('2027-01-01 05:00:00'), false], [self::enUtc('2026-07-01 03:00:00'), true], [self::enUtc('2026-07-01 05:00:00'), false]] as [$instant, $due]) {
            $this->travelTo($instant);
            $this->assertSame($due, $mensuelle->isDue($this->app), $instant->toIso8601String());
        }

        $horaires = array_filter($this->evenements(), static fn (Event $evenement): bool => preg_match('/^\d+ \* \* \* \*$/', $evenement->expression) === 1);
        foreach ([self::enUtc('2026-10-25 00:00:00'), self::enUtc('2026-10-25 01:00:00'), self::enUtc('2027-03-28 01:00:00'), self::enUtc('2026-07-14 13:00:00')] as $heureRonde) {
            foreach ($horaires as $evenement) {
                $minute = (int) explode(' ', $evenement->expression)[0];

                foreach ([[$minute, true], [$minute + 5, false]] as [$minutes, $due]) {
                    $instant = $heureRonde->addMinutes($minutes);
                    $this->travelTo($instant);
                    $this->assertSame($due, $evenement->isDue($this->app), $instant->toIso8601String().' : '.self::commande($evenement));
                }
            }
        }
    }

    /**
     * Le minuit de chaque territoire, en été, en hiver, le 31 décembre et
     * les jours où Paris change d'heure, tombe à une heure ronde en UTC,
     * minute 0 et seconde 0 : la clôture automatique, qui passe chaque
     * heure à la minute 0, est due à cet instant même. Un territoire futur
     * dont le décalage ne serait pas d'heures entières ferait échouer ce
     * test.
     */
    public function test_chaque_minuit_local_tombe_sur_un_passage_horaire(): void
    {
        $cloture = collect($this->evenements())->sole(static fn (Event $evenement): bool => self::commande($evenement) === 'app:cloturer-caisses-automatiquement');

        foreach (Territoire::cases() as $territoire) {
            foreach (['2026-07-14', '2026-10-25', '2026-12-31', '2027-03-28'] as $jour) {
                $minuit = Calendrier::duTerritoire($territoire)->debutDuJour($jour);
                $this->travelTo($minuit);

                $this->assertSame(0, $minuit->getTimestamp() % 3600, "{$territoire->value} : {$jour}");
                $this->assertTrue($cloture->isDue($this->app), "{$territoire->value} : {$jour}");
            }
        }
    }

    /**
     * Aucune tâche quotidienne ou mensuelle à une heure fixe entre 2 h et
     * 2 h 59 : elle partirait deux fois le dernier dimanche d'octobre et
     * jamais le dernier dimanche de mars.
     */
    public function test_aucune_tache_quotidienne_n_est_planifiee_entre_2_et_3_heures(): void
    {
        $heures = array_map(static fn (Event $evenement): string => explode(' ', $evenement->expression)[1], $this->evenements());

        $this->assertNotContains('2', $heures);
        $this->assertSame([], array_filter($heures, static fn (string $heure): bool => $heure !== '*' && preg_match('/^\d+$/', $heure) !== 1));
    }

    /**
     * Les tâches de bootstrap/app.php, que le démarrage d'Artisan
     * enregistre, comme sur le serveur avant schedule:run.
     *
     * @return list<Event>
     */
    private function evenements(): array
    {
        $this->artisan('schedule:list')->assertSuccessful();

        return array_values(app(Schedule::class)->events());
    }

    /**
     * Chaque tâche quotidienne part une seule fois dans la journée de Paris
     * qui commence à $minuit et dure $heures heures ; la vérification de la
     * caisse sécurisée part à cet instant (3 h à Paris).
     */
    private function assertDepartsDesTachesQuotidiennes(string $journee, CarbonImmutable $minuit, int $heures, CarbonImmutable $verificationDeLaCaisse): void
    {
        $quotidiennes = array_filter($this->evenements(), static fn (Event $evenement): bool => preg_match('/^\d+ \d+ \* \* \*$/', $evenement->expression) === 1);
        $departs = [];

        for ($minute = 0; $minute < $heures * 60; $minute++) {
            $this->travelTo($minuit->addMinutes($minute));

            foreach ($quotidiennes as $evenement) {
                if ($evenement->isDue($this->app)) {
                    $departs[self::commande($evenement)][] = now()->getTimestamp();
                }
            }
        }

        foreach ($quotidiennes as $evenement) {
            $this->assertCount(1, $departs[self::commande($evenement)] ?? [], "{$journee} : ".self::commande($evenement));
        }
        $this->assertSame([$verificationDeLaCaisse->getTimestamp()], $departs['caisse:verifier'], "{$journee} : caisse:verifier");
    }

    private static function commande(Event $evenement): string
    {
        return Str::after((string) $evenement->command, "'artisan' ");
    }
}
