<?php

/*
 * Le résumé d'une suite de tests, lancé par la CI après la suite, depuis la
 * racine du dépôt (constat C100 des audits du 07/11/2026) :
 *
 *   php tests/Qualite/resume-des-tests.php <junit.xml>
 *
 * Lit le rapport JUnit de la suite (php artisan test --parallel
 * --log-junit), écrit dans le résumé de la tâche de GitHub Actions (le
 * fichier que nomme GITHUB_STEP_SUMMARY ; sinon sur la sortie) les nombres
 * de tests réussis, échoués et sautés, et chaque raison de saut avec son
 * nombre ; échoue si un test est sauté en CI (variable CI), où aucune
 * raison permise de sauter un test ne vaut (voir TestsSautesTest) : un
 * test sauté y cacherait une partie de la suite. Échoue aussi si le
 * rapport est absent ou illisible.
 */

$rapport = $argv[1] ?? '';

if ($rapport === '' || ! is_file($rapport)) {
    fwrite(STDERR, "Rapport JUnit introuvable : « {$rapport} ».\n");
    exit(1);
}

$document = new DOMDocument;

if (! @$document->load($rapport)) {
    fwrite(STDERR, "Rapport JUnit illisible : « {$rapport} ».\n");
    exit(1);
}

$tests = 0;
$echoues = 0;
$sautes = 0;
/** @var array<string, int> $raisons */
$raisons = [];

foreach ($document->getElementsByTagName('testcase') as $cas) {
    $tests++;

    if ($cas->getElementsByTagName('failure')->length > 0 || $cas->getElementsByTagName('error')->length > 0) {
        $echoues++;
    }

    foreach ($cas->getElementsByTagName('skipped') as $saut) {
        $sautes++;
        $raison = trim($saut->getAttribute('message')) ?: trim($saut->textContent) ?: 'sans raison';
        $raisons[$raison] = ($raisons[$raison] ?? 0) + 1;
    }
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
        $lignes[] = '| '.str_replace(['|', "\n"], ['\|', ' '], $raison)." | {$nombre} |";
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
