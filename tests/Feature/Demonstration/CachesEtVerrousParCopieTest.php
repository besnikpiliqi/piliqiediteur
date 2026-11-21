<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Tests\DemonstrationTestCase;

/**
 * Les caches, les verrous, le limiteur de débit et les disques de chaque
 * copie (lot DEMO-COPIE, C.6.4, C.6.10 et C.6.11, N29) : la même clé de
 * limite ou de verrou (celle d'un travail unique, faite des identifiants
 * des fiches, les mêmes dans toutes les copies) vit dans la base de sa
 * copie ; chaque disque local de config/filesystems.php est réglé sous le
 * dossier de la copie ; ce qu'une requête a résolu pour une copie ne sert
 * jamais à la requête suivante d'une autre ; aucun code n'écrit par
 * storage_path('app…') hors d'un disque.
 */
class CachesEtVerrousParCopieTest extends DemonstrationTestCase
{
    public function test_la_meme_cle_de_limite_et_de_verrou_est_independante_dans_deux_copies(): void
    {
        ['copie' => $a] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $b] = $this->copieDeTest(TypeDActivite::Services);
        $ouverture = app(OuvertureDUneCopie::class);

        $ouverture->pendant($a, function (): void {
            RateLimiter::hit('envois:magasin:1', 3600);
            RateLimiter::hit('envois:magasin:1', 3600);
            $this->assertTrue(Cache::lock('laravel_unique_job:App\\Jobs\\EnvoyerDocumentJob:1', 600)->get(), 'Le verrou du travail unique se prend dans la copie A.');
        });

        $ouverture->pendant($b, function (): void {
            $this->assertSame(0, RateLimiter::attempts('envois:magasin:1'), 'La limite de la copie A ne compte rien dans la copie B.');
            $this->assertTrue(Cache::lock('laravel_unique_job:App\\Jobs\\EnvoyerDocumentJob:1', 600)->get(), 'Le même travail unique de la copie B n\'attend jamais celui de la copie A.');
        });

        $ouverture->pendant($a, function (): void {
            $this->assertSame(2, RateLimiter::attempts('envois:magasin:1'));
            $this->assertFalse(Cache::lock('laravel_unique_job:App\\Jobs\\EnvoyerDocumentJob:1', 600)->get());
        });
    }

    public function test_chaque_disque_local_est_regle_sous_le_dossier_de_la_copie_et_disparait_avec_elle(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $dossier = DossiersDeLaDemonstration::dUneCopie($copie->reference);
        $locaux = array_keys(array_filter((array) config('filesystems.disks'), static fn (mixed $disque): bool => is_array($disque) && ($disque['driver'] ?? null) === 'local'));
        $this->assertContains('telechargements', $locaux);

        app(OuvertureDUneCopie::class)->pendant($copie, function () use ($locaux, $dossier): void {
            foreach ($locaux as $nom) {
                $this->assertSame($dossier.'/'.$nom, config("filesystems.disks.{$nom}.root"), "Le disque « {$nom} » écrit sous le dossier de la copie.");
            }

            Storage::disk('telechargements')->put('documents-du-mois.zip', 'ZIP préparé dans la copie');
        });

        $this->assertFileExists($dossier.'/telechargements/documents-du-mois.zip');
        $this->assertNotSame($dossier.'/telechargements', config('filesystems.disks.telechargements.root'), 'Hors de la copie, les disques reviennent à leur dossier.');

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'supprimer', 'reference' => $copie->reference]))->assertOk();

        $this->assertDirectoryDoesNotExist($dossier, 'Le ZIP préparé dans la copie disparaît avec elle.');
    }

    public function test_ce_qu_une_requete_a_resolu_pour_une_copie_ne_sert_pas_a_la_requete_suivante_d_une_autre(): void
    {
        ['copie' => $a, 'secret' => $secretA] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $b, 'secret' => $secretB] = $this->copieDeTest(TypeDActivite::Services);
        $temoinA = $this->entrerParLeLien($secretA);
        $temoinB = $this->entrerParLeLien($secretB);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinA)->get('/app')->assertRedirect();
        Cache::put('derniere-visite', 'copie A', 600);
        RateLimiter::hit('essais-de-connexion', 60);
        $this->assertSame($this->baseDe($a), config('database.connections.pgsql.database'));

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinB)->get('/app')->assertRedirect();
        $this->assertSame($this->baseDe($b), config('database.connections.pgsql.database'));
        $this->assertNull(Cache::get('derniere-visite'), 'Le cache résolu pour la copie A ne sert pas à la copie B.');
        $this->assertSame(0, RateLimiter::attempts('essais-de-connexion'), 'Le limiteur résolu pour la copie A ne sert pas à la copie B.');
    }

    public function test_aucun_code_n_ecrit_par_le_dossier_storage_app_hors_d_un_disque(): void
    {
        $fautes = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $fichier) {
            if (preg_match('/storage_path\(\s*[\'"]app/', $fichier->getContents()) === 1) {
                $fautes[] = $fichier->getRelativePathname();
            }
        }

        $this->assertSame([], $fautes, 'Un fichier écrit par storage_path(\'app…\') échapperait au dossier de la copie : passez par un disque de config/filesystems.php.');
    }

    private function baseDe(Copie $copie): string
    {
        return (string) $copie->fresh()?->nom_de_la_base;
    }
}
