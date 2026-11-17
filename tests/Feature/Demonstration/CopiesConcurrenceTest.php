<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\MigrationDesCopies;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\DemonstrationTestCase;

/**
 * Le groupe concurrence du site de démonstration (lot DEMO-COPIE, G.1),
 * sur PostgreSQL, chaque geste concurrent dans son propre processus : une
 * remise à zéro, une suppression à 30 jours et une migration pendant une
 * visite (la visite voit sa page d'attente, jamais une erreur, puis la
 * copie dans son nouvel état) ; deux visites de deux copies en parallèle
 * (aucune ne voit les lignes de l'autre) ; le même travail unique dans
 * deux copies en même temps (les deux passent) ; la mesure de la place
 * pendant qu'une réfection change l'état de la copie (la mesure ne la
 * remet jamais « prête »).
 */
#[Group('concurrence')]
class CopiesConcurrenceTest extends DemonstrationTestCase
{
    public function test_une_remise_a_zero_pendant_une_visite_montre_se_prepare_puis_la_copie_refaite(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        $reference = $copie->reference;
        DB::connection('demo_admin')->select('select pg_advisory_lock(hashtext(?))', ['demonstration-modele-'.TypeDActivite::Services->value]);

        try {
            $concurrente = $this->demarrerDansLaDemonstration(static function () use ($reference): void {
                app(FinDesCopies::class)->refaire($reference, FinDesCopies::MOTIF_REMISE_A_ZERO);
            });
            $this->attendreUnVerrou($concurrente, self::nomDuRegistreDeTest());

            $this->assertSame(EtatDUneCopie::ARefaire, Copie::on('demo_admin')->whereKey($copie->id)->sole()->etat, 'L\'archive faite et l\'ancienne base supprimée, la nouvelle copie attend le modèle.');
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
                ->assertStatus(503)
                ->assertSee('Votre démonstration se prépare');
        } finally {
            DB::connection('demo_admin')->select('select pg_advisory_unlock_all()');
        }

        $resultat = $this->attendreLeProcessusConcurrent($concurrente, 120);
        $this->assertTrue($resultat->successful(), 'La remise à zéro finit : '.self::erreurDuProcessus($resultat));
        $refaite = Copie::on('demo_admin')->whereKey($copie->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $refaite->etat);
        $this->assertNotSame($copie->nom_de_la_base, $refaite->nom_de_la_base);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertOk()
            ->assertSee('Votre démonstration a été remise à zéro le', false);
    }

    public function test_la_suppression_a_trente_jours_pendant_une_requete_ne_montre_jamais_une_erreur(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        $reference = $copie->reference;
        $this->travelTo($copie->fin->addMinute());
        $verrou = DB::connection('demo_admin');
        $verrou->beginTransaction();

        try {
            $verrou->table('liens')->where('copie_id', $copie->id)->lockForUpdate()->get();
            $concurrente = $this->demarrerDansLaDemonstration(static function () use ($reference): void {
                app(FinDesCopies::class)->supprimer($reference, 'trente_jours');
            });
            $this->attendreUnVerrou($concurrente, self::nomDuRegistreDeTest(), 60);

            $this->assertFalse(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base), 'L\'archive faite, la base est supprimée.');
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
                ->assertStatus(410)
                ->assertSee('Cette démonstration est terminée');
        } finally {
            $verrou->rollBack();
        }

        $resultat = $this->attendreLeProcessusConcurrent($concurrente, 120);
        $this->assertTrue($resultat->successful(), 'La suppression finit : '.self::erreurDuProcessus($resultat));
        $this->assertSame(EtatDUneCopie::Supprimee, Copie::on('demo_admin')->whereKey($copie->id)->sole()->etat);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertStatus(410)
            ->assertSee('Cette démonstration est terminée');
    }

    public function test_la_migration_d_une_copie_pendant_une_visite_montre_la_mise_a_jour_puis_la_copie_migree(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        $dossier = sys_get_temp_dir().'/migrations-concurrentes-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dossier);
        File::put($dossier.'/2099_01_01_000001_create_essai_concurrent_table.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('essai_concurrent', function (Blueprint $table) {
                        $table->id();
                    });
                }
            };
            PHP);
        $base = app(BasesDeLaDemonstration::class)->connexionDAdministration((string) $copie->nom_de_la_base);
        $base->beginTransaction();

        try {
            $base->unprepared('lock table migrations in access exclusive mode');
            $concurrente = $this->demarrerDansLaDemonstration(static function () use ($dossier): void {
                app(MigrationDesCopies::class)->migrer([$dossier]);
            });
            $this->attendreUnVerrou($concurrente, (string) $copie->nom_de_la_base, 60);

            $this->assertSame(EtatDUneCopie::EnMigration, Copie::on('demo_admin')->whereKey($copie->id)->sole()->etat);
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
                ->assertStatus(503)
                ->assertSee('Mise à jour en cours, une minute');
        } finally {
            $base->rollBack();
            app(BasesDeLaDemonstration::class)->fermerLaConnexion();
        }

        try {
            $resultat = $this->attendreLeProcessusConcurrent($concurrente, 120);
        } finally {
            File::deleteDirectory($dossier);
            Cache::store('registre')->forget(MigrationDesCopies::CLE_DU_TABLEAU);
        }

        $this->assertTrue($resultat->successful(), 'La migration finit : '.self::erreurDuProcessus($resultat));
        $migree = Copie::on('demo_admin')->whereKey($copie->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $migree->etat);
        $this->assertSame($copie->nom_de_la_base, $migree->nom_de_la_base, 'La copie garde sa base et ses données.');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertOk()
            ->assertSee('vos essais sont gardés', false);
    }

    public function test_deux_visites_de_deux_copies_en_parallele_ne_voient_jamais_les_lignes_l_une_de_l_autre(): void
    {
        ['copie' => $a] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $b] = $this->copieDeTest(TypeDActivite::Services);
        $referenceB = $b->reference;

        app(OuvertureDUneCopie::class)->pendant($a, function () use ($referenceB): void {
            DB::transaction(function () use ($referenceB): void {
                User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Seulement dans la copie A'])->save();

                $concurrente = $this->demarrerDansLaDemonstration(static function () use ($referenceB): void {
                    $copie = Copie::on('demo_admin')->where('reference', $referenceB)->sole();
                    app(OuvertureDUneCopie::class)->pendant($copie, static function (): void {
                        if (User::query()->where('name', 'Seulement dans la copie A')->exists()) {
                            throw new RuntimeException('La visite de la copie B voit une ligne de la copie A.');
                        }

                        User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Seulement dans la copie B'])->save();
                    });
                });
                $resultat = $this->attendreLeProcessusConcurrent($concurrente);

                $this->assertTrue($resultat->successful(), 'La visite de la copie B, en même temps : '.self::erreurDuProcessus($resultat));
                $this->assertFalse(User::query()->where('name', 'Seulement dans la copie B')->exists(), 'La visite de la copie A ne voit jamais une ligne de la copie B.');
            });
        });

        $this->assertTrue(app(OuvertureDUneCopie::class)->pendant($b, fn (): bool => User::query()->where('name', 'Seulement dans la copie B')->exists()));
        $this->assertFalse(app(OuvertureDUneCopie::class)->pendant($b, fn (): bool => User::query()->where('name', 'Seulement dans la copie A')->exists()));
    }

    public function test_le_meme_travail_unique_dans_deux_copies_en_meme_temps_passe_dans_chacune(): void
    {
        ['copie' => $a] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $b] = $this->copieDeTest(TypeDActivite::Services);
        $references = [$a->reference, $b->reference];
        $cle = 'laravel_unique_job:App\\Domain\\Facturation\\Jobs\\EnvoyerDocumentJob:1';

        app(OuvertureDUneCopie::class)->pendant($a, function () use ($references, $cle): void {
            $this->assertTrue(Cache::lock($cle, 600)->get(), 'Le travail unique de la copie A tient son verrou.');

            $concurrente = $this->demarrerDansLaDemonstration(static function () use ($references, $cle): void {
                [$referenceA, $referenceB] = $references;
                $ouvrir = static fn (string $reference): Copie => Copie::on('demo_admin')->where('reference', $reference)->sole();

                if (! app(OuvertureDUneCopie::class)->pendant($ouvrir($referenceB), static fn (): bool => Cache::lock($cle, 600)->get())) {
                    throw new RuntimeException('Le même travail unique de la copie B attend celui de la copie A.');
                }

                if (app(OuvertureDUneCopie::class)->pendant($ouvrir($referenceA), static fn (): bool => Cache::lock($cle, 600)->get())) {
                    throw new RuntimeException('Le verrou du travail de la copie A n\'est pas tenu.');
                }
            });
            $resultat = $this->attendreLeProcessusConcurrent($concurrente);

            $this->assertTrue($resultat->successful(), 'Dans la copie B, le même travail passe en même temps : '.self::erreurDuProcessus($resultat));
        });
    }

    public function test_la_mesure_de_la_place_ne_remet_jamais_prete_une_copie_qu_une_refection_change_en_meme_temps(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $reference = $copie->reference;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use ($reference, &$concurrente): void {
            if ($concurrente !== null || $requete->connectionName !== 'demo_admin' || ! str_contains($requete->sql, 'from "copies"') || ! str_ends_with($requete->sql, 'for update')) {
                return;
            }

            $concurrente = $this->demarrerDansLaDemonstration(static function () use ($reference): void {
                DB::connection('demo_admin')->transaction(static function () use ($reference): void {
                    Copie::on('demo_admin')->where('reference', $reference)->lockForUpdate()->firstOrFail()->forceFill(['etat' => EtatDUneCopie::ARefaire])->save();
                });
            });
            $this->attendreUnVerrou($concurrente, self::nomDuRegistreDeTest());
        });

        app(PlaceDesCopies::class)->mesurer();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente, 'La réfection est partie pendant la mesure, qui tenait la ligne de la copie.');
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), self::erreurDuProcessus($resultat));
        $this->assertSame(EtatDUneCopie::ARefaire, Copie::on('demo_admin')->whereKey($copie->id)->sole()->etat, 'La réfection passe après la mesure, qui ne la remet jamais « prête ».');
    }

    /**
     * Un processus concurrent du site de démonstration, avec les réglages
     * des tests (dossier, files synchrones, clés des messages) qu'il ne lit
     * pas dans son environnement.
     */
    private function demarrerDansLaDemonstration(Closure $operation): InvokedProcess
    {
        $reglages = [
            'demonstration.dossier' => config('demonstration.dossier'),
            'demonstration.messages.cles_publiques' => config('demonstration.messages.cles_publiques'),
            'demonstration.origine_du_site_reel' => config('demonstration.origine_du_site_reel'),
            'queue.connections.demonstration' => ['driver' => 'sync'],
            'queue.connections.demonstration-rattrapages' => ['driver' => 'sync'],
        ];

        return $this->demarrerUnProcessusConcurrent(static function () use ($reglages, $operation): void {
            config($reglages);
            $operation();
        });
    }

    /**
     * Attend que l'autre processus attende un verrou dans cette base, ou
     * échoue avec ce qu'il a écrit.
     */
    private function attendreUnVerrou(InvokedProcess $processus, string $base, int $limite = 30): void
    {
        config(['database.connections.demo_admin_observateur' => [...config('database.connections.demo_admin'), 'database' => self::BASE_DE_SERVICE]]);
        $observation = DB::connection('demo_admin_observateur');
        $fin = hrtime(true) + $limite * 1_000_000_000;

        try {
            while ($processus->running()) {
                if ((int) $observation->scalar("select count(*) from pg_stat_activity where datname = ? and wait_event_type in ('Lock', 'Advisory') and pid <> pg_backend_pid()", [$base]) > 0) {
                    return;
                }

                if (hrtime(true) >= $fin) {
                    $processus->stop(0);
                    $this->fail("Au bout de {$limite} s, l'autre processus n'attend aucun verrou dans {$base}.\n".$processus->output().$processus->errorOutput());
                }

                usleep(20_000);
            }

            $this->fail("L'autre processus a fini sans attendre le verrou du test dans {$base} :\n".$processus->output().$processus->errorOutput());
        } finally {
            DB::purge('demo_admin_observateur');
        }
    }
}
