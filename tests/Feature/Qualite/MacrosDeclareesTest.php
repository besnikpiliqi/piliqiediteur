<?php

namespace Tests\Feature\Qualite;

use Closure;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Le fichier tests/Qualite/macros.stub déclare à l'analyse statique les
 * macros que AppServiceProvider ajoute aux composants de Filament : il ne
 * déclare que des macros réellement enregistrées, avec leurs paramètres.
 * Une macro retirée ou renommée fait échouer ce test, au lieu de rester
 * déclarée à Larastan, qui la croirait toujours là.
 */
class MacrosDeclareesTest extends TestCase
{
    public function test_chaque_macro_declaree_a_l_analyse_statique_est_enregistree_avec_ses_parametres(): void
    {
        $declarees = $this->macrosDeclarees();

        $this->assertNotEmpty($declarees);

        foreach ($declarees as [$classe, $nom, $parametres]) {
            $this->assertTrue($classe::hasMacro($nom), "{$classe}::{$nom}() est déclarée dans macros.stub mais n'est pas une macro enregistrée.");

            $macros = (new ReflectionProperty($classe, 'macros'))->getValue();
            $macro = $macros[$nom][$classe] ?? null;

            $this->assertInstanceOf(Closure::class, $macro, "{$classe}::{$nom}() n'est pas enregistrée sur {$classe} lui-même.");
            $this->assertSame(
                $parametres,
                array_map(fn (ReflectionParameter $parametre): string => $parametre->getName(), (new ReflectionFunction($macro))->getParameters()),
                "Les paramètres de {$classe}::{$nom}() ne sont pas ceux que déclare macros.stub.",
            );
        }
    }

    /**
     * Les @method du fichier, avec la classe qui les porte et le nom de
     * leurs paramètres.
     *
     * @return list<array{0: class-string, 1: string, 2: list<string>}>
     */
    private function macrosDeclarees(): array
    {
        $declarees = [];
        $espace = '';
        $methodes = [];

        foreach (file(base_path('tests/Qualite/macros.stub'), FILE_IGNORE_NEW_LINES) as $ligne) {
            if (preg_match('/^namespace ([\w\\\\]+);$/', $ligne, $morceaux) === 1) {
                $espace = $morceaux[1];
            } elseif (preg_match('/@method \S+ (\w+)\((.*)\)$/', $ligne, $morceaux) === 1) {
                preg_match_all('/\$(\w+)/', $morceaux[2], $noms);
                $methodes[] = [$morceaux[1], $noms[1]];
            } elseif (preg_match('/^class (\w+) \{\}$/', $ligne, $morceaux) === 1) {
                foreach ($methodes as [$nom, $parametres]) {
                    $declarees[] = [$espace.'\\'.$morceaux[1], $nom, $parametres];
                }

                $methodes = [];
            }
        }

        return $declarees;
    }
}
