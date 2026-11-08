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
 *   php tests/Qualite/qualite.php seuils <ancien seuils.json>
 *       Aucun seuil ne baisse par rapport à l'ancien fichier, et le niveau
 *       de l'analyse statique de phpstan.neon est celui de seuils.json.
 *   php tests/Qualite/qualite.php sensibles
 *       Les chemins des parties sensibles, un par ligne, à donner à
 *       Infection comme chemins à muter.
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

const RACINE = __DIR__.'/../..';

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

function mutation(string $journal, ?string $rapport): int
{
    $donnees = json_decode((string) file_get_contents($journal), true, flags: JSON_THROW_ON_ERROR);
    $parParties = [];
    $etats = ['killed' => 'tues', 'escaped' => 'survivants', 'timeouted' => 'tues', 'errored' => 'tues', 'syntaxErrors' => 'tues', 'uncovered' => 'non_couverts'];

    foreach ($etats as $cle => $etat) {
        foreach ($donnees[$cle] ?? [] as $mutant) {
            $fichier = $mutant['mutator']['originalFilePath'];
            $partie = partieDe($fichier);
            $parParties[$partie][$etat] = ($parParties[$partie][$etat] ?? 0) + 1;

            if ($etat !== 'tues') {
                $parParties[$partie]['liste'][] = sprintf('%s:%d %s', substr($fichier, (int) strpos($fichier, 'app/')), $mutant['mutator']['originalStartLine'], $mutant['mutator']['mutatorName']);
            }
        }
    }

    ksort($parParties);
    $seuils = seuils()['mutation'];
    $echec = false;
    $texte = "# Score de mutation (MSI) par partie\n\n| Partie | Tués | Survivants | Non couverts | MSI | Seuil |\n|---|---:|---:|---:|---:|---:|\n";

    foreach ($parParties as $partie => $mesure) {
        $tues = $mesure['tues'] ?? 0;
        $total = $tues + ($mesure['survivants'] ?? 0) + ($mesure['non_couverts'] ?? 0);
        $msi = pourcentage($tues, $total);
        $seuil = $seuils[$partie] ?? null;
        $echec = $echec || ($seuil !== null && $msi < $seuil);
        $texte .= sprintf("| %s | %d | %d | %d | %.1f %% | %s |\n", $partie, $tues, $mesure['survivants'] ?? 0, $mesure['non_couverts'] ?? 0, $msi, $seuil === null ? '—' : sprintf('%.1f %%', $seuil));
    }

    foreach ($parParties as $partie => $mesure) {
        if (($mesure['liste'] ?? []) !== []) {
            $texte .= "\n## {$partie} : mutants qu'aucun test n'arrête\n\n- ".implode("\n- ", $mesure['liste'])."\n";
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
 * achats, la caisse sécurisée (registre et clôtures), les droits et
 * l'isolation.
 */
const PARTIES_SENSIBLES = ['calculs', 'caisse_securisee', 'droits_et_isolation'];

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
    'flux' => flux($argv[2]),
    default => (function (): int {
        fwrite(STDERR, "Usage : php tests/Qualite/qualite.php couverture|nouveau-code|mutation|seuils|sensibles|sensibles-changes|flux ...\n");

        return 2;
    })(),
});
