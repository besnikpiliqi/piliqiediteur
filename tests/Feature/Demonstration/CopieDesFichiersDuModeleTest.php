<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process as ProcessSymfony;
use Tests\TestCase;

/**
 * La recopie des fichiers d'un modèle pour une copie (lot DEMO-COPIE, C.3) :
 * elle ne dépend d'aucun programme extérieur. Le poste de l'éditeur est sous
 * Windows (XAMPP), où « cp » n'existe pas : « Les fichiers du modèle n'ont
 * pas pu être recopiés : 'cp' n'est pas reconnu en tant que commande
 * interne » arrêtait chaque création de copie. Le clone de fichiers de Linux
 * n'est qu'une optimisation, jamais nécessaire.
 */
class CopieDesFichiersDuModeleTest extends TestCase
{
    private const BASE = 'demo_travail_commerce_202611171200';

    private const REFERENCE = '0123456789abcdef0123456789abcdef';

    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->racine = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piliqi-copie-des-fichiers-'.getmypid();
        File::deleteDirectory($this->racine);
        config(['demonstration.dossier' => $this->racine]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->racine));
    }

    public function test_les_fichiers_du_modele_se_recopient_avec_leur_contenu_leurs_droits_et_leurs_sous_dossiers(): void
    {
        $this->poserLeModele();
        $this->poserUneAncienneCopie();

        DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);

        $this->assertCopieConforme();
    }

    /**
     * Sans « cp » (Windows : le programme répond « n'est pas reconnu en tant
     * que commande interne » ; un serveur sans coreutils : il ne se lance
     * pas), la copie se fait en PHP, avec les mêmes fichiers.
     *
     * @return array<string, array{Closure(): ProcessResult}>
     */
    public static function copiesSansCp(): array
    {
        return [
            'cp répond qu\'il n\'est pas reconnu' => [fn (): ProcessResult => Process::result(errorOutput: "'cp' n'est pas reconnu en tant que commande interne ou externe, un programme exécutable ou un fichier de commandes.", exitCode: 1)],
            'cp ne se lance pas' => [fn (): ProcessResult => throw new ProcessStartFailedException(new ProcessSymfony(['cp']), 'cp introuvable')],
        ];
    }

    #[DataProvider('copiesSansCp')]
    public function test_sans_cp_la_copie_se_fait_en_php_avec_les_memes_fichiers(Closure $reponse): void
    {
        $this->poserLeModele();
        $this->poserUneAncienneCopie();
        Process::preventStrayProcesses();
        Process::fake(['*' => $reponse]);

        DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);

        $this->assertCopieConforme();
    }

    /**
     * Le chemin du système sans aucun programme (PATH vide) : ni cp ni rien
     * d'autre ne se lance, et la copie est la même.
     */
    public function test_la_copie_ne_demande_aucun_programme_au_systeme(): void
    {
        $this->poserLeModele();
        $chemin = (string) getenv('PATH');
        putenv('PATH=');

        try {
            DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);
        } finally {
            putenv('PATH='.$chemin);
        }

        $this->assertCopieConforme();
    }

    public function test_un_modele_sans_dossier_donne_une_copie_vide(): void
    {
        DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);

        $this->assertDirectoryExists(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE));
        $this->assertSame([], File::allFiles(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE)));
    }

    /**
     * Un lien symbolique dans le modèle, à la racine comme plus bas, fait
     * refuser la copie avant d'en écrire une : une copie ne suit jamais un
     * lien hors de son dossier (ici, un fichier qu'aucune copie ne doit
     * lire).
     */
    #[DataProvider('placesDUnLien')]
    public function test_un_lien_symbolique_dans_le_modele_est_refuse_avant_toute_copie(string $place): void
    {
        $this->poserLeModele();
        $secret = $this->racine.DIRECTORY_SEPARATOR.'secret.txt';
        File::put($secret, 'hors du modèle');
        $lien = DossiersDeLaDemonstration::duModele(self::BASE).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $place);
        File::ensureDirectoryExists(dirname($lien));
        symlink($secret, $lien);

        try {
            DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);
            $this->fail('Un modèle qui porte un lien symbolique ne se recopie pas.');
        } catch (RuntimeException $refus) {
            $this->assertSame(sprintf(DossiersDeLaDemonstration::MESSAGE_LIEN_DANS_LE_MODELE, str_replace('/', DIRECTORY_SEPARATOR, $place)), $refus->getMessage());
        }

        $this->assertDirectoryDoesNotExist(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE));
    }

    /**
     * Le dossier du modèle lui-même ne peut pas être un lien : il pointerait
     * hors du dossier des modèles.
     */
    public function test_un_dossier_de_modele_qui_est_un_lien_symbolique_est_refuse(): void
    {
        $ailleurs = $this->racine.DIRECTORY_SEPARATOR.'ailleurs';
        File::ensureDirectoryExists($ailleurs);
        File::put($ailleurs.DIRECTORY_SEPARATOR.'secret.txt', 'hors du modèle');
        $modele = DossiersDeLaDemonstration::duModele(self::BASE);
        File::ensureDirectoryExists(dirname($modele));
        symlink($ailleurs, $modele);

        try {
            DossiersDeLaDemonstration::copierLesFichiersDuModele(self::BASE, self::REFERENCE);
            $this->fail('Un dossier de modèle qui est un lien symbolique ne se recopie pas.');
        } catch (RuntimeException $refus) {
            $this->assertSame(sprintf(DossiersDeLaDemonstration::MESSAGE_LIEN_DANS_LE_MODELE, self::BASE), $refus->getMessage());
        }

        $this->assertDirectoryDoesNotExist(DossiersDeLaDemonstration::dUneCopie(self::REFERENCE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function placesDUnLien(): array
    {
        return [
            'à la racine du modèle' => ['lien.txt'],
            'dans un sous-dossier' => ['local/pieces/lien.pdf'],
        ];
    }

    private function poserLeModele(): void
    {
        $modele = DossiersDeLaDemonstration::duModele(self::BASE);
        File::ensureDirectoryExists($modele.'/local/pieces');
        File::ensureDirectoryExists($modele.'/local/vide');
        File::put($modele.'/local/pieces/facture.pdf', "%PDF-1.7\nune pièce du modèle\n");
        File::put($modele.'/local/note.txt', 'une note privée');
        chmod($modele.'/local/note.txt', 0600);
        File::put($modele.'/racine.txt', 'à la racine');
        touch($modele.'/racine.txt', self::aParis('2026-11-10 08:30:00')->getTimestamp());
    }

    private function poserUneAncienneCopie(): void
    {
        $copie = DossiersDeLaDemonstration::dUneCopie(self::REFERENCE);
        File::ensureDirectoryExists($copie.'/local');
        File::put($copie.'/local/ancien.txt', 'un fichier d\'une copie d\'avant, que la recopie remplace');
    }

    /**
     * La copie a les fichiers du modèle, leurs droits, la date de
     * modification d'un fichier, ses dossiers vides, et rien d'une copie
     * d'avant ; le modèle n'a pas changé.
     */
    private function assertCopieConforme(): void
    {
        $modele = DossiersDeLaDemonstration::duModele(self::BASE);
        $copie = DossiersDeLaDemonstration::dUneCopie(self::REFERENCE);

        $this->assertSame("%PDF-1.7\nune pièce du modèle\n", File::get($copie.'/local/pieces/facture.pdf'));
        $this->assertSame('une note privée', File::get($copie.'/local/note.txt'));
        $this->assertSame('à la racine', File::get($copie.'/racine.txt'));
        $this->assertDirectoryExists($copie.'/local/vide');
        $this->assertFileDoesNotExist($copie.'/local/ancien.txt');

        foreach (['local/note.txt', 'local/pieces/facture.pdf', 'racine.txt'] as $fichier) {
            $this->assertSame(fileperms($modele.'/'.$fichier) & 0777, fileperms($copie.'/'.$fichier) & 0777, "Les droits de « {$fichier} » sont ceux du modèle.");
        }

        $this->assertSame(self::aParis('2026-11-10 08:30:00')->getTimestamp(), filemtime($copie.'/racine.txt'));
        $this->assertSame('une note privée', File::get($modele.'/local/note.txt'));
        $this->assertFileExists($modele.'/racine.txt');
    }
}
