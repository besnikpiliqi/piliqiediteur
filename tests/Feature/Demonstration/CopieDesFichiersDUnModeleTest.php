<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Les fichiers d'un modèle du site de démonstration se recopient pour
 * chaque copie par PHP seul (constat de l'éditeur du 17/11/2026 : sur son
 * poste Windows, « 'cp' n'est pas reconnu en tant que commande interne »
 * arrêtait chaque création de copie). Le clonage de `cp --reflink=auto`
 * n'est qu'une accélération sous Linux : s'il manque ou échoue, PHP copie
 * lui-même, sans laisser un dossier à moitié copié, et un lien symbolique
 * dans le modèle est refusé avant toute copie.
 */
class CopieDesFichiersDUnModeleTest extends TestCase
{
    private const BASE_DU_MODELE = 'demo_travail_services_202611220100';

    private const REFERENCE_DE_LA_COPIE = 'abababababababababababababababab';

    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->racine = storage_path('framework/testing/copie-des-fichiers/'.bin2hex(random_bytes(8)));
        config(['demonstration.dossier' => $this->racine]);
        $this->remplirLeModele();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);

        parent::tearDown();
    }

    public function test_les_fichiers_du_modele_se_recopient_avec_leur_contenu_leurs_droits_et_leurs_dossiers_vides(): void
    {
        $this->copier();

        $this->assertSame($this->contenuDe(DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE)), $this->contenuDe(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE)));
        $this->assertSame("%PDF-1.7\n1 0 obj\n", File::get(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE).'/local/pieces/2026/facture.pdf'));
        $this->assertTrue(File::isDirectory(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE).'/local/vide'));
        $this->assertSame(0640, fileperms(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE).'/local/pieces/2026/facture.pdf') & 0777);
    }

    /**
     * Le cas du poste Windows : aucun programme `cp` dans le PATH.
     */
    public function test_la_copie_aboutit_quand_cp_est_introuvable(): void
    {
        $chemin = getenv('PATH');
        putenv('PATH='.$this->racine.'/aucun-programme');

        try {
            $this->copier();
        } finally {
            putenv($chemin === false ? 'PATH' : 'PATH='.$chemin);
        }

        $this->assertSame($this->contenuDe(DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE)), $this->contenuDe(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE)));
    }

    /**
     * `cp` présent mais en échec (une version qui ne connaît pas
     * --reflink, un disque plein au milieu) : le dossier à moitié copié est
     * effacé, PHP recommence, la copie est celle du modèle et rien d'autre.
     */
    public function test_un_cp_qui_echoue_laisse_la_copie_a_php_sans_reste_du_clone_interrompu(): void
    {
        $cible = DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE);
        Process::fake(function (PendingProcess $processus) use ($cible): FakeProcessResult {
            File::ensureDirectoryExists($cible.'/local');
            File::put($cible.'/local/reste-du-clone-interrompu.txt', 'à moitié');

            return Process::result(errorOutput: 'cp: option non reconnue -- reflink', exitCode: 1);
        });

        $this->copier();

        $this->assertFileDoesNotExist($cible.'/local/reste-du-clone-interrompu.txt');
        $this->assertSame($this->contenuDe(DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE)), $this->contenuDe($cible));
    }

    public function test_une_ancienne_copie_de_la_meme_reference_est_remplacee_en_entier(): void
    {
        $cible = DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE);
        File::ensureDirectoryExists($cible.'/local');
        File::put($cible.'/local/ancien-fichier.txt', 'd\'une copie recommencée');

        $this->copier();

        $this->assertFileDoesNotExist($cible.'/local/ancien-fichier.txt');
        $this->assertSame($this->contenuDe(DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE)), $this->contenuDe($cible));
    }

    public function test_un_modele_sans_dossier_donne_une_copie_vide(): void
    {
        File::deleteDirectory(DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE));

        $this->copier();

        $this->assertTrue(File::isDirectory(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE)));
        $this->assertSame([], $this->contenuDe(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE)));
    }

    /**
     * Un lien symbolique dans le modèle ferait lire, ou écrire, hors de son
     * dossier : la copie est refusée avant d'avoir commencé, et aucun
     * dossier de copie n'est laissé. (Ce test crée un lien symbolique : sous
     * Windows, il demande le mode développeur, README.)
     */
    public function test_un_lien_symbolique_dans_le_modele_est_refuse_avant_toute_copie(): void
    {
        $modele = DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE);
        File::put($this->racine.'/secret-hors-du-modele.txt', 'hors du modèle');
        $this->assertTrue(symlink($this->racine.'/secret-hors-du-modele.txt', $modele.'/local/pieces/lien.txt'), 'Ce poste ne sait pas créer de lien symbolique (sous Windows : mode développeur).');

        try {
            $this->copier();
            $this->fail('Le modèle porte un lien symbolique : la copie devait être refusée.');
        } catch (RuntimeException $refus) {
            $this->assertSame(sprintf(DossiersDeLaDemonstration::MESSAGE_LIEN_SYMBOLIQUE, $modele.'/local/pieces/lien.txt'), str_replace('\\', '/', $refus->getMessage()));
        }

        $this->assertDirectoryDoesNotExist(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE_DE_LA_COPIE));
    }

    private function copier(): void
    {
        DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE_DU_MODELE, self::REFERENCE_DE_LA_COPIE);
    }

    /**
     * Un modèle avec des dossiers imbriqués, un dossier vide, un fichier
     * caché, un fichier binaire et un fichier aux droits resserrés.
     */
    private function remplirLeModele(): void
    {
        $modele = DossiersDeLaDemonstration::duModele(self::BASE_DU_MODELE);
        File::ensureDirectoryExists($modele.'/local/pieces/2026');
        File::ensureDirectoryExists($modele.'/local/vide');
        File::put($modele.'/local/pieces/2026/facture.pdf', "%PDF-1.7\n1 0 obj\n");
        chmod($modele.'/local/pieces/2026/facture.pdf', 0640);
        File::put($modele.'/local/.gitignore', "*\n!.gitignore\n");
        File::put($modele.'/local/pieces/binaire.bin', random_bytes(4096));
        touch($modele.'/local/pieces/binaire.bin', 1_700_000_000);
    }

    /**
     * Le contenu d'un dossier : chaque chemin relatif avec l'empreinte de
     * son contenu, ou « dossier ».
     *
     * @return array<string, string>
     */
    private function contenuDe(string $dossier): array
    {
        $contenu = [];

        foreach (Finder::create()->in($dossier)->ignoreDotFiles(false)->ignoreVCS(false) as $element) {
            $contenu[str_replace('\\', '/', $element->getRelativePathname())] = $element->isDir()
                ? 'dossier'
                : sha1_file($element->getPathname()).' '.decoct($element->getPerms() & 0777).' '.$element->getMTime();
        }

        ksort($contenu);

        return $contenu;
    }
}
