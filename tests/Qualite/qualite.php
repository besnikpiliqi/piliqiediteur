<?php

/*
 * Contrôles des outils de qualité (décision de l'éditeur du 01/11/2026),
 * lancés par la CI et en local depuis la racine du dépôt :
 *
 *   php tests/Qualite/qualite.php couverture <clover.xml> [<rapport.md>]
 *       La couverture des lignes de chaque partie (parties.php), comparée
 *       à son seuil (seuils.json) : échoue sous le seuil.
 *   php tests/Qualite/qualite.php nouveau-code <clover.xml> <commit de base>
 *       Chaque ligne exécutable de app/ ajoutée ou modifiée depuis ce
 *       commit est exécutée par au moins un test : échoue sinon.
 *   php tests/Qualite/qualite.php mutation <infection.json> [<rapport.md>]
 *       Le score de mutation (MSI) de chaque partie sensible dont des
 *       mutants ont été testés, comparé à son seuil : échoue sous le seuil.
 *       Le rapport liste aussi les mutants non conclus, lus dans le journal
 *       texte d'Infection rangé à côté (infection.log).
 *   php tests/Qualite/qualite.php seuils <ancien seuils.json>
 *       Aucun seuil ne baisse par rapport à l'ancien fichier, et le niveau
 *       de l'analyse statique de phpstan.neon est celui de seuils.json.
 *   php tests/Qualite/qualite.php sensibles
 *       Les chemins des parties sensibles, un par ligne, à donner à
 *       Infection comme chemins à muter.
 *   php tests/Qualite/qualite.php configuration-sensibles <destination>
 *       Écrit la configuration d'Infection (infection.json5) limitée aux
 *       parties sensibles : tout app/, moins chaque dossier et chaque
 *       fichier qui n'en est pas. Elle se combine avec --git-diff-lines
 *       (les seules lignes changées, comme la CI), qui ne se combine avec
 *       aucun autre filtre des fichiers.
 *   php tests/Qualite/qualite.php sensibles-changes <commit de base>
 *       Les fichiers des parties sensibles ajoutés ou modifiés depuis ce
 *       commit, un par ligne (rien quand aucun ne l'est) : ceux dont la CI
 *       teste les mutants à chaque envoi.
 *   php tests/Qualite/qualite.php flux <rapport-psalm.json>
 *       Aucun chemin d'une donnée venue de l'extérieur jusqu'à une requête
 *       SQL, une page, un fichier, une commande, une redirection ou un
 *       e-mail sans contrôle (analyse des flux de données de Psalm, voir
 *       psalm.xml) : échoue au premier chemin trouvé, ou sans rapport.
 *
 * Code de sortie : 0 si tout tient, 1 sinon.
 */

define('RACINE', (string) realpath(__DIR__.'/../..'));

/**
 * @return array{analyse_statique: array{niveau: int}, couverture: array<string, float>, mutation: array<string, float>}
 */
function seuils(string $fichier = __DIR__.'/seuils.json'): array
{
    return json_decode((string) file_get_contents($fichier), true, flags: JSON_THROW_ON_ERROR);
}

function partieDe(string $fichier): string
{
    $relatif = str_starts_with($fichier, 'app/') ? $fichier : ltrim(substr($fichier, (int) strpos($fichier, '/app/')), '/');

    foreach (require __DIR__.'/parties.php' as $partie => $chemins) {
        foreach ($chemins as $chemin) {
            if (str_starts_with($relatif, $chemin)) {
                return $partie;
            }
        }
    }

    return 'ecrans_et_socle';
}

/**
 * Les lignes exécutables de chaque fichier de app/ et le nombre de tests
 * qui les exécutent, lues dans un rapport Clover.
 *
 * @return array<string, array<int, int>> par chemin relatif (« app/... »)
 */
function lignesDuClover(string $clover): array
{
    $xml = simplexml_load_file($clover) ?: throw new RuntimeException("Rapport Clover illisible : {$clover}");
    $fichiers = [];

    foreach ($xml->xpath('//file') as $fichier) {
        $nom = (string) $fichier['name'];
        $position = strpos($nom, '/app/');

        if ($position === false) {
            continue;
        }

        $relatif = substr($nom, $position + 1);
        $fichiers[$relatif] ??= [];

        foreach ($fichier->line as $ligne) {
            if ((string) $ligne['type'] === 'stmt') {
                $fichiers[$relatif][(int) $ligne['num']] = (int) $ligne['count'];
            }
        }
    }

    return $fichiers;
}

function pourcentage(int $partie, int $tout): float
{
    return $tout === 0 ? 100.0 : floor($partie * 1000 / $tout) / 10;
}

function couverture(string $clover, ?string $rapport): int
{
    $parParties = [];

    foreach (lignesDuClover($clover) as $fichier => $lignes) {
        $partie = partieDe($fichier);
        $parParties[$partie]['lignes'] = ($parParties[$partie]['lignes'] ?? 0) + count($lignes);
        $parParties[$partie]['couvertes'] = ($parParties[$partie]['couvertes'] ?? 0) + count(array_filter($lignes, fn (int $n): bool => $n > 0));
        $parParties[$partie]['fichiers'][$fichier] = [count($lignes), count(array_filter($lignes, fn (int $n): bool => $n > 0))];
    }

    ksort($parParties);
    $seuils = seuils()['couverture'];
    $echec = false;
    $texte = "# Couverture des lignes par partie\n\n| Partie | Lignes | Couvertes | Couverture | Seuil |\n|---|---:|---:|---:|---:|\n";

    foreach ($parParties as $partie => $mesure) {
        $taux = pourcentage($mesure['couvertes'], $mesure['lignes']);
        $seuil = $seuils[$partie] ?? null;
        $echec = $echec || $seuil === null || $taux < $seuil;
        $texte .= sprintf("| %s | %d | %d | %.1f %% | %s |\n", $partie, $mesure['lignes'], $mesure['couvertes'], $taux, $seuil === null ? 'aucun' : sprintf('%.1f %%', $seuil));
    }

    foreach ($parParties as $partie => $mesure) {
        $texte .= "\n## {$partie}\n\n| Fichier | Lignes | Couverture |\n|---|---:|---:|\n";

        foreach ($mesure['fichiers'] as $fichier => [$lignes, $couvertes]) {
            $texte .= sprintf("| %s | %d | %.1f %% |\n", $fichier, $lignes, pourcentage($couvertes, $lignes));
        }
    }

    if ($rapport !== null) {
        file_put_contents($rapport, $texte);
    }

    echo strstr($texte, "\n## ", true) ?: $texte, "\n";
    echo $echec ? "Échec : une partie est sous son seuil, ou sans seuil, dans tests/Qualite/seuils.json.\n" : "Chaque partie tient son seuil.\n";

    return $echec ? 1 : 0;
}

/**
 * Les lignes de app/ ajoutées ou modifiées depuis ce commit.
 *
 * @return array<string, list<int>>
 */
function lignesChangees(string $base): array
{
    $diff = shell_exec('git -C '.escapeshellarg(RACINE).' diff --unified=0 --no-color '.escapeshellarg($base).' -- app/');
    $lignes = [];
    $fichier = null;

    foreach (explode("\n", (string) $diff) as $ligne) {
        if (str_starts_with($ligne, '+++ ')) {
            $fichier = $ligne === '+++ /dev/null' ? null : substr($ligne, 6);
        } elseif ($fichier !== null && preg_match('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $ligne, $morceaux) === 1) {
            $debut = (int) $morceaux[1];
            $nombre = isset($morceaux[2]) ? (int) $morceaux[2] : 1;

            for ($numero = $debut; $numero < $debut + $nombre; $numero++) {
                $lignes[$fichier][] = $numero;
            }
        }
    }

    return $lignes;
}

function nouveauCode(string $clover, string $base): int
{
    $executees = lignesDuClover($clover);
    $nonCouvertes = [];

    foreach (lignesChangees($base) as $fichier => $numeros) {
        foreach ($numeros as $numero) {
            if (($executees[$fichier][$numero] ?? null) === 0) {
                $nonCouvertes[] = "{$fichier}:{$numero}";
            }
        }
    }

    if ($nonCouvertes === []) {
        echo "Chaque ligne exécutable ajoutée ou modifiée depuis {$base} est couverte.\n";

        return 0;
    }

    echo "Lignes ajoutées ou modifiées qu'aucun test n'exécute :\n  ".implode("\n  ", $nonCouvertes)."\n";

    return 1;
}

/**
 * Les mutants non conclus, que le journal JSON d'Infection ne fait que
 * compter : lus dans la section « Skipped mutants » de son journal texte
 * (infection.log, à côté de infection.json, voir infection.json5), quand il
 * existe.
 *
 * @return list<array{string, int, string}> fichier, ligne, mutateur
 */
function mutantsNonConclus(string $journalTexte): array
{
    if (! is_file($journalTexte)) {
        return [];
    }

    $morceaux = [];

    // Un journal par passage d'Infection ; des passages mis bout à bout
    // (une partie lancée en plusieurs morceaux) ont chacun leur section.
    foreach (array_slice(preg_split('/^Skipped mutants:$/m', (string) file_get_contents($journalTexte)) ?: [], 1) as $section) {
        preg_match_all('/^\d+\) (\S+):(\d+)\s+\[M\] (\S+)/m', preg_split('/^\S[^\n]* mutants:$/m', $section)[0] ?? '', $trouves, PREG_SET_ORDER);
        $morceaux = [...$morceaux, ...$trouves];
    }

    return array_map(fn (array $morceau): array => [$morceau[1], (int) $morceau[2], $morceau[3]], $morceaux);
}

function mutation(string $journal, ?string $rapport): int
{
    $donnees = json_decode((string) file_get_contents($journal), true, flags: JSON_THROW_ON_ERROR);
    $sansTest = array_filter($donnees['killed'] ?? [], fn (array $mutant): bool => preg_match('/Test file ".*" not found/', (string) ($mutant['processOutput'] ?? '')) === 1);

    if ($sansTest !== []) {
        echo count($sansTest)." mutants comptés comme tués n'ont lancé aucun test : Infection n'a pas trouvé leurs fichiers dans le rapport JUnit. Ce rapport doit venir de paratest (PHPUnit), pas de php artisan test (Pest) : voir README.\n";

        return 1;
    }

    $testsPasses = array_filter($donnees['killed'] ?? [], fn (array $mutant): bool => str_contains((string) ($mutant['processOutput'] ?? ''), '"result":"passed"'));

    if ($testsPasses !== []) {
        echo count($testsPasses)." mutants comptés comme tués ont pourtant passé tous leurs tests : PHPUnit a écrit son résumé pour un agent de code (variables AI_AGENT, CLAUDECODE ou CLAUDE_CODE), qu'Infection ne lit pas. Relancez Infection sans elles (env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE) : voir README.\n";

        return 1;
    }

    $parParties = [];
    $etats = ['killed' => 'tues', 'escaped' => 'survivants', 'timeouted' => 'delais', 'errored' => 'tues', 'syntaxErrors' => 'tues', 'uncovered' => 'non_couverts'];
    $mutants = [];

    foreach ($etats as $cle => $etat) {
        foreach ($donnees[$cle] ?? [] as $mutant) {
            $mutants[] = [$etat, $mutant['mutator']['originalFilePath'], $mutant['mutator']['originalStartLine'], $mutant['mutator']['mutatorName']];
        }
    }

    foreach (mutantsNonConclus((string) preg_replace('/\.json$/', '.log', $journal)) as $mutant) {
        $mutants[] = ['non_conclus', ...$mutant];
    }

    foreach ($mutants as [$etat, $fichier, $ligne, $mutateur]) {
        $partie = partieDe($fichier);
        $parParties[$partie][$etat] = ($parParties[$partie][$etat] ?? 0) + 1;

        if (! in_array($etat, ['tues', 'delais'], true)) {
            $parParties[$partie][$etat === 'non_conclus' ? 'non_conclus_liste' : 'liste'][] = sprintf('%s:%d %s', substr($fichier, (int) strpos($fichier, 'app/')), $ligne, $mutateur);
        }
    }

    ksort($parParties);
    $seuils = seuils()['mutation'];
    $echec = false;
    $texte = "# Score de mutation (MSI) par partie\n\n"
        ."Un mutant dont les tests dépassent le délai d'Infection compte, comme Infection le compte, parmi ceux qu'un test arrête ; la colonne « dont délai dépassé » les montre à part. "
        ."Un mutant « non conclu » est celui dont les tests, tous plus longs que ce délai, n'ont pas fini : Infection l'écarte du score, comme ici, et la liste le montre pour qu'un test plus court le tranche.\n\n"
        ."| Partie | Arrêtés | dont délai dépassé | Survivants | Non couverts | Non conclus | MSI | Seuil |\n|---|---:|---:|---:|---:|---:|---:|---:|\n";

    foreach ($parParties as $partie => $mesure) {
        $tues = ($mesure['tues'] ?? 0) + ($mesure['delais'] ?? 0);
        $total = $tues + ($mesure['survivants'] ?? 0) + ($mesure['non_couverts'] ?? 0);
        $msi = pourcentage($tues, $total);
        $seuil = $seuils[$partie] ?? null;
        $echec = $echec || ($seuil !== null && $msi < $seuil);
        $texte .= sprintf("| %s | %d | %d | %d | %d | %d | %.1f %% | %s |\n", $partie, $tues, $mesure['delais'] ?? 0, $mesure['survivants'] ?? 0, $mesure['non_couverts'] ?? 0, $mesure['non_conclus'] ?? 0, $msi, $seuil === null ? '—' : sprintf('%.1f %%', $seuil));
    }

    foreach ($parParties as $partie => $mesure) {
        if (($mesure['liste'] ?? []) !== []) {
            $texte .= "\n## {$partie} : mutants qu'aucun test n'arrête\n\n- ".implode("\n- ", $mesure['liste'])."\n";
        }

        if (($mesure['non_conclus_liste'] ?? []) !== []) {
            $texte .= "\n## {$partie} : mutants non conclus\n\n- ".implode("\n- ", $mesure['non_conclus_liste'])."\n";
        }
    }

    if ($rapport !== null) {
        file_put_contents($rapport, $texte);
    }

    echo $texte, "\n", $echec ? "Échec : une partie sensible est sous son seuil dans tests/Qualite/seuils.json.\n" : "Chaque partie testée tient son seuil.\n";

    return $echec ? 1 : 0;
}

function seuilsQuiMontent(string $ancien): int
{
    $nouveaux = seuils();
    $anciens = seuils($ancien);
    $baisses = [];

    if ($nouveaux['analyse_statique']['niveau'] < $anciens['analyse_statique']['niveau']) {
        $baisses[] = "niveau de l'analyse statique : {$anciens['analyse_statique']['niveau']} → {$nouveaux['analyse_statique']['niveau']}";
    }

    foreach (['couverture', 'mutation'] as $outil) {
        foreach ($anciens[$outil] as $partie => $seuil) {
            if (($nouveaux[$outil][$partie] ?? -1) < $seuil) {
                $baisses[] = "{$outil} de {$partie} : {$seuil} → ".($nouveaux[$outil][$partie] ?? 'retiré');
            }
        }
    }

    preg_match('/^\s*level:\s*(\d+)\s*$/m', (string) file_get_contents(RACINE.'/phpstan.neon'), $niveau);

    if ((int) ($niveau[1] ?? -1) !== $nouveaux['analyse_statique']['niveau']) {
        $baisses[] = 'phpstan.neon analyse au niveau '.($niveau[1] ?? '?').", seuils.json enregistre le niveau {$nouveaux['analyse_statique']['niveau']}";
    }

    if ($baisses === []) {
        echo "Aucun seuil ne baisse.\n";

        return 0;
    }

    echo "Un seuil ne peut que monter :\n  ".implode("\n  ", $baisses)."\n";

    return 1;
}

/**
 * Les parties sensibles, dont Infection teste les mutants (décision de
 * l'éditeur du 01/11/2026, point 2) : les calculs des ventes et des
 * achats, les droits et l'isolation. Ceux de la caisse sécurisée, du
 * registre et des clôtures se testeront à l'étape 3 de la feuille de
 * route, où ce code sera terminé (décision de l'éditeur du 09/11/2026,
 * « caisse à l'étape 3 ») ; sa couverture garde son seuil.
 */
const PARTIES_SENSIBLES = ['calculs', 'droits_et_isolation'];

function sensibles(): int
{
    $parties = require __DIR__.'/parties.php';
    $chemins = [];

    foreach (PARTIES_SENSIBLES as $partie) {
        array_push($chemins, ...$parties[$partie]);
    }

    echo implode("\n", $chemins), "\n";

    return 0;
}

/**
 * Les exclusions, relatives à app/, qui ne laissent à Infection que les
 * fichiers des parties sensibles : un dossier sans aucun fichier sensible
 * s'exclut entier, un fichier non sensible d'un dossier qui en a s'exclut
 * seul ; chacune est une expression ancrée sur le chemin entier.
 *
 * @return list<string>
 */
function exclusionsHorsDesPartiesSensibles(string $dossier = ''): array
{
    $exclusions = [];

    foreach (scandir(RACINE.'/app/'.$dossier) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $relatif = $dossier.$entree;

        if (is_dir(RACINE.'/app/'.$relatif)) {
            $sensibles = array_filter(
                array_map(fn (string $fichier): string => substr($fichier, strlen(RACINE) + 1), glob_recursif(RACINE.'/app/'.$relatif)),
                fn (string $fichier): bool => in_array(partieDe($fichier), PARTIES_SENSIBLES, true),
            );
            $exclusions = $sensibles === []
                ? [...$exclusions, '#^'.preg_quote($relatif.'/', '#').'#']
                : [...$exclusions, ...exclusionsHorsDesPartiesSensibles($relatif.'/')];
        } elseif (! in_array(partieDe('app/'.$relatif), PARTIES_SENSIBLES, true)) {
            $exclusions[] = '#^'.preg_quote($relatif, '#').'$#';
        }
    }

    return $exclusions;
}

/**
 * @return list<string> les fichiers PHP sous ce dossier, chemins absolus
 */
function glob_recursif(string $dossier): array
{
    $fichiers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)) as $fichier) {
        if ($fichier->isFile() && str_ends_with($fichier->getFilename(), '.php')) {
            $fichiers[] = $fichier->getPathname();
        }
    }

    return $fichiers;
}

function configurationSensibles(string $destination): int
{
    $configuration = json_decode((string) preg_replace('#^\s*//.*$#m', '', (string) file_get_contents(RACINE.'/infection.json5')), true, flags: JSON_THROW_ON_ERROR);
    $configuration['$schema'] = RACINE.'/vendor/infection/infection/resources/schema.json';
    $configuration['source'] = ['directories' => [RACINE.'/app'], 'excludes' => exclusionsHorsDesPartiesSensibles()];
    $configuration['tmpDir'] = RACINE.'/'.$configuration['tmpDir'];
    $configuration['logs'] = array_map(fn (string $journal): string => RACINE.'/'.$journal, $configuration['logs']);
    $configuration['phpUnit'] = ['configDir' => RACINE];
    file_put_contents($destination, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

    return 0;
}

function sensiblesChanges(string $base): int
{
    $fichiers = explode("\n", trim((string) shell_exec('git -C '.escapeshellarg(RACINE).' diff --name-only --diff-filter=AM --no-color '.escapeshellarg($base).' -- app/')));
    $changes = array_filter($fichiers, fn (string $fichier): bool => $fichier !== '' && in_array(partieDe($fichier), PARTIES_SENSIBLES, true));

    echo $changes === [] ? '' : implode("\n", $changes)."\n";

    return 0;
}

/**
 * Psalm ne sert qu'à l'analyse des flux de données (décision de l'éditeur
 * du 07/11/2026, D54) : le contrôle des types est celui de Larastan
 * (phpstan.neon). Seuls ses chemins de sécurité comptent ; un chemin sûr
 * se justifie par écrit dans le code (@psalm-taint-escape, avec la raison).
 */
function flux(string $rapport): int
{
    if (! is_file($rapport)) {
        echo "Aucun rapport de Psalm : {$rapport}. L'analyse n'a pas tourné jusqu'au bout.\n";

        return 1;
    }

    $chemins = array_values(array_filter(
        json_decode((string) file_get_contents($rapport), true, flags: JSON_THROW_ON_ERROR),
        static fn (array $probleme): bool => ($probleme['is_security'] ?? false) === true || str_starts_with((string) $probleme['type'], 'Tainted'),
    ));

    if ($chemins === []) {
        echo "Aucun chemin d'une donnée venue de l'extérieur sans contrôle.\n";

        return 0;
    }

    echo "Chemins d'une donnée venue de l'extérieur sans contrôle :\n";

    foreach ($chemins as $chemin) {
        echo sprintf("  %s:%d %s : %s\n", $chemin['file_name'], $chemin['line_from'], $chemin['type'], $chemin['message']);
    }

    return 1;
}

exit(match ($argv[1] ?? null) {
    'couverture' => couverture($argv[2], $argv[3] ?? null),
    'nouveau-code' => nouveauCode($argv[2], $argv[3]),
    'mutation' => mutation($argv[2], $argv[3] ?? null),
    'seuils' => seuilsQuiMontent($argv[2]),
    'sensibles' => sensibles(),
    'sensibles-changes' => sensiblesChanges($argv[2]),
    'configuration-sensibles' => configurationSensibles($argv[2]),
    'flux' => flux($argv[2]),
    default => (function (): int {
        fwrite(STDERR, "Usage : php tests/Qualite/qualite.php couverture|nouveau-code|mutation|seuils|sensibles|sensibles-changes|configuration-sensibles|flux ...\n");

        return 2;
    })(),
});
