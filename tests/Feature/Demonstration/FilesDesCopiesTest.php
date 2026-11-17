<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
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
        config(['queue.connections.'.self::FILE => ['driver' => 'database', 'connection' => 'registre', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => true]]);
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

    public function test_un_travail_envoye_dans_une_transaction_annulee_ne_part_pas(): void
    {
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

    public function test_les_travaux_echoues_du_registre_se_purgent_a_trente_jours(): void
    {
        $this->travelTo(self::aParis('2026-12-20 10:00:00'));

        foreach (['vieux' => 31, 'recent' => 29] as $nom => $jours) {
            DB::connection('demo_admin')->table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'demonstration', 'queue' => 'default', 'payload' => '{"nom":"'.$nom.'"}', 'exception' => 'Échec', 'failed_at' => Calendrier::deParis()->decaler(CarbonImmutable::now(), CarbonInterval::days(-$jours))]);
        }

        $this->artisan('demonstration:purger-les-travaux-echoues')->assertSuccessful();

        $this->assertSame(['{"nom":"recent"}'], DB::connection('demo_admin')->table('failed_jobs')->pluck('payload')->all());
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
