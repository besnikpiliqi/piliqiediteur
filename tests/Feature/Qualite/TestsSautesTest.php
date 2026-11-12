<?php

namespace Tests\Feature\Qualite;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Un test sauté cache une partie de la suite (constat C100 des audits du
 * 07/11/2026) : un test ne se saute que pour une raison d'une liste
 * fermée, qui ne se présente jamais en CI, et la CI publie les nombres de
 * tests réussis, échoués et sautés, chaque raison de saut avec son nombre,
 * et échoue si un test y est sauté (voir tests/Qualite/resume-des-tests.php).
 */
class TestsSautesTest extends TestCase
{
    /**
     * Les seules raisons permises de sauter un test, chacune absente en CI
     * (Node.js y est installé).
     *
     * @var list<string>
     */
    private const RAISONS_PERMISES = [
        'Node.js est absent : le script de l\'avis ne se rejoue pas.',
    ];

    public function test_un_test_ne_se_saute_que_pour_une_raison_permise(): void
    {
        $refus = [];

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            if ($fichier->getExtension() === 'php' && $fichier->getRealPath() !== __FILE__) {
                $refus = [...$refus, ...self::sautsNonPermis($fichier->getContents(), 'tests/'.$fichier->getRelativePathname())];
            }
        }

        $this->assertSame([], $refus);
    }

    /**
     * La règle refuse un saut « à revoir », un test laissé inachevé et un
     * saut sans raison écrite, et laisse passer une raison permise.
     */
    public function test_un_test_saute_a_revoir_fait_echouer_la_regle(): void
    {
        $code = implode("\n", [
            '$this->markTest'."Skipped('À revoir : échoue sous Windows.');",
            '$this->markTest'."Incomplete('Pas fini.');",
            "test('x', fn () => true)->skip".'($raison);',
            '$this->markTest'."Skipped('Node.js est absent : le script de l\\'avis ne se rejoue pas.');",
        ]);

        $this->assertSame([
            'exemple.php : « À revoir : échoue sous Windows. »',
            'exemple.php : « Pas fini. »',
            'exemple.php : « sans raison écrite »',
        ], self::sautsNonPermis($code, 'exemple.php'));
    }

    /**
     * Le résumé de la CI compte les tests réussis, échoués et sautés, et
     * chaque raison de saut, sur les vrais rapports de PHPUnit : le
     * rapport JUnit, qui écrit un saut sans sa raison, et le rapport
     * TeamCity, qui la donne ; en CI, un test sauté fait échouer la tâche.
     * Les rapports viennent d'une classe de tests jetable, écrite hors de
     * tests/ et lancée par PHPUnit : deux tests sautés pour la raison
     * permise, un réussi et un échoué ; puis d'une autre, sans saut.
     */
    public function test_le_resume_de_la_ci_compte_les_tests_et_echoue_sur_un_test_saute(): void
    {
        $dossier = storage_path('framework/testing/resume-des-tests-'.getmypid());
        File::ensureDirectoryExists($dossier);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dossier));
        $raison = self::RAISONS_PERMISES[0];
        $avecSaut = $this->rapportsDePhpunit($dossier, 'AvecSautTest', [
            'test_saute_une_fois(): void { $this->markTest'.'Skipped('.var_export($raison, true).'); }',
            'test_saute_deux_fois(): void { $this->markTest'.'Skipped('.var_export($raison, true).'); }',
            'test_reussi(): void { $this->assertTrue(true); }',
            'test_echoue(): void { $this->assertTrue(false); }',
        ]);
        $sansSaut = $this->rapportsDePhpunit($dossier, 'SansSautTest', ['test_reussi(): void { $this->assertTrue(true); }']);
        $this->assertStringContainsString('<skipped/>', File::get($avecSaut[0]));
        $resumer = fn (string ...$rapports): ProcessResult => Process::path(base_path())
            ->env(['CI' => 'true', 'GITHUB_STEP_SUMMARY' => "{$dossier}/resume.md"])
            ->run([PHP_BINARY, 'tests/Qualite/resume-des-tests.php', ...$rapports]);

        $avecSautResume = $resumer(...$avecSaut);
        $this->assertSame(1, $avecSautResume->exitCode());
        $this->assertStringContainsString('2 test(s) sauté(s) en CI', $avecSautResume->errorOutput());
        $this->assertStringContainsString("| 1 | 1 | 2 |\n", File::get("{$dossier}/resume.md"));
        $this->assertStringContainsString("| {$raison} | 2 |\n", File::get("{$dossier}/resume.md"));

        File::delete("{$dossier}/resume.md");
        $this->assertSame(0, $resumer(...$sansSaut)->exitCode());
        $this->assertStringContainsString("| 1 | 0 | 0 |\n", File::get("{$dossier}/resume.md"));
        $this->assertSame(1, $resumer("{$dossier}/absent.xml", $sansSaut[1])->exitCode());
        $this->assertSame(1, $resumer($avecSaut[0])->exitCode());
    }

    /**
     * Les rapports JUnit et TeamCity de PHPUnit sur une classe de tests
     * jetable, écrite dans ce dossier avec ces méthodes.
     *
     * @param  list<string>  $methodes
     * @return array{0: string, 1: string} le rapport JUnit, le rapport TeamCity
     */
    private function rapportsDePhpunit(string $dossier, string $classe, array $methodes): array
    {
        File::put("{$dossier}/{$classe}.php", "<?php\n\nclass {$classe} extends PHPUnit\\Framework\\TestCase\n{\n"
            .implode("\n", array_map(static fn (string $methode): string => "    public function {$methode}", $methodes))
            ."\n}\n");
        Process::path(base_path())->run([
            PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration',
            '--log-junit', "{$dossier}/{$classe}.xml",
            '--log-teamcity', "{$dossier}/{$classe}.txt",
            "{$dossier}/{$classe}.php",
        ]);

        return ["{$dossier}/{$classe}.xml", "{$dossier}/{$classe}.txt"];
    }

    /**
     * Chaque saut de ce code (markTestSkipped, markTestIncomplete, skip()
     * ou todo() de Pest) dont la raison n'est pas permise, ou n'est pas
     * écrite en clair.
     *
     * @return list<string>
     */
    private static function sautsNonPermis(string $code, string $fichier): array
    {
        preg_match_all("/(?:markTestSkipped|markTestIncomplete|->skip|->todo)\\(\\s*(?:'((?:[^'\\\\]|\\\\.)*)'\\s*\\))?/u", $code, $sauts, PREG_SET_ORDER);
        $refus = [];

        foreach ($sauts as $saut) {
            $raison = isset($saut[1]) ? str_replace(["\\'", '\\\\'], ["'", '\\'], $saut[1]) : null;

            if ($raison === null || ! in_array($raison, self::RAISONS_PERMISES, true)) {
                $refus[] = "{$fichier} : « ".($raison ?? 'sans raison écrite').' »';
            }
        }

        return $refus;
    }
}
