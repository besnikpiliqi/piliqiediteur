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
     * chaque raison de saut ; en CI, un test sauté fait échouer la tâche.
     */
    public function test_le_resume_de_la_ci_compte_les_tests_et_echoue_sur_un_test_saute(): void
    {
        $dossier = storage_path('framework/testing/resume-des-tests-'.getmypid());
        File::ensureDirectoryExists($dossier);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dossier));
        File::put("{$dossier}/avec-saut.xml", '<?xml version="1.0"?><testsuites><testsuite name="s">'
            .'<testcase name="a"/><testcase name="b"><failure>non</failure></testcase>'
            .'<testcase name="c"><skipped message="Node.js est absent"/></testcase><testcase name="d"><skipped message="Node.js est absent"/></testcase>'
            .'</testsuite></testsuites>');
        File::put("{$dossier}/sans-saut.xml", '<?xml version="1.0"?><testsuites><testsuite name="s"><testcase name="a"/><testcase name="b"/></testsuite></testsuites>');
        $resumer = fn (string $rapport): ProcessResult => Process::path(base_path())
            ->env(['CI' => 'true', 'GITHUB_STEP_SUMMARY' => "{$dossier}/resume.md"])
            ->run([PHP_BINARY, 'tests/Qualite/resume-des-tests.php', "{$dossier}/{$rapport}"]);

        $avecSaut = $resumer('avec-saut.xml');
        $this->assertSame(1, $avecSaut->exitCode());
        $this->assertStringContainsString('2 test(s) sauté(s) en CI', $avecSaut->errorOutput());
        $this->assertStringContainsString("| 1 | 1 | 2 |\n", File::get("{$dossier}/resume.md"));
        $this->assertStringContainsString("| Node.js est absent | 2 |\n", File::get("{$dossier}/resume.md"));

        File::delete("{$dossier}/resume.md");
        $sansSaut = $resumer('sans-saut.xml');
        $this->assertSame(0, $sansSaut->exitCode());
        $this->assertStringContainsString("| 2 | 0 | 0 |\n", File::get("{$dossier}/resume.md"));
        $this->assertSame(1, $resumer('absent.xml')->exitCode());
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
