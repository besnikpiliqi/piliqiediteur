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
 *   php tests/Qualite/qualite.php seuils [<ancien seuils.json>]
 *       Aucun seuil ne baisse par rapport à l'ancien fichier, ou, sans
 *       fichier, au seuils.json de la branche que suit la branche courante
 *       (git @{upstream}), que la CI prend pour base à l'envoi suivant, ou,
 *       sans branche suivie qui en ait un, à seuils.json lui-même, comme
 *       la CI pour une nouvelle branche (voir anciensSeuils()) : ni la
 *       couverture, ni le score de mutation d'une partie, ni l'analyse
 *       statique (Larastan), que seuils.json enregistre telle que PHPStan
 *       la règle (niveau, chemins, exclusions, stubs, options, règles
 *       désactivées) et que phpstan.neon doit suivre exactement, sans rien
 *       relâcher ni cacher, ni inclure d'autre fichier que l'extension de
 *       Larastan, et sans qu'aucun commentaire ne fasse taire PHPStan ni ne
 *       donne un type en ligne (voir refusDeLAnalyseStatique()). Une clé
 *       que l'ancien fichier n'a pas compte comme rien d'enregistré. Le
 *       PHPStan lancé est celui du dépôt (vendor/bin/phpstan), ou celui
 *       que nomme la variable QUALITE_PHPSTAN par son chemin absolu (les
 *       tests de cet outil, dans un dépôt temporaire).
 *   php tests/Qualite/qualite.php sensibles
 *       Les chemins des parties sensibles, un par ligne, à donner à
 *       Infection comme chemins à muter.
 *   php tests/Qualite/qualite.php configuration-sensibles <destination>
 *       Écrit la configuration d'Infection (infection.json5) limitée aux
 *       parties sensibles, pour leur passage complet : tout app/, moins
 *       chaque dossier et chaque fichier qui n'en est pas.
 *   php tests/Qualite/qualite.php sensibles-changes <commit de base>
 *       Les fichiers des parties sensibles ajoutés, modifiés ou renommés
 *       depuis ce commit, un par ligne, depuis la racine du dépôt (rien
 *       quand aucun ne l'est) : la CI les donne à Infection comme chemins,
 *       et il teste tous leurs mutants à chaque envoi. Un filtre de git
 *       (--git-diff-filter, --git-diff-lines) ne les remplace pas :
 *       Infection ne mute alors que les lignes changées de chaque fichier.
 *   php tests/Qualite/qualite.php flux <rapport-psalm.json>
 *       Aucun chemin d'une donnée venue de l'extérieur jusqu'à une requête
 *       SQL, une page, un fichier, une commande, une redirection ou un
 *       e-mail sans contrôle (analyse des flux de données de Psalm, voir
 *       psalm.xml) : échoue au premier chemin trouvé, ou sans rapport.
 *   php tests/Qualite/qualite.php flux-temoin <rapport-psalm.json>
 *       L'analyse du témoin (psalm-temoin.xml) voit exactement les chemins
 *       que ses lignes marquées « attendu : » annoncent, ni plus ni moins :
 *       elle suit bien les saisies des écrans Livewire et Filament (voir
 *       tests/Qualite/Psalm/SaisiesDesEcrans.php).
 *
 * Code de sortie : 0 si tout tient, 1 sinon, 2 pour une commande inconnue
 * ou incomplète, après son usage.
 */

define('RACINE', (string) realpath(__DIR__.'/../..'));

/**
 * Les seuils qu'enregistre ce fichier, chaque section relue par la
 * fonction qui s'en sert : une clé absente vaut « rien d'enregistré ».
 *
 * @return array<array-key, mixed>
 */
function seuils(string $fichier = __DIR__.'/seuils.json'): array
{
    $contenu = file_get_contents($fichier);

    if ($contenu === false) {
        throw new RuntimeException("Seuils illisibles : {$fichier}.");
    }

    return seuilsDuTexte($contenu, $fichier);
}

/**
 * Les seuils qu'enregistre ce texte d'un seuils.json, venu de cette
 * origine (un fichier, une branche).
 *
 * @return array<array-key, mixed>
 */
function seuilsDuTexte(string $contenu, string $origine): array
{
    $seuils = json_decode($contenu, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($seuils)) {
        throw new RuntimeException("{$origine} n'enregistre aucun seuil.");
    }

    return $seuils;
}

/**
 * Les anciens seuils auxquels « seuils » compare seuils.json, avec ce
 * qu'ils sont : ceux du fichier donné ; sans fichier, ceux du seuils.json
 * de la branche que suit la branche courante (git @{upstream}), que la CI
 * prend pour base à l'envoi suivant (le commit d'avant l'envoi) ; sans
 * branche suivie, ou sans seuils.json sur elle, seuils.json lui-même, comme
 * la CI pour une nouvelle branche : le réglage de l'analyse statique y est
 * alors contrôlé, sans rien pour baisser.
 *
 * @return array{0: array<array-key, mixed>, 1: string}
 */
function anciensSeuils(?string $fichier): array
{
    if ($fichier !== null) {
        return [seuils($fichier), $fichier];
    }

    $processus = proc_open(['git', '-C', RACINE, 'show', '@{upstream}:tests/Qualite/seuils.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);

    if ($processus !== false) {
        $sortie = stream_get_contents($tubes[1]);
        fclose($tubes[1]);
        fclose($tubes[2]);

        if (proc_close($processus) === 0 && $sortie !== false) {
            return [seuilsDuTexte($sortie, 'Le seuils.json de la branche suivie'), 'celui de la branche suivie (git @{upstream})'];
        }
    }

    return [seuils(), "seuils.json lui-même (aucune branche suivie n'en a)"];
}

/**
 * Une section de seuils : un tableau, vide quand la clé est absente.
 *
 * @param  array<array-key, mixed>  $seuils
 * @return array<array-key, mixed>
 */
function section(array $seuils, string $cle): array
{
    $section = $seuils[$cle] ?? [];

    if (! is_array($section)) {
        throw new RuntimeException("La section « {$cle} » de seuils.json n'est pas un tableau.");
    }

    return $section;
}

/**
 * Le contenu de ce fichier, que l'outil lit (un rapport, un journal, une
 * configuration) : un fichier illisible est refusé.
 */
function contenuDe(string $fichier): string
{
    $contenu = file_get_contents($fichier);

    if ($contenu === false) {
        throw new RuntimeException("Fichier illisible : {$fichier}.");
    }

    return $contenu;
}

/**
 * Une valeur lue dans un fichier que l'outil ne fait que lire (un rapport,
 * un journal, une configuration), là où il attend un tableau : une valeur
 * d'un autre type est refusée, au lieu d'être utilisée pour ce qu'elle
 * n'est pas.
 *
 * @param  string  $quoi  ce qu'est la valeur (« le journal d'Infection »)
 * @return array<array-key, mixed>
 */
function tableauLu(mixed $valeur, string $quoi): array
{
    if (! is_array($valeur)) {
        throw new RuntimeException(ucfirst($quoi).' n\'est pas un tableau : '.get_debug_type($valeur).'.');
    }

    return $valeur;
}

/**
 * La même lecture, là où l'outil attend un texte.
 *
 * @param  string  $quoi  ce qu'est la valeur
 */
function texteLu(mixed $valeur, string $quoi): string
{
    if (! is_string($valeur)) {
        throw new RuntimeException(ucfirst($quoi).' n\'est pas un texte : '.get_debug_type($valeur).'.');
    }

    return $valeur;
}

/**
 * La même lecture, là où l'outil attend un entier.
 *
 * @param  string  $quoi  ce qu'est la valeur
 */
function entierLu(mixed $valeur, string $quoi): int
{
    if (! is_int($valeur)) {
        throw new RuntimeException(ucfirst($quoi).' n\'est pas un entier : '.get_debug_type($valeur).'.');
    }

    return $valeur;
}

/**
 * Les grandes parties du logiciel (parties.php), chacune avec ses chemins.
 *
 * @return array<string, list<string>>
 */
function parties(): array
{
    $parties = [];

    foreach (tableauLu(require __DIR__.'/parties.php', 'parties.php') as $partie => $chemins) {
        if (! is_string($partie)) {
            throw new RuntimeException("parties.php : chaque partie se nomme par un texte, pas par {$partie}.");
        }

        $parties[$partie] = listeDeChaines($chemins, "parties.php, partie {$partie},");
    }

    return $parties;
}

/**
 * Les seuils d'un outil par partie (couverture, mutation), chacun un
 * nombre de points de pourcentage, entier ou décimal, tel que seuils.json
 * l'écrit.
 *
 * @param  array<array-key, mixed>  $seuils
 * @return array<string, int|float>
 */
function seuilsParPartie(array $seuils, string $outil): array
{
    $parPartie = [];

    foreach (section($seuils, $outil) as $partie => $seuil) {
        if (! is_string($partie) || ! (is_int($seuil) || is_float($seuil))) {
            throw new RuntimeException("seuils.json : chaque seuil de « {$outil} » est un nombre, par partie.");
        }

        $parPartie[$partie] = $seuil;
    }

    return $parPartie;
}

/**
 * Le chemin de ce fichier relatif à la racine du dépôt (« app/... ») : un
 * chemin absolu (rapport Clover, journal d'Infection) perd la racine du
 * dépôt, quel que soit le dossier où celui-ci est monté (« /app » d'un
 * conteneur compris) ; un chemin déjà relatif (diff de git) reste tel
 * quel. Un fichier hors du dépôt est refusé, jamais rangé dans une partie
 * au hasard : le rapport vient alors d'un autre dépôt.
 */
function relatifAuDepot(string $fichier): string
{
    $nom = nomDansLeDepot($fichier);

    if (! str_starts_with($nom, '/') && preg_match('#^[A-Za-z]:/#', $nom) !== 1) {
        return $nom;
    }

    throw new RuntimeException('Fichier hors du dépôt '.RACINE." : {$nom}. Le rapport doit être lu dans le dépôt qui l'a produit.");
}

/**
 * Ce chemin nommé depuis la racine du dépôt quand il y est, en entier
 * sinon : un chemin, un stub ou un fichier de configuration de l'analyse
 * statique hors du dépôt se nomme tel quel dans son refus.
 */
function nomDansLeDepot(string $chemin): string
{
    $chemin = str_replace('\\', '/', $chemin);
    $racine = str_replace('\\', '/', RACINE).'/';

    return str_starts_with($chemin, $racine) ? substr($chemin, strlen($racine)) : $chemin;
}

function partieDe(string $fichier): string
{
    $relatif = relatifAuDepot($fichier);

    foreach (parties() as $partie => $chemins) {
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
 * qui les exécutent, lues dans un rapport Clover produit dans ce dépôt
 * (voir relatifAuDepot()).
 *
 * @return array<string, array<int, int>> par chemin relatif (« app/... »)
 */
function lignesDuClover(string $clover): array
{
    $xml = simplexml_load_file($clover) ?: throw new RuntimeException("Rapport Clover illisible : {$clover}");
    $elements = $xml->xpath('//file');
    $fichiers = [];

    if (! is_array($elements)) {
        throw new RuntimeException("Rapport Clover illisible : {$clover}");
    }

    foreach ($elements as $fichier) {
        $relatif = relatifAuDepot((string) $fichier['name']);

        if (! str_starts_with($relatif, 'app/')) {
            continue;
        }

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
    $seuils = seuilsParPartie(seuils(), 'couverture');
    $echec = false;
    $texte = "# Couverture des lignes par partie\n\n| Partie | Lignes | Couvertes | Couverture | Seuil |\n|---|---:|---:|---:|---:|\n";

    foreach ($parParties as $partie => $mesure) {
        $taux = pourcentage($mesure['couvertes'], $mesure['lignes']);
        $seuil = $seuils[$partie] ?? null;
        $echec = $echec || $seuil === null || $taux < $seuil;
        $texte .= sprintf("| %s | %d | %d | %.1f %% | %s |\n", $partie, $mesure['lignes'], $mesure['couvertes'], $taux, $seuil === null ? 'aucun' : sprintf('%.1f %%', $seuil));
    }

    // Une partie qui a un seuil et dont le rapport ne mesure aucune ligne
    // (ses fichiers déplacés ou renommés, un rapport partiel) ne tient pas
    // son seuil : elle ne le perd jamais en silence.
    foreach (array_diff_key($seuils, $parParties) as $partie => $seuil) {
        $echec = true;
        $texte .= sprintf("| %s | 0 | 0 | non mesurée | %.1f %% |\n", $partie, $seuil);
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
    echo $echec ? "Échec : une partie est sous son seuil, sans seuil dans tests/Qualite/seuils.json, ou non mesurée.\n" : "Chaque partie tient son seuil.\n";

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

/**
 * Les mutants de cet état du journal JSON d'Infection (killed, escaped…),
 * chacun avec ce que le contrôle en lit : son fichier, sa ligne, son
 * mutateur et la sortie de ses tests, vide quand le journal n'en donne
 * pas. Un état absent n'a aucun mutant.
 *
 * @param  array<array-key, mixed>  $donnees  le journal JSON d'Infection
 * @return list<array{fichier: string, ligne: int, mutateur: string, sortie: string}>
 */
function mutantsDuJournal(array $donnees, string $etat): array
{
    $mutants = [];

    foreach (tableauLu($donnees[$etat] ?? [], "la liste « {$etat} » du journal d'Infection") as $mutant) {
        $mutant = tableauLu($mutant, "un mutant « {$etat} » du journal d'Infection");
        $mutateur = tableauLu($mutant['mutator'] ?? null, "le mutateur d'un mutant « {$etat} » du journal d'Infection");
        $mutants[] = [
            'fichier' => texteLu($mutateur['originalFilePath'] ?? null, "le fichier d'un mutant « {$etat} » du journal d'Infection"),
            'ligne' => entierLu($mutateur['originalStartLine'] ?? null, "la ligne d'un mutant « {$etat} » du journal d'Infection"),
            'mutateur' => texteLu($mutateur['mutatorName'] ?? null, "le nom du mutateur d'un mutant « {$etat} » du journal d'Infection"),
            'sortie' => texteLu($mutant['processOutput'] ?? '', "la sortie des tests d'un mutant « {$etat} » du journal d'Infection"),
        ];
    }

    return $mutants;
}

function mutation(string $journal, ?string $rapport): int
{
    $donnees = tableauLu(json_decode(contenuDe($journal), true, flags: JSON_THROW_ON_ERROR), "le journal d'Infection {$journal}");
    $sansTest = array_filter(mutantsDuJournal($donnees, 'killed'), fn (array $mutant): bool => preg_match('/Test file ".*" not found/', $mutant['sortie']) === 1);

    if ($sansTest !== []) {
        echo count($sansTest)." mutants comptés comme tués n'ont lancé aucun test : Infection n'a pas trouvé leurs fichiers dans le rapport JUnit. Ce rapport doit venir de paratest (PHPUnit), pas de php artisan test (Pest) : voir README.\n";

        return 1;
    }

    $testsPasses = array_filter(mutantsDuJournal($donnees, 'killed'), fn (array $mutant): bool => str_contains($mutant['sortie'], '"result":"passed"'));

    if ($testsPasses !== []) {
        echo count($testsPasses)." mutants comptés comme tués ont pourtant passé tous leurs tests : PHPUnit a écrit son résumé pour un agent de code (variables AI_AGENT, CLAUDECODE ou CLAUDE_CODE), qu'Infection ne lit pas. Relancez Infection sans elles (env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE) : voir README.\n";

        return 1;
    }

    $parParties = [];
    $etats = ['killed' => 'tues', 'escaped' => 'survivants', 'timeouted' => 'delais', 'errored' => 'tues', 'syntaxErrors' => 'tues', 'uncovered' => 'non_couverts'];
    $mutants = [];

    foreach ($etats as $cle => $etat) {
        foreach (mutantsDuJournal($donnees, $cle) as $mutant) {
            $mutants[] = [$etat, $mutant['fichier'], $mutant['ligne'], $mutant['mutateur']];
        }
    }

    foreach (mutantsNonConclus((string) preg_replace('/\.json$/', '.log', $journal)) as $mutant) {
        $mutants[] = ['non_conclus', ...$mutant];
    }

    foreach ($mutants as [$etat, $fichier, $ligne, $mutateur]) {
        $partie = partieDe($fichier);
        $parParties[$partie][$etat] = ($parParties[$partie][$etat] ?? 0) + 1;

        if (! in_array($etat, ['tues', 'delais'], true)) {
            $parParties[$partie][$etat === 'non_conclus' ? 'non_conclus_liste' : 'liste'][] = sprintf('%s:%d %s', relatifAuDepot($fichier), $ligne, $mutateur);
        }
    }

    ksort($parParties);
    $seuils = seuilsParPartie(seuils(), 'mutation');
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

function seuilsQuiMontent(?string $ancien): int
{
    $nouveaux = seuils();
    [$anciens, $origine] = anciensSeuils($ancien);
    echo "Ancien seuils.json : {$origine}.\n";
    $refus = [];

    foreach (['couverture', 'mutation'] as $outil) {
        $seuilsNouveaux = seuilsParPartie($nouveaux, $outil);

        foreach (seuilsParPartie($anciens, $outil) as $partie => $seuil) {
            if (($seuilsNouveaux[$partie] ?? -1) < $seuil) {
                $refus[] = "{$outil} de {$partie} : {$seuil} → ".($seuilsNouveaux[$partie] ?? 'retiré');
            }
        }
    }

    $refus = [...$refus, ...refusDeLAnalyseStatique(section($nouveaux, 'analyse_statique'), section($anciens, 'analyse_statique'))];

    if ($refus === []) {
        echo "Aucun seuil ne baisse, et phpstan.neon règle l'analyse statique comme seuils.json l'enregistre.\n";

        return 0;
    }

    echo "Un seuil ne peut que monter, et phpstan.neon suit exactement seuils.json :\n  ".implode("\n  ", $refus)."\n";

    return 1;
}

/**
 * Les seules exclusions de l'analyse statique (cahier de l'analyse
 * statique, § 4.1, point 7) : le cache de l'amorçage, l'extension et le
 * stub que Psalm charge seul, et les fichiers témoins de PHPStan, qui
 * violent exprès des règles.
 */
const EXCLUSIONS_PERMISES = ['bootstrap/cache', 'tests/Qualite/Psalm', 'tests/Qualite/Temoins'];

/**
 * Les seuls stubs de l'analyse statique : les macros que AppServiceProvider
 * ajoute à Filament (voir MacrosDeclareesTest).
 */
const STUBS_PERMIS = ['tests/Qualite/macros.stub'];

/**
 * Les seuls fichiers de configuration que phpstan.neon peut inclure, les
 * ensembles de règles (cahier de l'analyse statique, § 4.1, point 2),
 * chacun par son chemin dans le dossier vendor du PHPStan que lance le
 * contrôle (celui du dépôt) : l'extension de Larastan. Ce qu'ils incluent
 * eux-mêmes les suit. Tout autre fichier inclus est refusé : la référence
 * ne se bâtit qu'avec eux, si bien que ce qu'un autre fichier réglerait (un
 * contrôle relâché, un stub, une classe aux propriétés libres) reste un
 * écart de phpstan.neon, refusé comme tel.
 */
const INCLUSIONS_PERMISES = ['larastan/larastan/extension.neon'];

/**
 * Les options de contrôle que phpstan.neon peut activer, liste fermée
 * (cahier de l'analyse statique, § 4.1, points 4 et 5), chacune avec la
 * valeur qui contrôle le plus : true, false pour
 * rememberPossiblyImpureFunctionValues, ou l'une des valeurs permises de
 * reportUnsafeArrayStringKeyCasting ; parseModelCastsMethod fait lire à
 * Larastan les casts que déclare la méthode casts() des modèles. Une clé
 * pointée désigne un paramètre imbriqué.
 *
 * @var array<string, bool|list<string>>
 */
const OPTIONS_PERMISES = [
    'checkUninitializedProperties' => true,
    'checkBenevolentUnionTypes' => true,
    'checkTooWideReturnTypesInProtectedAndPublicMethods' => true,
    'reportPossiblyNonexistentGeneralArrayOffset' => true,
    'reportPossiblyNonexistentConstantArrayOffset' => true,
    'reportAnyTypeWideningInVarTag' => true,
    'reportAlwaysTrueInLastCondition' => true,
    'checkMissingCallableSignature' => true,
    'checkMissingOverrideMethodAttribute' => true,
    'checkTooWideThrowTypesInProtectedAndPublicMethods' => true,
    'checkTooWideParameterOutInProtectedAndPublicMethods' => true,
    'exceptions.check.throwTypeCovariance' => true,
    'exceptions.check.tooWideImplicitThrowType' => true,
    'reportUnsafeArrayStringKeyCasting' => ['detect', 'prevent'],
    'reportIgnoresWithoutComments' => true,
    'rememberPossiblyImpureFunctionValues' => false,
    'checkConfigTypes' => true,
    'checkUniqueJobUniqueFor' => true,
    'checkUniqueJobUniqueId' => true,
    'noBatchedUniqueJob' => true,
    'checkJobSerializesModels' => true,
    'checkBatchedJobIsBatchable' => true,
    'checkBatchableJobChecksCancellation' => true,
    'checkDispatchInTransactionAfterCommit' => true,
    'checkModelProperties' => true,
    'checkModelMethodVisibility' => true,
    'checkUnusedViews' => true,
    'checkMissingTranslations' => true,
    'checkAuthCallsWhenInRequestScope' => true,
    'noImplicitQueryBuilderCall' => true,
    'noUnnecessaryEnumerableToArrayCalls' => true,
    'parseModelCastsMethod' => true,
];

/**
 * La seule règle qu'un ensemble de règles active et que phpstan.neon peut
 * désactiver (cahier de l'analyse statique, § 4.1, point 3).
 */
const REGLES_DESACTIVABLES = ['strictRules.dynamicCallOnStaticMethod'];

/**
 * Les paramètres de PHPStan qui ne règlent pas ce que l'analyse contrôle
 * (l'environnement de la commande, son cache, ses messages, son
 * parallélisme), ceux qui se contrôlent à part (niveau, chemins,
 * exclusions, stubs, et les quatre gardes nommées dans
 * refusDeLAnalyseStatique()), et ceux qui nomment les fichiers de
 * configuration lus.
 */
const PARAMETRES_HORS_COMPARAISON = [
    'level', 'usedLevel', 'paths', 'analysedPathsFromConfig', 'excludePaths', 'stubFiles',
    'ignoreErrors', 'universalObjectCratesClasses', 'treatPhpDocTypesAsCertain', 'reportUnmatchedIgnoredErrors',
    'additionalConfigFiles', 'allConfigFiles', 'composerAutoloaderProjectPaths', 'currentWorkingDirectory', 'rootDir', 'env', 'sysGetTempDir',
    'tmpDir', 'resultCachePath', 'resultCacheSkipIfOlderThanDays', 'resultCacheChecksProjectExtensionFilesDependencies', 'cache', 'enableMigrationCache', 'parametersNotInvalidatingCache',
    'tips', 'tipsOfTheDay', 'editorUrl', 'editorUrlTitle', 'errorFormat', 'internalErrorsCountLimit', 'parallel', 'fixer', 'pro',
    'cliArgumentsVariablesRegistered', 'cliAutoloadFile', 'generateBaselineFile', 'sourceLocatorPlaygroundMode',
];

/**
 * Ce que refuse le contrôle de l'analyse statique (Larastan), d'après ses
 * paramètres effectifs, que PHPStan lit lui-même dans phpstan.neon
 * (dump-parameters : aucun lecteur NEON n'existe hors de son phar), et ses
 * paramètres de référence, ceux que donnent seuls son niveau et les
 * ensembles de règles permis que phpstan.neon inclut (INCLUSIONS_PERMISES) :
 *
 * - phpstan.neon n'inclut aucun autre fichier, directement ou non, que ces
 *   ensembles de règles et ce qu'ils incluent eux-mêmes ;
 * - seuils.json (section analyse_statique) enregistre exactement ce que
 *   règle phpstan.neon : son niveau, ses chemins, ses exclusions (celles
 *   d'EXCLUSIONS_PERMISES seulement), ses stubs (STUBS_PERMIS), ses
 *   options (OPTIONS_PERMISES, chacune à sa valeur) et ses règles
 *   désactivées (REGLES_DESACTIVABLES) ;
 * - phpstan.neon ne s'écarte de la référence que par eux : tout autre
 *   paramètre changé (un contrôle du niveau relâché, une règle de
 *   Larastan coupée, une valeur qui en cache) est refusé, comme une
 *   classe ajoutée à universalObjectCratesClasses, une entrée de
 *   ignoreErrors, ou treatPhpDocTypesAsCertain et
 *   reportUnmatchedIgnoredErrors à false ;
 * - par rapport à l'ancien seuils.json, le niveau ne baisse pas, aucun
 *   chemin ni aucune option ne disparaît, aucune option ne change de
 *   valeur, aucune exclusion, aucun stub ni aucune règle désactivée
 *   n'apparaît ; une clé que l'ancien n'a pas compte comme rien
 *   d'enregistré ;
 * - aucun commentaire d'un fichier analysé ne fait taire PHPStan sur une
 *   ligne, ni ne donne un type en ligne, que l'analyse croirait sans le
 *   vérifier (voir commentairesRefuses()).
 *
 * @param  array<array-key, mixed>  $enregistre  la section analyse_statique du nouveau seuils.json
 * @param  array<array-key, mixed>  $ancien  celle de l'ancien
 * @return list<string>
 */
function refusDeLAnalyseStatique(array $enregistre, array $ancien): array
{
    $configuration = RACINE.'/phpstan.neon';
    $effectifs = aplatir(parametresDePhpstan($configuration));
    $inclus = fichiersInclus($effectifs, $configuration);
    $configurationDeReference = configurationDeReference(array_values(array_filter($inclus, estUneInclusionPermise(...))), $effectifs['level'] ?? null);
    $reference = aplatir(parametresDePhpstan($configurationDeReference));
    $refus = array_map(
        static fn (string $fichier): string => 'fichier inclus non permis : '.nomDansLeDepot($fichier),
        array_values(array_diff($inclus, fichiersInclus($reference, $configurationDeReference))),
    );

    $niveau = $enregistre['niveau'] ?? null;
    $ancienNiveau = $ancien['niveau'] ?? null;

    if ($effectifs['level'] !== $niveau) {
        $refus[] = 'phpstan.neon analyse au niveau '.json_encode($effectifs['level']).', seuils.json enregistre le niveau '.json_encode($niveau);
    }

    if (is_int($niveau) && is_int($ancienNiveau) && $niveau < $ancienNiveau) {
        $refus[] = "niveau de l'analyse statique : {$ancienNiveau} → {$niveau}";
    }

    $chemins = cheminsDuDepot(listeDeChaines($effectifs['paths'] ?? [], 'paths'));
    $exclusions = cheminsDuDepot([...listeDeChaines($effectifs['excludePaths.analyseAndScan'] ?? [], 'excludePaths'), ...listeDeChaines($effectifs['excludePaths.analyse'] ?? [], 'excludePaths')]);
    $stubs = cheminsDuDepot(array_diff(listeDeChaines($effectifs['stubFiles'] ?? [], 'stubFiles'), listeDeChaines($reference['stubFiles'] ?? [], 'stubFiles')));

    foreach (['chemins' => $chemins, 'exclusions' => $exclusions, 'stubs' => $stubs] as $cle => $valeurs) {
        $enregistres = listeTriee($enregistre[$cle] ?? [], $cle);

        if ($valeurs !== $enregistres) {
            $refus[] = "{$cle} : phpstan.neon donne ".json_encode($valeurs, JSON_UNESCAPED_SLASHES).', seuils.json enregistre '.json_encode($enregistres, JSON_UNESCAPED_SLASHES);
        }
    }

    foreach (listeTriee($enregistre['exclusions'] ?? [], 'exclusions') as $exclusion) {
        if (! in_array($exclusion, EXCLUSIONS_PERMISES, true)) {
            $refus[] = "exclusion non permise : {$exclusion} (seules ".implode(', ', EXCLUSIONS_PERMISES).')';
        }
    }

    foreach (listeTriee($enregistre['stubs'] ?? [], 'stubs') as $stub) {
        if (! in_array($stub, STUBS_PERMIS, true)) {
            $refus[] = "stub non permis : {$stub} (seul ".implode(', ', STUBS_PERMIS).')';
        }
    }

    if (array_key_exists('chemins', $ancien)) {
        foreach (array_diff(listeTriee($ancien['chemins'], 'chemins'), listeTriee($enregistre['chemins'] ?? [], 'chemins')) as $chemin) {
            $refus[] = "le chemin {$chemin} n'est plus analysé";
        }
    }

    foreach (['exclusions' => 'nouvelle exclusion', 'stubs' => 'nouveau stub', 'regles_desactivees' => 'nouvelle règle désactivée'] as $cle => $libelle) {
        if (array_key_exists($cle, $ancien)) {
            foreach (array_diff(listeTriee($enregistre[$cle] ?? [], $cle), listeTriee($ancien[$cle], $cle)) as $apparue) {
                $refus[] = "{$libelle} : {$apparue}";
            }
        }
    }

    $options = $enregistre['options'] ?? [];
    $regles = listeTriee($enregistre['regles_desactivees'] ?? [], 'regles_desactivees');

    if (! is_array($options)) {
        throw new RuntimeException('seuils.json : les options de l\'analyse statique sont un tableau, par paramètre.');
    }

    foreach ($options as $option => $valeur) {
        $permise = OPTIONS_PERMISES[$option] ?? null;

        if ($permise === null) {
            $refus[] = "option non permise : {$option}";
        } elseif (is_array($permise) ? ! in_array($valeur, $permise, true) : $valeur !== $permise) {
            $refus[] = "option {$option} : ".json_encode($valeur).' n\'est pas sa valeur stricte ('.json_encode($permise).')';
        }

        if (($effectifs[$option] ?? null) !== $valeur) {
            $refus[] = "option {$option} : phpstan.neon donne ".json_encode($effectifs[$option] ?? null).', seuils.json enregistre '.json_encode($valeur);
        }
    }

    $anciennesOptions = $ancien['options'] ?? [];

    if (is_array($anciennesOptions)) {
        foreach ($anciennesOptions as $option => $valeur) {
            if (! array_key_exists($option, $options)) {
                $refus[] = "l'option {$option} disparaît";
            } elseif ($options[$option] !== $valeur) {
                $refus[] = "l'option {$option} change de valeur : ".json_encode($valeur).' → '.json_encode($options[$option]);
            }
        }
    }

    foreach ($regles as $regle) {
        if (! in_array($regle, REGLES_DESACTIVABLES, true)) {
            $refus[] = "règle qui ne se désactive pas : {$regle}";
        }

        if (($effectifs[$regle] ?? null) !== false) {
            $refus[] = "règle {$regle} : seuils.json l'enregistre désactivée, phpstan.neon donne ".json_encode($effectifs[$regle] ?? null);
        }
    }

    foreach (array_unique([...array_keys($effectifs), ...array_keys($reference)]) as $parametre) {
        if (in_array(explode('.', $parametre)[0], PARAMETRES_HORS_COMPARAISON, true)) {
            continue;
        }

        $valeur = $effectifs[$parametre] ?? null;
        $valeurDeReference = $reference[$parametre] ?? null;

        if ($valeur === $valeurDeReference
            || (array_key_exists($parametre, $options) && $options[$parametre] === $valeur)
            || (in_array($parametre, $regles, true) && $valeur === false)) {
            continue;
        }

        $refus[] = "{$parametre} vaut ".json_encode($valeur, JSON_UNESCAPED_SLASHES).' au lieu de '.json_encode($valeurDeReference, JSON_UNESCAPED_SLASHES)
            .' (ce que donnent le niveau et les ensembles de règles), sans être une option ni une règle désactivée de seuils.json';
    }

    if (($effectifs['ignoreErrors'] ?? []) !== []) {
        $refus[] = 'ignoreErrors cache des erreurs : '.json_encode($effectifs['ignoreErrors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    foreach (array_diff(listeDeChaines($effectifs['universalObjectCratesClasses'] ?? [], 'universalObjectCratesClasses'), listeDeChaines($reference['universalObjectCratesClasses'] ?? [], 'universalObjectCratesClasses')) as $classe) {
        $refus[] = "universalObjectCratesClasses ajoute {$classe} : ses propriétés ne seraient plus contrôlées";
    }

    foreach (['treatPhpDocTypesAsCertain', 'reportUnmatchedIgnoredErrors'] as $garde) {
        if (($effectifs[$garde] ?? null) !== true) {
            $refus[] = "{$garde} ne passe jamais à false";
        }
    }

    foreach (array_diff($chemins, $exclusions) as $chemin) {
        $refus = [...$refus, ...commentairesRefuses($chemin, $exclusions)];
    }

    return $refus;
}

/**
 * Les paramètres effectifs de PHPStan pour cette configuration, tels que
 * PHPStan les lit lui-même (dump-parameters), lancé sans les variables qui
 * lui font écrire un résumé pour un agent de code (AI_AGENT, CLAUDECODE,
 * CLAUDE_CODE), depuis la racine du dépôt.
 *
 * @return array<array-key, mixed>
 */
function parametresDePhpstan(string $configuration): array
{
    $environnement = array_diff_key(getenv(), array_flip(['AI_AGENT', 'CLAUDECODE', 'CLAUDE_CODE']));
    $commande = [PHP_BINARY, phpstan(), 'dump-parameters', '--json', '--memory-limit=1G', '--configuration='.$configuration];
    $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, RACINE, $environnement);

    if ($processus === false) {
        throw new RuntimeException('PHPStan ne se lance pas.');
    }

    $sortie = stream_get_contents($tubes[1]);
    $erreurs = stream_get_contents($tubes[2]);
    fclose($tubes[1]);
    fclose($tubes[2]);
    $code = proc_close($processus);

    if ($sortie === false || $erreurs === false) {
        throw new RuntimeException("La sortie de PHPStan pour {$configuration} est illisible.");
    }

    $parametres = json_decode($sortie, true);

    if ($code !== 0 || ! is_array($parametres)) {
        throw new RuntimeException("PHPStan ne rend pas les paramètres de {$configuration} (code {$code}) : ".trim($erreurs.' '.$sortie));
    }

    return $parametres;
}

/**
 * Le PHPStan que lance le contrôle : celui du dépôt, ou celui que nomme
 * QUALITE_PHPSTAN par son chemin absolu (les tests de cet outil, dans un
 * dépôt temporaire). Son dossier vendor est celui des inclusions permises.
 */
function phpstan(): string
{
    $phpstan = getenv('QUALITE_PHPSTAN');

    return is_string($phpstan) && $phpstan !== '' ? $phpstan : RACINE.'/vendor/bin/phpstan';
}

/**
 * Les fichiers de configuration que cette configuration inclut, directement
 * ou non, tels que PHPStan les a lus : ceux qui la suivent dans
 * allConfigFiles, où chaque fichier précède ceux qu'il inclut (le schéma
 * des paramètres et les fichiers des niveaux, que PHPStan lit de lui-même,
 * la précèdent).
 *
 * @param  array<string, mixed>  $parametres  les paramètres effectifs aplatis de cette configuration
 * @return list<string>
 */
function fichiersInclus(array $parametres, string $configuration): array
{
    $fichiers = listeDeChaines($parametres['allConfigFiles'] ?? [], 'allConfigFiles');
    $rang = array_search(realpath($configuration), $fichiers, true);

    if ($rang === false) {
        throw new RuntimeException("PHPStan ne dit pas les fichiers inclus de {$configuration}.");
    }

    return array_slice($fichiers, $rang + 1);
}

/**
 * Ce fichier de configuration est-il l'une des inclusions permises
 * (INCLUSIONS_PERMISES), dans le dossier vendor du PHPStan que lance le
 * contrôle ?
 */
function estUneInclusionPermise(string $fichier): bool
{
    $reel = realpath($fichier);

    return $reel !== false && in_array($reel, array_map(static fn (string $permise): string|false => realpath(dirname(phpstan(), 2).'/'.$permise), INCLUSIONS_PERMISES), true);
}

/**
 * Une configuration qui n'inclut que ces fichiers (les inclusions permises
 * de phpstan.neon), à ce niveau, sans aucun autre paramètre : ce que
 * PHPStan contrôle d'office à ce niveau avec ces ensembles de règles.
 * Écrite dans un fichier temporaire, effacé à la fin de la commande.
 *
 * @param  list<string>  $inclusions
 */
function configurationDeReference(array $inclusions, mixed $niveau): string
{
    if (! is_int($niveau)) {
        throw new RuntimeException('PHPStan ne dit pas le niveau de phpstan.neon.');
    }

    $dossier = realpath(sys_get_temp_dir());

    if ($dossier === false) {
        throw new RuntimeException('Le dossier temporaire '.sys_get_temp_dir().' est introuvable.');
    }

    $neon = $inclusions === [] ? '' : "includes:\n".implode('', array_map(static fn (string $inclus): string => "    - '".str_replace("'", "''", $inclus)."'\n", $inclusions))."\n";
    $reference = $dossier.'/piliqi-reference-'.getmypid().'-'.bin2hex(random_bytes(4)).'.neon';
    file_put_contents($reference, $neon."parameters:\n    level: {$niveau}\n");
    register_shutdown_function(static fn (): bool => ! is_file($reference) || unlink($reference));

    return $reference;
}

/**
 * Les paramètres, chacun sous sa clé pointée (« exceptions.check.tooWideThrowType ») :
 * un tableau associatif se déplie, une liste reste une valeur.
 *
 * @param  array<array-key, mixed>  $parametres
 * @return array<string, mixed>
 */
function aplatir(array $parametres, string $prefixe = ''): array
{
    $aplatis = [];

    foreach ($parametres as $cle => $valeur) {
        $nom = $prefixe.$cle;

        if (is_array($valeur) && $valeur !== [] && ! array_is_list($valeur)) {
            $aplatis = [...$aplatis, ...aplatir($valeur, $nom.'.')];
        } else {
            $aplatis[$nom] = $valeur;
        }
    }

    return $aplatis;
}

/**
 * @return list<string>
 */
function listeDeChaines(mixed $valeurs, string $nom): array
{
    if (! is_array($valeurs) || ! array_is_list($valeurs)) {
        throw new RuntimeException("{$nom} n'est pas une liste.");
    }

    return array_map(static function (mixed $valeur) use ($nom): string {
        if (! is_string($valeur)) {
            throw new RuntimeException("{$nom} ne contient pas que des chaînes.");
        }

        return $valeur;
    }, $valeurs);
}

/**
 * @return list<string> triée
 */
function listeTriee(mixed $valeurs, string $nom): array
{
    $liste = listeDeChaines($valeurs, $nom);
    sort($liste);

    return $liste;
}

/**
 * Ces chemins absolus, nommés depuis la racine du dépôt (en entier hors du
 * dépôt, voir nomDansLeDepot()), triés.
 *
 * @param  array<array-key, string>  $chemins
 * @return list<string>
 */
function cheminsDuDepot(array $chemins): array
{
    $noms = array_map(nomDansLeDepot(...), array_values($chemins));
    sort($noms);

    return $noms;
}

/**
 * Les commentaires d'un fichier PHP de ce chemin (un fichier ou un
 * dossier du dépôt), hors des exclusions, que le contrôle refuse, chacun
 * avec sa raison et « fichier:ligne » : celui qui demande à PHPStan
 * d'ignorer une erreur, et celui qui donne un type en ligne, une balise
 * var, phpstan-var ou psalm-var ailleurs que devant la déclaration d'une
 * propriété ou d'une constante (voir precedeUneDeclaration()), un type
 * que l'analyse croirait sans le vérifier (cahier de l'analyse statique,
 * § 4.2, TY2). La recherche se fait par token_get_all, dans les seuls
 * commentaires, si bien qu'une chaîne du code qui cite une consigne ou une
 * balise (comme celles de cette fonction) n'en est pas une.
 *
 * @param  list<string>  $exclusions
 * @return list<string>
 */
function commentairesRefuses(string $chemin, array $exclusions): array
{
    $absolu = RACINE.'/'.$chemin;
    $fichiers = is_dir($absolu) ? glob_recursif($absolu) : (is_file($absolu) ? [$absolu] : []);
    $refus = [];

    foreach ($fichiers as $fichier) {
        $relatif = relatifAuDepot($fichier);

        foreach ($exclusions as $exclusion) {
            if ($relatif === $exclusion || str_starts_with($relatif, $exclusion.'/')) {
                continue 2;
            }
        }

        $source = file_get_contents($fichier);

        if ($source === false) {
            throw new RuntimeException("Fichier illisible : {$relatif}.");
        }

        $jetons = token_get_all($source);

        foreach ($jetons as $rang => $jeton) {
            if (! is_array($jeton) || ! in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (str_contains($jeton[1], '@phpstan-'.'ignore')) {
                $refus[] = "commentaire qui fait taire PHPStan : {$relatif}:{$jeton[2]}";
            }

            if (preg_match('/@(?:phpstan-|psalm-)?var\b/', $jeton[1]) === 1 && ! precedeUneDeclaration($jetons, $rang)) {
                $refus[] = "type donné en ligne par un commentaire : {$relatif}:{$jeton[2]}";
            }
        }
    }

    return $refus;
}

/**
 * Le commentaire de ce rang précède-t-il la déclaration d'une propriété
 * (ses modificateurs, dont au moins sa visibilité, var ou readonly, puis
 * son type et son nom ; propriété promue d'un constructeur comprise) ou
 * d'une constante, de classe ou non ? Les espaces, les autres commentaires
 * et les attributs qui les séparent sont sautés. Toute autre suite (une
 * affectation, un return, une variable sans modificateur, une variable
 * statique d'une fonction, une fonction) n'en est pas une.
 *
 * @param  array<int, array{int, string, int}|string>  $jetons  ceux de token_get_all()
 */
function precedeUneDeclaration(array $jetons, int $rang): bool
{
    $visible = false;
    $dansUnAttribut = 0;

    for ($suivant = $rang + 1; isset($jetons[$suivant]); $suivant++) {
        $jeton = $jetons[$suivant];
        $nature = is_array($jeton) ? $jeton[0] : $jeton;

        if ($dansUnAttribut > 0) {
            $dansUnAttribut += match ($nature) {
                T_ATTRIBUTE, '[' => 1,
                ']' => -1,
                default => 0,
            };

            continue;
        }

        if ($nature === T_ATTRIBUTE) {
            $dansUnAttribut = 1;
        } elseif (in_array($nature, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY], true)) {
            $visible = true;
        } elseif ($nature === T_CONST) {
            return true;
        } elseif ($nature === T_VARIABLE) {
            return $visible;
        } elseif (! in_array($nature, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_FINAL, T_ABSTRACT], true)
            && ! ($visible && in_array($nature, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, '?', '|', '(', ')'], true))) {
            return false;
        }
    }

    return false;
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
    $parties = parties();
    $chemins = [];

    foreach (PARTIES_SENSIBLES as $partie) {
        array_push($chemins, ...$parties[$partie] ?? throw new RuntimeException("parties.php n'a pas la partie sensible {$partie}."));
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
        if (! $fichier instanceof SplFileInfo) {
            throw new RuntimeException("Le parcours de {$dossier} ne rend pas un fichier : ".get_debug_type($fichier).'.');
        }

        if ($fichier->isFile() && str_ends_with($fichier->getFilename(), '.php')) {
            $fichiers[] = $fichier->getPathname();
        }
    }

    return $fichiers;
}

function configurationSensibles(string $destination): int
{
    $configuration = tableauLu(json_decode((string) preg_replace('#^\s*//.*$#m', '', contenuDe(RACINE.'/infection.json5')), true, flags: JSON_THROW_ON_ERROR), 'infection.json5');
    $configuration['$schema'] = RACINE.'/vendor/infection/infection/resources/schema.json';
    $configuration['source'] = ['directories' => [RACINE.'/app'], 'excludes' => exclusionsHorsDesPartiesSensibles()];
    $configuration['tmpDir'] = RACINE.'/'.texteLu($configuration['tmpDir'] ?? null, 'le tmpDir de infection.json5');
    $configuration['logs'] = array_map(fn (mixed $journal): string => RACINE.'/'.texteLu($journal, 'un journal de infection.json5'), tableauLu($configuration['logs'] ?? null, 'les journaux de infection.json5'));
    $configuration['phpUnit'] = ['configDir' => RACINE];
    file_put_contents($destination, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

    return 0;
}

function sensiblesChanges(string $base): int
{
    $fichiers = explode("\n", trim((string) shell_exec('git -C '.escapeshellarg(RACINE).' diff --name-only --no-renames --diff-filter=AM --no-color '.escapeshellarg($base).' -- app/')));
    $changes = array_filter($fichiers, fn (string $fichier): bool => $fichier !== '' && in_array(partieDe($fichier), PARTIES_SENSIBLES, true));

    echo $changes === [] ? '' : implode("\n", $changes)."\n";

    return 0;
}

/**
 * Un problème d'un rapport de Psalm est-il un chemin de sécurité (une
 * donnée venue de l'extérieur qui atteint, sans contrôle, une requête SQL,
 * une page, un fichier, une commande, une redirection ou un e-mail) ?
 *
 * @param  array<array-key, mixed>  $probleme
 */
function estUnCheminDeSecurite(array $probleme): bool
{
    return ($probleme['is_security'] ?? false) === true || str_starts_with(texteLu($probleme['type'] ?? null, "le type d'un problème du rapport de Psalm"), 'Tainted');
}

/**
 * Les chemins de sécurité d'un rapport JSON de Psalm (voir
 * estUnCheminDeSecurite()), dans son ordre, chacun avec ce que le contrôle
 * en lit : son fichier, sa ligne, son type et son message.
 *
 * @return list<array{fichier: string, ligne: int, type: string, message: string}>
 */
function cheminsDeSecurite(string $rapport): array
{
    $chemins = [];

    foreach (tableauLu(json_decode(contenuDe($rapport), true, flags: JSON_THROW_ON_ERROR), "le rapport de Psalm {$rapport}") as $probleme) {
        $probleme = tableauLu($probleme, "un problème du rapport de Psalm {$rapport}");

        if (estUnCheminDeSecurite($probleme)) {
            $chemins[] = [
                'fichier' => texteLu($probleme['file_name'] ?? null, "le fichier d'un chemin du rapport de Psalm"),
                'ligne' => entierLu($probleme['line_from'] ?? null, "la ligne d'un chemin du rapport de Psalm"),
                'type' => texteLu($probleme['type'], "le type d'un chemin du rapport de Psalm"),
                'message' => texteLu($probleme['message'] ?? null, "le message d'un chemin du rapport de Psalm"),
            ];
        }
    }

    return $chemins;
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

    $chemins = cheminsDeSecurite($rapport);

    if ($chemins === []) {
        echo "Aucun chemin d'une donnée venue de l'extérieur sans contrôle.\n";

        return 0;
    }

    echo "Chemins d'une donnée venue de l'extérieur sans contrôle :\n";

    foreach ($chemins as $chemin) {
        echo sprintf("  %s:%d %s : %s\n", $chemin['fichier'], $chemin['ligne'], $chemin['type'], $chemin['message']);
    }

    return 1;
}

/**
 * Les chemins de sécurité d'un rapport de Psalm, chacun « fichier:ligne
 * type », triés ; null sans rapport.
 *
 * @return list<string>|null
 */
function cheminsDuRapport(string $rapport): ?array
{
    if (! is_file($rapport)) {
        return null;
    }

    $chemins = [];

    foreach (cheminsDeSecurite($rapport) as $chemin) {
        $chemins[] = sprintf('%s:%d %s', relatifAuDepot($chemin['fichier']), $chemin['ligne'], $chemin['type']);
    }

    sort($chemins);

    return $chemins;
}

function fluxDuTemoin(string $rapport): int
{
    $trouves = cheminsDuRapport($rapport);

    if ($trouves === null) {
        echo "Aucun rapport de Psalm pour le témoin : {$rapport}. L'analyse n'a pas tourné jusqu'au bout.\n";

        return 1;
    }

    $attendus = [];

    foreach (glob_recursif(RACINE.'/tests/Qualite/Psalm/Temoin') as $fichier) {
        foreach (file($fichier) ?: [] as $rang => $ligne) {
            if (preg_match('#// attendu : (.+)$#', rtrim($ligne), $marque) === 1) {
                foreach (array_map(trim(...), explode(',', $marque[1])) as $type) {
                    $attendus[] = sprintf('%s:%d %s', relatifAuDepot($fichier), $rang + 1, $type);
                }
            }
        }
    }

    sort($attendus);
    $manquants = array_diff($attendus, $trouves);
    $enTrop = array_diff($trouves, $attendus);

    if ($attendus !== [] && $manquants === [] && $enTrop === []) {
        echo 'L\'analyse des flux voit les '.count($attendus)." chemins du témoin, et aucun autre.\n";

        return 0;
    }

    echo "L'analyse des flux ne voit pas le témoin tel qu'il est marqué :\n";

    foreach ($manquants as $chemin) {
        echo "  chemin non vu : {$chemin}\n";
    }

    foreach ($enTrop as $chemin) {
        echo "  chemin en trop : {$chemin}\n";
    }

    if ($attendus === []) {
        echo "  aucune ligne marquée « attendu : » dans tests/Qualite/Psalm/Temoin.\n";
    }

    return 1;
}

/**
 * Les commandes, chacune avec le nombre d'arguments qu'elle exige et ceux
 * qu'elle accepte en plus, et leur usage.
 */
const COMMANDES = [
    'couverture' => [1, 1, '<clover.xml> [<rapport.md>]'],
    'nouveau-code' => [2, 0, '<clover.xml> <commit de base>'],
    'mutation' => [1, 1, '<infection.json> [<rapport.md>]'],
    'seuils' => [0, 1, '[<ancien seuils.json>]'],
    'sensibles' => [0, 0, ''],
    'sensibles-changes' => [1, 0, '<commit de base>'],
    'configuration-sensibles' => [1, 0, '<destination>'],
    'flux' => [1, 0, '<rapport-psalm.json>'],
    'flux-temoin' => [1, 0, '<rapport-psalm.json>'],
];

/**
 * L'usage de l'outil, sur la sortie d'erreur ; code de sortie 2.
 */
function usage(): int
{
    $lignes = array_map(
        static fn (string $commande, array $definition): string => rtrim("  php tests/Qualite/qualite.php {$commande} {$definition[2]}"),
        array_keys(COMMANDES),
        COMMANDES,
    );
    fwrite(STDERR, "Usage :\n".implode("\n", $lignes)."\n");

    return 2;
}

/**
 * Lance la commande avec ses arguments, ou écrit l'usage quand elle est
 * inconnue ou que ses arguments ne sont pas en nombre.
 *
 * @param  list<string>  $arguments
 */
function lancer(?string $commande, array $arguments): int
{
    $definition = COMMANDES[$commande] ?? null;

    if ($commande === null || $definition === null || count($arguments) < $definition[0] || count($arguments) > $definition[0] + $definition[1]) {
        return usage();
    }

    return match ($commande) {
        'couverture' => couverture($arguments[0], $arguments[1] ?? null),
        'nouveau-code' => nouveauCode($arguments[0], $arguments[1]),
        'mutation' => mutation($arguments[0], $arguments[1] ?? null),
        'seuils' => seuilsQuiMontent($arguments[0] ?? null),
        'sensibles' => sensibles(),
        'sensibles-changes' => sensiblesChanges($arguments[0]),
        'configuration-sensibles' => configurationSensibles($arguments[0]),
        'flux' => flux($arguments[0]),
        'flux-temoin' => fluxDuTemoin($arguments[0]),
        default => usage(),
    };
}

try {
    exit(lancer($argv[1] ?? null, array_slice($argv, 2)));
} catch (RuntimeException|JsonException $erreur) {
    echo 'Échec : ', $erreur->getMessage(), "\n";

    exit(1);
}
