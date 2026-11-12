<?php

/*
 * Le résumé d'une suite de tests, lancé par la CI après la suite, depuis la
 * racine du dépôt (constat C100 des audits du 07/11/2026) :
 *
 *   php tests/Qualite/resume-des-tests.php <junit.xml> <teamcity.txt>
 *
 * Lit les deux rapports de la suite (php artisan test --parallel
 * --log-junit … --log-teamcity …) : le rapport JUnit donne les nombres de
 * tests, d'échecs et de sauts, mais PHPUnit et ParaTest y écrivent un saut
 * sans sa raison (<skipped/>) ; le rapport TeamCity donne la raison de
 * chaque saut (testIgnored, son message). Écrit dans le résumé de la tâche
 * de GitHub Actions (le fichier que nomme GITHUB_STEP_SUMMARY ; sinon sur
 * la sortie) les nombres de tests réussis, échoués et sautés, et chaque
 * raison de saut avec son nombre ; échoue si un test est sauté en CI
 * (variable CI), où aucune raison permise de sauter un test ne vaut (voir
 * TestsSautesTest) : un test sauté y cacherait une partie de la suite.
 * Échoue aussi si un rapport est absent ou illisible.
 */

$rapportJunit = $argv[1] ?? '';
$rapportTeamcity = $argv[2] ?? '';

foreach (['JUnit' => $rapportJunit, 'TeamCity' => $rapportTeamcity] as $format => $rapport) {
    if ($rapport === '' || ! is_file($rapport)) {
        fwrite(STDERR, "Rapport {$format} introuvable : « {$rapport} ».\n");
        exit(1);
    }
}

$document = new DOMDocument;
$teamcity = file_get_contents($rapportTeamcity);

if (! @$document->load($rapportJunit) || $teamcity === false) {
    fwrite(STDERR, "Rapport illisible : « {$rapportJunit} » ou « {$rapportTeamcity} ».\n");
    exit(1);
}

$tests = 0;
$echoues = 0;
$sautes = 0;

foreach ($document->getElementsByTagName('testcase') as $cas) {
    $tests++;

    if ($cas->getElementsByTagName('failure')->length > 0 || $cas->getElementsByTagName('error')->length > 0) {
        $echoues++;
    } elseif ($cas->getElementsByTagName('skipped')->length > 0) {
        $sautes++;
    }
}

/**
 * Une valeur d'un message TeamCity, ses caractères échappés rendus : |' |n
 * |r || |[ |] et |0x suivi du code hexadécimal d'un caractère.
 */
$lireLaValeur = static fn (string $valeur): string => (string) preg_replace_callback(
    "/\\|(0x[0-9a-fA-F]{4}|['nr|\\[\\]])/",
    static fn (array $echappe): string => match ($echappe[1]) {
        "'" => "'",
        'n' => "\n",
        'r' => "\r",
        '|' => '|',
        '[' => '[',
        ']' => ']',
        default => mb_chr((int) hexdec(substr($echappe[1], 2)), 'UTF-8'),
    },
    $valeur,
);

/** @var array<string, int> $raisons */
$raisons = [];
preg_match_all('/^##teamcity\\[testIgnored (.*)\\]\\s*$/m', $teamcity, $ignores);

foreach ($ignores[1] as $attributs) {
    $raison = preg_match("/(?:^| )message='((?:[^'|]|\\|.)*)'/", $attributs, $message) === 1 ? trim($lireLaValeur($message[1])) : '';
    $raison = $raison === '' ? 'sans raison' : $raison;
    $raisons[$raison] = ($raisons[$raison] ?? 0) + 1;
}

$sansRaison = $sautes - array_sum($raisons);

if ($sansRaison > 0) {
    $raisons['sans raison'] = ($raisons['sans raison'] ?? 0) + $sansRaison;
}

$reussis = $tests - $echoues - $sautes;
$lignes = [
    '## Tests',
    '',
    '| Réussis | Échoués | Sautés |',
    '|---:|---:|---:|',
    "| {$reussis} | {$echoues} | {$sautes} |",
];

if ($raisons !== []) {
    ksort($raisons);
    array_push($lignes, '', '| Raison du saut | Tests |', '|---|---:|');

    foreach ($raisons as $raison => $nombre) {
        $lignes[] = '| '.str_replace(['|', "\r", "\n"], ['\|', ' ', ' '], $raison)." | {$nombre} |";
    }
}

$resume = implode("\n", $lignes)."\n";
$fichierDuResume = getenv('GITHUB_STEP_SUMMARY');

if (is_string($fichierDuResume) && $fichierDuResume !== '') {
    file_put_contents($fichierDuResume, $resume, FILE_APPEND);
} else {
    echo $resume;
}

if ($sautes > 0 && getenv('CI') !== false && getenv('CI') !== '') {
    fwrite(STDERR, "{$sautes} test(s) sauté(s) en CI : aucun test ne s'y saute.\n");
    exit(1);
}

exit(0);
