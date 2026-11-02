<?php

namespace Tests\Browser\Support;

/**
 * L'inventaire des actions de l'interface (tests/Browser/inventaire-des-
 * actions.php) : pour chaque fichier qui déclare une action (voir
 * ActionsDeLInterface), chaque action, le lot qui écrit ses tests en
 * navigateur par rôle, et les tests en navigateur qui la couvrent déjà,
 * chacun nommé « chemin du fichier de test › nom du test ».
 */
final class Inventaire
{
    /**
     * Les lots qui écrivent les tests en navigateur de chaque action, pour
     * chaque rôle qui la voit.
     */
    public const LOTS = ['NAV-2', 'NAV-3', 'NAV-4', 'NAV-5'];

    /**
     * Le séparateur entre le fichier d'un test et son nom.
     */
    public const SEPARATEUR = ' › ';

    /**
     * @return array<string, array<string, array{lot: string, tests: list<string>}>>
     */
    public static function charger(): array
    {
        return require base_path('tests/Browser/inventaire-des-actions.php');
    }

    /**
     * Les actions de l'inventaire que ce test dit couvrir, par fichier.
     *
     * @return array<string, list<string>>
     */
    public static function promisesPar(string $test): array
    {
        $promises = [];

        foreach (self::charger() as $fichier => $actions) {
            foreach ($actions as $action => $entree) {
                if (in_array($test, $entree['tests'], true)) {
                    $promises[$fichier][] = $action;
                }
            }
        }

        return $promises;
    }

    /**
     * La référence d'un test : le chemin de son fichier depuis la racine du
     * dépôt, puis son nom.
     */
    public static function reference(string $fichierDuTest, string $nom): string
    {
        return ltrim(str_replace([base_path(), '\\'], ['', '/'], $fichierDuTest), '/').self::SEPARATEUR.$nom;
    }

    /**
     * Les noms des tests que déclare ce fichier de test (test('nom', ...)).
     *
     * @return list<string>
     */
    public static function testsDuFichier(string $chemin): array
    {
        if (! is_file(base_path($chemin))) {
            return [];
        }

        preg_match_all("/\\btest\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(base_path($chemin)), $tests);

        return array_map(fn (string $nom): string => stripcslashes($nom), $tests[1]);
    }
}
