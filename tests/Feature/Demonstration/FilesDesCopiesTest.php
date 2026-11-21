<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\RattraperLesTachesDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Taches\TachesDesCopies;
use App\Domain\Demonstration\Taches\TravauxDesCopies;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\DemonstrationTestCase;

/**
 * Les files et les tâches des copies (lot DEMO-COPIE, C.13) : un
 * travail envoyé depuis une copie s'exécute dans cette copie, même juste
 * après un travail d'une autre ; celui d'une copie supprimée est abandonné
 * sans rien écrire ; un travail envoyé dans une transaction annulée ne
 * part pas ; les tâches du calendrier passent dans chaque copie visitée,
 * et une copie restée sans passe rattrape ses journées dans l'ordre, un
 * ticket Z par jour, avant de s'ouvrir ; les travaux échoués se purgent à
 * 30 jours. La place d'une copie : voir PlaceDesCopiesTest.
 */
class FilesDesCopiesTest extends DemonstrationTestCase
{
    private const FILE = 'copies_de_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.connections.'.self::FILE => self::connexionsDeLaConfiguration()['demonstration']]);
        NoterLaBaseDuTravail::$bases = [];
    }

    public function test_un_travail_envoye_depuis_une_copie_s_execute_dans_cette_copie_et_celui_d_une_copie_supprimee_est_abandonne(): void
    {
        ['copie' => $premiere] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $seconde] = $this->copieDeTest(TypeDActivite::Services);

        foreach ([$premiere, $seconde, $premiere] as $copie) {
            $this->envoyerDepuis($copie);
        }

        $charges = DB::connection('demo_admin')->table('jobs')->orderBy('id')->pluck('payload')->map(fn (mixed $charge): mixed => json_decode((string) $charge, true)[TravauxDesCopies::CLE] ?? null)->all();
        $this->assertSame([$premiere->reference, $seconde->reference, $premiere->reference], $charges, 'Chaque travail emporte la référence de sa copie.');

        for ($travail = 0; $travail < 3; $travail++) {
            Artisan::call('queue:work', ['connection' => self::FILE, '--once' => true]);
        }

        $this->assertSame([$premiere->nom_de_la_base, $seconde->nom_de_la_base, $premiere->nom_de_la_base], NoterLaBaseDuTravail::$bases);
        $this->assertNull(OuvertureDUneCopie::courante(), 'Après son travail, le travailleur ne lit plus aucune copie.');

        $this->envoyerDepuis($seconde);
        Copie::on('demo_admin')->whereKey($seconde->id)->update(['etat' => EtatDUneCopie::EnMigration->value]);
        Artisan::call('queue:work', ['connection' => self::FILE, '--once' => true]);

        $this->assertCount(3, NoterLaBaseDuTravail::$bases, 'Le travail d\'une copie qui ne se sert pas est abandonné, sans rien exécuter.');
        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->count());
        $this->assertSame(0, DB::connection('demo_admin')->table('failed_jobs')->count(), 'Abandonné sans nouvelle tentative ni échec.');
    }

    public function test_un_travail_envoye_avant_une_refection_n_est_jamais_execute_dans_la_copie_refaite(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $this->envoyerDepuis($copie);
        $this->envoyerDepuis($copie);
        $charge = (string) DB::connection('demo_admin')->table('jobs')->orderBy('id')->value('payload');
        $this->assertSame($copie->nom_de_la_base, json_decode($charge, true)[TravauxDesCopies::CLE_DE_LA_BASE] ?? null, 'Le travail emporte la base de sa copie.');
        DB::connection('demo_admin')->table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => self::FILE, 'queue' => 'default', 'payload' => $charge, 'exception' => 'Échec', 'failed_at' => CarbonImmutable::now()]);

        app(FinDesCopies::class)->refaire($copie->reference, FinDesCopies::MOTIF_REMISE_A_ZERO);

        $refaite = Copie::on('demo_admin')->whereKey($copie->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $refaite->etat);
        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->where('payload', 'like', TravauxDesCopies::motifDesTravaux($copie->reference))->count(), 'Les travaux de l\'ancienne base sont effacés des files à la réfection.');
        $this->assertSame(0, DB::connection('demo_admin')->table('failed_jobs')->where('payload', 'like', TravauxDesCopies::motifDesTravaux($copie->reference))->count());

        // Un travail que son travailleur remettrait dans sa file après la
        // réfection (il tournait pendant le DROP) : jamais exécuté dans la
        // nouvelle base.
        DB::connection('demo_admin')->table('jobs')->insert(['queue' => 'default', 'payload' => $charge, 'attempts' => 0, 'reserved_at' => null, 'available_at' => CarbonImmutable::now()->getTimestamp(), 'created_at' => CarbonImmutable::now()->getTimestamp()]);
        Artisan::call('queue:work', ['connection' => self::FILE, '--once' => true]);

        $this->assertSame([], NoterLaBaseDuTravail::$bases, 'Un identifiant de l\'ancienne base ne désigne jamais une fiche de la nouvelle : le travail est abandonné.');
        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->count());
        $this->assertSame(0, DB::connection('demo_admin')->table('failed_jobs')->count(), 'Abandonné sans échec.');

        $this->envoyerDepuis($refaite);
        Artisan::call('queue:work', ['connection' => self::FILE, '--once' => true]);
        $this->assertSame([$refaite->nom_de_la_base], NoterLaBaseDuTravail::$bases, 'Un travail envoyé depuis la copie refaite s\'y exécute.');
    }

    public function test_un_travail_envoye_dans_une_transaction_annulee_ne_part_pas(): void
    {
        $connexions = self::connexionsDeLaConfiguration();

        foreach (['demonstration' => 'registre', 'demonstration-admin' => 'demo_admin', 'database' => 'registre', 'telechargements' => 'registre'] as $nom => $connexion) {
            $this->assertSame($connexion, $connexions[$nom]['connection'], "La file {$nom} est au registre.");
            $this->assertTrue($connexions[$nom]['after_commit'], "Sur le site de démonstration, un travail de la file {$nom} ne part qu'après la validation de sa transaction : le registre est une autre base que la copie.");
        }

        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        try {
            $this->dansLaCopie($copie, fn () => DB::transaction(function (): void {
                NoterLaBaseDuTravail::dispatch()->onConnection(self::FILE);

                throw new RuntimeException('Geste annulé.');
            }));
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->count());
    }

    public function test_une_copie_restee_sans_passe_rattrape_ses_journees_dans_l_ordre_puis_s_ouvre(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $this->dansLaCopie($copie, function (): void {
            $halles = Magasin::query()->withoutGlobalScopes()->where('nom', 'Les Halles')->sole();
            $vendeuse = User::query()->where('name', 'Inès Garcia')->sole();
            app(TenantContext::class)->set($halles);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            app(EmettreFactureAction::class)->handle($vendeuse, app(CreerFactureAction::class)->handle($vendeuse, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
            app(TenantContext::class)->clear();
        });
        $temoin = $this->entrerParLeLien($secret);
        $this->assertSame([$copie->reference], app(TachesDesCopies::class)->aPasser(false), 'Une copie visitée depuis moins de 48 heures passe chaque heure.');

        $this->travelTo(self::aParis('2026-11-23 15:00:00'));
        $this->assertSame([], app(TachesDesCopies::class)->aPasser(false), 'Une copie visitée il y a plus de 48 heures ne passe que la nuit.');
        $this->assertSame([$copie->reference], app(TachesDesCopies::class)->aPasser(true));

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertOk()->assertSee('Choisissez qui vous êtes');

        $journees = $this->dansLaCopie($copie, fn (): array => ClotureCaisse::query()->withoutGlobalScopes()->orderBy('id')->pluck('date_cloture')->map(fn (mixed $jour): string => substr((string) $jour, 0, 10))->all());
        $this->assertSame(['2026-11-20', '2026-11-21', '2026-11-22'], array_slice($journees, -3), 'À l\'entrée, la copie rattrape ses journées dans l\'ordre, un ticket Z par jour, même à zéro, jusqu\'à la veille, puis s\'ouvre.');
        $this->assertSame(array_values(array_unique($journees)), $journees);

        foreach (array_slice($journees, 1) as $rang => $jour) {
            $this->assertSame(Calendrier::ajouterJours($journees[$rang], 1), $jour, 'Aucune journée sautée.');
        }
        $passe = Copie::on('demo_admin')->whereKey($copie->id)->sole()->derniere_passe_des_taches_le;
        $this->assertNotNull($passe);
        $this->assertTrue($passe->equalTo(CarbonImmutable::now()));
    }

    public function test_les_taches_des_copies_passent_chaque_heure_la_nuit_comprise(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $passes = array_values(array_filter(app(Schedule::class)->events(), static fn (Event $tache): bool => str_contains((string) $tache->command, 'demonstration:taches-des-copies') && ! str_contains((string) $tache->command, '--nuit')));
        $this->assertCount(1, $passes);

        foreach (range(0, 23) as $heure) {
            $this->travelTo(self::aParis(sprintf('2026-11-20 %02d:00:00', $heure)));
            $this->assertTrue($passes[0]->isDue($this->app) && $passes[0]->filtersPass($this->app), "La passe de chaque heure part aussi à {$heure} h : une copie visitée la nuit n'attend jamais un rattrapage.");
        }
    }

    public function test_une_copie_passee_depuis_la_passe_de_l_heure_precedente_reste_a_l_heure_et_livewire_n_attend_jamais(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        Copie::on('demo_admin')->whereKey($copie->id)->update(['derniere_passe_des_taches_le' => self::aParis('2026-11-20 00:00:30')]);
        $copie = Copie::on('demo_admin')->whereKey($copie->id)->sole();

        $this->assertTrue(TachesDesCopies::estALHeure($copie, self::aParis('2026-11-20 01:30:00')), 'Passée à la passe de minuit, la copie est à l\'heure jusqu\'à la passe de 2 h.');
        $this->assertTrue(TachesDesCopies::estALHeure($copie, self::aParis('2026-11-20 01:59:59')));
        $this->assertFalse(TachesDesCopies::estALHeure($copie, self::aParis('2026-11-20 02:00:00')), 'Sans la passe de 1 h, elle ne l\'est plus.');

        $this->travelTo(self::aParis('2026-11-20 09:00:00'));
        Bus::fake([RattraperLesTachesDUneCopie::class]);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertStatus(503)
            ->assertSee('Nous mettons votre démonstration à l\'heure');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->withHeader('X-Livewire', 'true')->get('/choisir')
            ->assertOk();
        Bus::assertDispatchedTimes(RattraperLesTachesDUneCopie::class, 1);
    }

    public function test_les_travaux_echoues_du_registre_se_purgent_a_trente_jours(): void
    {
        $this->travelTo(self::aParis('2026-12-20 10:00:00'));

        foreach (['vieux' => 31, 'recent' => 29] as $nom => $jours) {
            DB::connection('demo_admin')->table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'demonstration', 'queue' => 'default', 'payload' => '{"nom":"'.$nom.'"}', 'exception' => 'Échec', 'failed_at' => Calendrier::deParis()->decaler(CarbonImmutable::now(), CarbonInterval::days(-$jours))]);
        }

        $this->artisan('demonstration:purger-les-travaux-echoues')->assertSuccessful();

        $this->assertSame(['{"nom":"recent"}'], DB::connection('demo_admin')->table('failed_jobs')->pluck('payload')->all());
    }

    public function test_un_travail_d_une_copie_qui_echoue_dans_le_travailleur_est_garde_au_registre_avec_sa_reference(): void
    {
        $this->assertSame('registre', config('queue.failed.database'));
        $this->assertSame('registre', config('queue.batching.database'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $this->dansLaCopie($copie, function (): void {
            EchouerDansLaCopie::dispatch()->onConnection(self::FILE);
        });

        Artisan::call('queue:work', ['connection' => self::FILE, '--once' => true]);

        $echoues = DB::connection('demo_admin')->table('failed_jobs')->get(['payload', 'exception']);
        $this->assertCount(1, $echoues, 'Le travail échoué est gardé au registre, jamais dans la base sentinelle ni dans la copie.');
        $this->assertSame($copie->reference, json_decode((string) $echoues[0]->payload, true)[TravauxDesCopies::CLE] ?? null, 'Avec la référence de sa copie : il s\'efface avec elle.');
        $this->assertStringContainsString('Échec voulu dans la copie.', (string) $echoues[0]->exception);
        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->count());
        $this->assertNull(OuvertureDUneCopie::courante());
    }

    /**
     * Un travail envoyé depuis cette copie, pendant qu'elle est ouverte.
     */
    private function envoyerDepuis(Copie $copie): void
    {
        $this->dansLaCopie($copie, function (): void {
            NoterLaBaseDuTravail::dispatch()->onConnection(self::FILE);
        });
    }

    /**
     * Les connexions des files telles que config/queue.php les définit sur
     * le site de démonstration (les tests rendent « demonstration »
     * synchrone).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function connexionsDeLaConfiguration(): array
    {
        $configuration = require config_path('queue.php');

        return $configuration['connections'];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    private function dansLaCopie(Copie $copie, Closure $geste): mixed
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, $geste);
    }
}

/**
 * Un travail qui note la base où il s'exécute.
 */
final class NoterLaBaseDuTravail implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @var list<string>
     */
    public static array $bases = [];

    public function handle(): void
    {
        self::$bases[] = DB::connection()->getDatabaseName();
    }
}

/**
 * Un travail qui échoue, sans nouvelle tentative.
 */
final class EchouerDansLaCopie implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('Échec voulu dans la copie.');
    }
}
