<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Après chaque enregistrement, retour à la page parente (décision de
 * l'éditeur des messages 71 et 110, D34, que CLAUDE.md n'écrit pas encore :
 * historique/DIRECTIVES.md, § 2.2, point 4 : « pour tous les sauvegardes
 * qui se trouvent sur le logiciel, [ils] doivent se rediriger si la page a
 * sa page parent ») : chaque page de création ou de modification d'une
 * fiche revient à la liste de sa ressource par RevientALaListe, sans
 * exception. Ce test lit les pages, sans base de données.
 */
class RetourALaListeTest extends TestCase
{
    public function test_chaque_page_de_creation_ou_de_modification_revient_a_la_liste_de_sa_ressource(): void
    {
        $racine = dirname(__DIR__, 2);
        $pages = [];
        $fautes = [];

        foreach (self::fichiersPhp($racine.'/app/Filament') as $chemin) {
            $code = (string) file_get_contents($chemin);

            if (preg_match('/\bextends\s+(CreateRecord|EditRecord)\b/', $code) !== 1) {
                continue;
            }

            $page = substr($chemin, strlen($racine) + 1);
            $pages[] = $page;

            if (preg_match('/^    use\s+[^;]*\bRevientALaListe\s*[,;]/m', $code) !== 1) {
                $fautes[] = $page.' : ne revient pas à la liste de sa ressource';
            }

            if (preg_match('/\bfunction\s+getRedirectUrl\s*\(/', $code) === 1) {
                $fautes[] = $page.' : remplace le retour à la liste par une autre page';
            }
        }

        $this->assertNotEmpty($pages, 'Aucune page de création ni de modification trouvée.');
        $this->assertSame([], $fautes);
    }

    public function test_revenir_a_la_liste_mene_a_l_index_de_la_ressource(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2).'/app/Filament/Concerns/RevientALaListe.php');

        $this->assertMatchesRegularExpression('/function getRedirectUrl\(\): string\s*\{\s*return [^;]*static::getResource\(\)::getUrl\(\'index\'\)/s', $code);
    }

    /**
     * Les fichiers PHP de ce dossier et de ses sous-dossiers, dans l'ordre
     * de leur chemin.
     *
     * @return list<string>
     */
    private static function fichiersPhp(string $dossier): array
    {
        $fichiers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getExtension() === 'php') {
                $fichiers[] = $fichier->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }
}
