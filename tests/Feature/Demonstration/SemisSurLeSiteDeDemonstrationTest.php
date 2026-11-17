<?php

namespace Tests\Feature\Demonstration;

use App\Console\Commands\SemerLesEtatsDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\DemonstrationTestCase;

/**
 * Le semis sur le site de démonstration (lot DEMO-COPIE, A.9 et F.8) :
 * php artisan db:seed y est refusé hors de la construction d'un modèle ;
 * demonstration:semer-les-etats pose une copie de chaque état du registre
 * qu'aucune demande semée ne fait naître, chacune avec son lien écrit à la
 * fin de la liste des comptes du semis, et une archive ; elle refuse en
 * production.
 */
class SemisSurLeSiteDeDemonstrationTest extends DemonstrationTestCase
{
    public function test_le_semis_lance_a_la_main_est_refuse_sur_le_site_de_demonstration(): void
    {
        $this->assertThrows(fn () => $this->artisan('db:seed', ['--force' => true])->run(), RuntimeException::class, DatabaseSeeder::MESSAGE_HORS_D_UNE_CONSTRUCTION);
    }

    public function test_semer_les_etats_pose_chaque_etat_du_registre_avec_son_lien_et_une_archive(): void
    {
        $dossier = sys_get_temp_dir().'/semis-des-etats-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dossier);
        config(['filesystems.disks.local.root' => $dossier]);
        Storage::forgetDisk('local');
        File::put($dossier.'/'.DatabaseSeeder::FICHIER_DES_COMPTES, "Liste des comptes du semis\n");
        $this->modeleDeTest(TypeDActivite::Services);
        config(['demonstration.copies_max' => 50]);

        try {
            $this->semerLesEtats($dossier);
        } finally {
            File::deleteDirectory($dossier);
        }
    }

    private function semerLesEtats(string $dossier): void
    {
        $this->artisan('demonstration:semer-les-etats')->assertSuccessful();

        $etats = Copie::on('demo_admin')->orderBy('id')->get();
        $this->assertCount(count(SemerLesEtatsDeLaDemonstration::ETATS), $etats);
        $this->assertSame(1, $etats->where('etat', EtatDUneCopie::Perdue)->count());
        $this->assertSame(1, $etats->where('etat', EtatDUneCopie::ARefaire)->count());
        $this->assertSame(1, $etats->where('etat', EtatDUneCopie::EnMigration)->count());
        $this->assertSame(1, $etats->where('etat', EtatDUneCopie::EnLectureSeule)->count());
        $this->assertSame(1, $etats->where('etat', EtatDUneCopie::EnAttenteDUnePlace)->count());
        $this->assertSame(1, $etats->whereNotNull('message')->where('motif_du_message', 'mise_a_jour')->count());
        $this->assertSame(1, $etats->whereNotNull('message')->where('motif_du_message', 'migration_ratee')->count());
        $this->assertSame(1, $etats->filter(fn (Copie $copie): bool => $copie->derniere_passe_des_taches_le !== null && $copie->derniere_passe_des_taches_le->lessThan(now()->subHour()))->count());
        $this->assertSame(1, Archive::on('demo_admin')->count(), 'Une archive de caisse.');

        $fichier = (string) File::get($dossier.'/'.DatabaseSeeder::FICHIER_DES_COMPTES);
        $this->assertStringStartsWith('Liste des comptes du semis', $fichier, 'La liste des comptes du semis est gardée.');
        $this->assertSame(count(SemerLesEtatsDeLaDemonstration::ETATS), substr_count($fichier, '/d#'), 'Chaque état a son lien.');

        $this->artisan('demonstration:semer-les-etats')->assertSuccessful();
        $this->assertSame(count(SemerLesEtatsDeLaDemonstration::ETATS), substr_count((string) File::get($dossier.'/'.DatabaseSeeder::FICHIER_DES_COMPTES), '/d#'), 'Un second passage remplace les liens du premier.');
        $this->assertSame(1, substr_count((string) File::get($dossier.'/'.DatabaseSeeder::FICHIER_DES_COMPTES), SemerLesEtatsDeLaDemonstration::TITRE_DANS_LE_FICHIER));

        $this->app['env'] = 'production';
        $this->artisan('demonstration:semer-les-etats')->assertFailed()->expectsOutputToContain('Rien n\'a été écrit');
    }
}
