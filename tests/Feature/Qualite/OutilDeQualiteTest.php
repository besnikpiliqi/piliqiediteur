<?php

namespace Tests\Feature\Qualite;

use Illuminate\Filesystem\Filesystem;
use Infection\Command\RunCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Le contrôle de la couverture de tests/Qualite/qualite.php (décision de
 * l'éditeur du 01/11/2026, point 1) range chaque fichier d'un rapport
 * Clover dans sa partie par son chemin relatif à la racine du dépôt, quel
 * que soit le dossier où le dépôt est monté (« /app » d'un conteneur
 * compris), refuse un rapport venu d'un autre dépôt, et ne laisse jamais
 * passer en silence une partie qui a un seuil sans être mesurée ; il
 * nomme depuis la racine du dépôt les fichiers sensibles changés, que la
 * CI donne entiers à Infection, qui les lit tous comme des chemins (la
 * commande de la CI, lue dans son fichier) ; le contrôle du témoin de
 * l'analyse des flux exige exactement les chemins qu'il annonce. Chaque
 * cas de l'outil le lance, comme la CI, sur une copie de tests/Qualite
 * placée dans un dépôt de test nommé « app ».
 */
class OutilDeQualiteTest extends TestCase
{
    private string $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = sys_get_temp_dir().'/piliqi-qualite-'.getmypid().'/app';
        $fichiers = new Filesystem;
        $fichiers->deleteDirectory(dirname($this->depot));
        $fichiers->ensureDirectoryExists($this->depot.'/tests/Qualite');
        $this->depot = (string) realpath($this->depot);

        foreach (['qualite.php', 'parties.php'] as $outil) {
            $fichiers->copy(__DIR__.'/../../Qualite/'.$outil, $this->depot.'/tests/Qualite/'.$outil);
        }

        $this->ecrire('tests/Qualite/seuils.json', json_encode([
            'analyse_statique' => ['niveau' => 6],
            'couverture' => ['calculs' => 100, 'droits_et_isolation' => 50],
            'mutation' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(dirname($this->depot));

        parent::tearDown();
    }

    public function test_un_depot_monte_sous_app_range_chaque_fichier_dans_sa_partie(): void
    {
        $clover = $this->clover([
            $this->depot.'/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3, 11 => 1],
            $this->depot.'/app/Policies/FacturePolicy.php' => [20 => 2, 21 => 0],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(0, $code, $sortie);
        $this->assertStringContainsString('| calculs | 2 | 2 | 100.0 % | 100.0 % |', $sortie);
        $this->assertStringContainsString('| droits_et_isolation | 2 | 1 | 50.0 % | 50.0 % |', $sortie);
        $this->assertStringNotContainsString('ecrans_et_socle', $sortie);
    }

    public function test_une_partie_qui_a_un_seuil_sans_aucune_ligne_mesuree_fait_echouer_le_controle(): void
    {
        $clover = $this->clover([
            $this->depot.'/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('| droits_et_isolation | 0 | 0 | non mesurée | 50.0 % |', $sortie);
        $this->assertStringContainsString('non mesurée', $sortie);
    }

    public function test_un_rapport_venu_d_un_autre_depot_est_refuse(): void
    {
        $clover = $this->clover([
            '/ailleurs/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Fichier hors du dépôt', $sortie);
    }

    public function test_le_nouveau_code_d_un_depot_monte_sous_app_retrouve_ses_lignes_changees(): void
    {
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\nfunction a(): int\n{\n    return 1;\n}\n");
        $this->git('init', '--quiet');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Base');
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\nfunction a(): int\n{\n    return 2;\n}\n");
        $clover = $this->clover([$this->depot.'/app/Domain/Exemple.php' => [5 => 0]]);

        [$code, $sortie] = $this->lancer('nouveau-code', $clover, 'HEAD');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('app/Domain/Exemple.php:5', $sortie);
    }

    /**
     * La CI donne à Infection, comme chemins, les fichiers des parties
     * sensibles que l'envoi ajoute, modifie ou renomme, pour qu'il mute
     * chacun en entier (avec un filtre de git, il ne muterait que leurs
     * lignes changées) : chacun nommé depuis la racine du dépôt, jamais un
     * fichier d'une autre partie ni un fichier retiré.
     */
    public function test_les_fichiers_sensibles_changes_se_nomment_depuis_la_racine_du_depot(): void
    {
        foreach (['app/Domain/Facturation/Services/CalculateurLigne.php', 'app/Policies/FacturePolicy.php', 'app/Policies/DevisPolicy.php', 'app/Domain/Exemple.php'] as $fichier) {
            $this->ecrire($fichier, "<?php\n\n// avant l'envoi\n");
        }
        $this->git('init', '--quiet');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Base');
        $this->ecrire('app/Domain/Facturation/Services/CalculateurLigne.php', "<?php\n\n// modifié\n");
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\n// modifié\n");
        $this->ecrire('app/Models/User.php', "<?php\n\n// ajouté\n");
        $this->git('mv', 'app/Policies/FacturePolicy.php', 'app/Policies/FacturesPolicy.php');
        $this->git('rm', '--quiet', 'app/Policies/DevisPolicy.php');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Envoi');

        [$code, $sortie] = $this->lancer('sensibles-changes', 'HEAD~1');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame("app/Domain/Facturation/Services/CalculateurLigne.php\napp/Models/User.php\napp/Policies/FacturesPolicy.php\n", $sortie);
    }

    /**
     * La tâche de mutation de la CI donne ces fichiers à Infection, qui les
     * lit tous comme les chemins à muter : aucun filtre de git
     * (--git-diff-filter, --git-diff-lines), avec lequel il ne muterait que
     * leurs lignes changées, et aucun chemin pris pour la valeur d'une option
     * qui en accepte une (--ignore-msi-with-no-mutations avalait le premier
     * quand les chemins le suivaient sans « -- »).
     */
    public function test_la_ci_donne_a_infection_les_chemins_des_fichiers_sensibles_changes(): void
    {
        $ci = Yaml::parseFile(__DIR__.'/../../../.github/workflows/tests.yml');
        $etape = array_values(array_filter($ci['jobs']['couverture']['steps'], fn (array $etape): bool => ($etape['name'] ?? '') === 'Tests de mutation des parties sensibles changées'))[0];
        preg_match('#vendor/bin/infection (.+)$#m', $etape['run'], $commande);
        $fichiers = ['app/Domain/Facturation/Services/CalculateurLigne.php', 'app/Models/User.php', 'app/Policies/FacturePolicy.php'];

        $arguments = new StringInput(str_replace('$fichiers', implode(' ', $fichiers), $commande[1]));
        $arguments->bind((new RunCommand)->getDefinition());

        $this->assertStringContainsString('fichiers=$(php tests/Qualite/qualite.php sensibles-changes "$BASE")', $etape['run']);
        $this->assertSame($fichiers, $arguments->getArgument('paths'));
        $this->assertNull($arguments->getOption('git-diff-filter'));
        $this->assertFalse($arguments->getOption('git-diff-lines'));
    }

    public function test_le_temoin_de_l_analyse_des_flux_exige_exactement_les_chemins_qu_il_annonce(): void
    {
        $this->ecrire('tests/Qualite/Psalm/Temoin/Temoin.php', "<?php\n\necho \$x; // attendu : TaintedHtml, TaintedTextWithQuotes\nDB::select(\$y); // attendu : TaintedSql\n");
        $chemin = fn (int $ligne, string $type): array => ['file_name' => $this->depot.'/tests/Qualite/Psalm/Temoin/Temoin.php', 'line_from' => $ligne, 'type' => $type, 'message' => '', 'is_security' => true];
        $tous = [$chemin(3, 'TaintedHtml'), $chemin(3, 'TaintedTextWithQuotes'), $chemin(4, 'TaintedSql')];

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode($tous, JSON_THROW_ON_ERROR)));
        $this->assertSame(0, $code, $sortie);

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode(array_slice($tous, 0, 2), JSON_THROW_ON_ERROR)));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('chemin non vu : tests/Qualite/Psalm/Temoin/Temoin.php:4 TaintedSql', $sortie);

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode([...$tous, $chemin(2, 'TaintedSql')], JSON_THROW_ON_ERROR)));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('chemin en trop : tests/Qualite/Psalm/Temoin/Temoin.php:2 TaintedSql', $sortie);
    }

    /**
     * Un rapport Clover de ces fichiers : par chemin, le nombre de tests
     * qui exécutent chaque ligne.
     *
     * @param  array<string, array<int, int>>  $fichiers
     */
    private function clover(array $fichiers): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project>';

        foreach ($fichiers as $nom => $lignes) {
            $xml .= '<file name="'.htmlspecialchars($nom).'">';

            foreach ($lignes as $numero => $nombre) {
                $xml .= '<line num="'.$numero.'" type="stmt" count="'.$nombre.'"/>';
            }

            $xml .= '</file>';
        }

        return $this->ecrire('clover.xml', $xml.'</project></coverage>');
    }

    private function ecrire(string $chemin, string $contenu): string
    {
        (new Filesystem)->ensureDirectoryExists(dirname($this->depot.'/'.$chemin));
        file_put_contents($this->depot.'/'.$chemin, $contenu);

        return $this->depot.'/'.$chemin;
    }

    private function git(string ...$arguments): void
    {
        $git = new Process(['git', '-C', $this->depot, ...$arguments]);
        $git->mustRun();
    }

    /**
     * @return array{0: int, 1: string} le code de sortie et la sortie de l'outil
     */
    private function lancer(string ...$arguments): array
    {
        $outil = new Process([PHP_BINARY, $this->depot.'/tests/Qualite/qualite.php', ...$arguments], $this->depot);
        $outil->run();

        return [(int) $outil->getExitCode(), $outil->getOutput().$outil->getErrorOutput()];
    }
}
