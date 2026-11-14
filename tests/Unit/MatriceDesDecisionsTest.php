<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La matrice décision → test (tests/Unit/matrice-des-decisions.php, lot de
 * décisions 21 étendu) suit CLAUDE.md et les tests :
 * - chaque puce des décisions de l'éditeur de CLAUDE.md, et chacune de ses
 *   autres parties qui portent des règles (protocole de rigueur, produit,
 *   développement), a au moins une règle dans la matrice, et chaque règle
 *   nomme une partie qui existe ;
 * - chaque règle nomme au moins un test, et chaque test nommé existe,
 *   « chemin › nom » pour un test Pest, « chemin::méthode » pour un test
 *   PHPUnit ;
 * - chaque décision recopiée dans la matrice renvoie aux règles qui la font
 *   vivre, ou dit pourquoi aucun test ne la porte : une façon de travailler,
 *   hors du logiciel ; une décision remplacée, par celle qui la remplace ;
 *   une étape de la feuille de route qui n'est pas encore faite ; une
 *   explication donnée à l'éditeur, qui ne décide rien ; une décision
 *   remplacée en partie garde ses règles pour la part qui tient, et une
 *   décision que le code contredit dit la question proposée à l'éditeur ;
 * - les 295 décisions de Claude de l'onglet « À faire vérifier par un
 *   professionnel » y sont, chacune sous son numéro, sans aucune question
 *   à trancher (D76), et chacune qui reste à faire, en tout ou en partie,
 *   nomme son étape ou son lot : une décision de l'onglet appliquée en
 *   partie garde ses règles pour la part faite et dit, en « a_venir »,
 *   l'étape ou le lot de ce qui reste.
 * Ce test lit les fichiers, sans base de données. Que la matrice recopie
 * chaque décision de chaque fichier de notes de l'éditeur, hors du dépôt,
 * ne se contrôle pas ici : la relecture de chaque lot qui la tient à jour
 * le vérifie.
 */
class MatriceDesDecisionsTest extends TestCase
{
    /**
     * Les parties de CLAUDE.md, hors des puces des décisions de l'éditeur,
     * qui portent des règles, et la partie des décisions que CLAUDE.md
     * n'écrit pas (règles écrites seulement dans le code et proposées à
     * l'éditeur).
     *
     * @var list<string>
     */
    private const AUTRES_PARTIES = ['Protocole de rigueur', 'Développement', 'Produit', 'Hors de CLAUDE.md'];

    /**
     * Pourquoi une décision, ou une part d'une décision, n'a pas de test :
     * chaque clé, avec sa raison écrite. Seule une décision remplacée en
     * partie (« remplacee ») garde aussi des règles, pour la part qui tient,
     * et, parmi les décisions de l'onglet, une décision appliquée en partie
     * (« a_venir »), pour la part faite.
     *
     * @var list<string>
     */
    private const RAISONS_SANS_TEST = ['hors_logiciel', 'remplacee', 'a_venir', 'explication'];

    /**
     * Une décision de l'éditeur qui tient, ou la part d'une décision qui
     * tient, que le code contredit : la question proposée à l'éditeur, avec
     * ce que le code fait en attendant sa réponse.
     */
    private const QUESTION_A_TRANCHER = 'a_trancher';

    /**
     * La source des décisions de Claude de l'onglet « À faire vérifier par
     * un professionnel » du document de pilotage (D76), et leur nombre :
     * une entrée par ligne de l'onglet, la question au comptable et celle
     * au prestataire d'un même numéro sous deux clés.
     */
    private const SOURCE_DE_L_ONGLET = 'Décisions de Claude de l\'onglet « À faire vérifier par un professionnel » (D76), appliquées sans attendre ; une réponse du professionnel prime (decisions-affichage/sortie/section-*.md)';

    private const DECISIONS_DE_L_ONGLET = 295;

    public function test_chaque_partie_de_claude_md_a_ses_regles_et_chaque_regle_nomme_une_partie_qui_existe(): void
    {
        $parties = [...self::pucesDesDecisions(self::lire('CLAUDE.md')), ...self::AUTRES_PARTIES];
        $regles = self::matrice()['regles'];
        $nommees = array_unique(array_column($regles, 'puce'));

        $this->assertSame([], array_values(array_diff($parties, $nommees)), 'Parties de CLAUDE.md sans règle dans la matrice.');
        $this->assertSame([], array_values(array_diff($nommees, $parties)), 'Règles qui nomment une partie absente de CLAUDE.md.');
    }

    public function test_chaque_regle_nomme_au_moins_un_test_et_chaque_test_nomme_existe(): void
    {
        $fautes = [];

        foreach (self::matrice()['regles'] as $cle => $regle) {
            if (trim($regle['regle']) === '') {
                $fautes[] = $cle.' : règle sans texte';
            }

            if ($regle['tests'] === []) {
                $fautes[] = $cle.' : aucun test';
            }

            foreach ($regle['tests'] as $test) {
                if (! self::referenceExiste($test)) {
                    $fautes[] = $cle.' : test introuvable « '.$test.' »';
                }
            }
        }

        $this->assertSame([], $fautes);
    }

    public function test_chaque_decision_renvoie_a_ses_regles_ou_dit_pourquoi_aucun_test_ne_la_porte(): void
    {
        $regles = self::matrice()['regles'];
        $fautes = [];

        foreach (self::matrice()['decisions'] as $source => $decisions) {
            foreach ($decisions as $identifiant => $decision) {
                $fautes = [...$fautes, ...self::fautesDeLaDecision($source.' › '.$identifiant, $decision, $regles, $source === self::SOURCE_DE_L_ONGLET)];
            }
        }

        $this->assertSame([], $fautes);
    }

    /**
     * Les décisions de Claude de l'onglet y sont toutes, chacune sous son
     * numéro (« T2.1-comptable », « T90.1-prestataire-4 »…) ; aucune
     * n'attend une réponse (D76 interdit « a_trancher ») et chacune qui
     * reste à faire nomme l'étape ou le lot qui l'appliquera.
     */
    public function test_les_decisions_de_claude_de_l_onglet_y_sont_toutes_et_aucune_n_attend(): void
    {
        $decisions = self::matrice()['decisions'][self::SOURCE_DE_L_ONGLET] ?? [];
        $malNumerotees = array_filter(array_keys($decisions), fn (string $cle): bool => preg_match('/^[TP]\d+(\.\d+)?(-(comptable|prestataire))?(-\d)?$/', $cle) !== 1);
        $quiAttendent = array_filter($decisions, fn (array $decision): bool => array_key_exists(self::QUESTION_A_TRANCHER, $decision));
        $sansEtapeNiLot = array_filter($decisions, fn (array $decision): bool => isset($decision['a_venir']) && preg_match('/(étapes? \d|\blots?\b)/iu', $decision['a_venir']) !== 1);

        $this->assertCount(self::DECISIONS_DE_L_ONGLET, $decisions);
        $this->assertSame([], array_values($malNumerotees));
        $this->assertSame([], array_keys($quiAttendent));
        $this->assertSame([], array_keys($sansEtapeNiLot));
    }

    /**
     * Une décision renvoie à ses règles, dit une seule raison sans test,
     * ou les deux quand elle est remplacée en partie, ou, pour une décision
     * de l'onglet, appliquée en partie ; une question à trancher s'ajoute à
     * l'une ou l'autre forme, ou se suffit.
     */
    public function test_une_decision_remplacee_en_partie_garde_ses_regles_et_une_question_a_trancher_se_dit(): void
    {
        $regles = ['une.regle' => ['puce' => 'Produit', 'regle' => 'Une règle.', 'tests' => []]];
        $controler = fn (array $decision): array => self::fautesDeLaDecision('source › 1', ['decision' => 'Une décision.', ...$decision], $regles);
        $controlerDansLOnglet = fn (array $decision): array => self::fautesDeLaDecision('onglet › T1', ['decision' => 'Une décision.', ...$decision], $regles, true);

        $this->assertSame([], $controler(['regles' => ['une.regle']]));
        $this->assertSame([], $controler(['a_venir' => 'Étape 2.']));
        $this->assertSame([], $controler(['regles' => ['une.regle'], 'remplacee' => 'Remplacée en partie par B7.']));
        $this->assertSame([], $controler(['regles' => ['une.regle'], 'remplacee' => 'Remplacée en partie par B7.', 'a_trancher' => 'Question proposée à l\'éditeur.']));
        $this->assertSame([], $controler(['a_trancher' => 'Question proposée à l\'éditeur.']));
        $this->assertSame(['source › 1 : seule une décision remplacée en partie garde des règles avec une raison sans test'], $controler(['regles' => ['une.regle'], 'a_venir' => 'Étape 2.']));
        $this->assertSame(['source › 1 : il faut des règles, une raison sans test ou une question à trancher'], $controler([]));
        $this->assertSame(['source › 1 : plusieurs raisons sans test'], $controler(['remplacee' => 'Par B7.', 'a_venir' => 'Étape 2.']));
        $this->assertSame(['source › 1 : raison « a_trancher » sans texte'], $controler(['regles' => ['une.regle'], 'a_trancher' => ' ']));
        $this->assertSame(['source › 1 : règle inconnue « une.autre »'], $controler(['regles' => ['une.autre']]));
        $this->assertSame([], $controlerDansLOnglet(['regles' => ['une.regle'], 'a_venir' => 'Étape 2 : la part qui reste.']));
        $this->assertSame([], $controlerDansLOnglet(['regles' => ['une.regle'], 'remplacee' => 'Remplacée en partie par T2.']));
        $this->assertSame(['onglet › T1 : seule une décision remplacée ou appliquée en partie garde des règles avec une raison sans test'], $controlerDansLOnglet(['regles' => ['une.regle'], 'hors_logiciel' => 'Une démarche.']));
        $this->assertSame(['onglet › T1 : seule une décision remplacée ou appliquée en partie garde des règles avec une raison sans test', 'onglet › T1 : plusieurs raisons sans test'], $controlerDansLOnglet(['regles' => ['une.regle'], 'remplacee' => 'Par T2.', 'a_venir' => 'Étape 2.']));
    }

    /**
     * La lecture des références refuse un test absent, un fichier absent et
     * une référence mal écrite, et trouve un test PHPUnit et un test Pest
     * qui existent.
     */
    public function test_la_lecture_des_references_trouve_les_tests_et_refuse_ceux_qui_n_existent_pas(): void
    {
        $this->assertTrue(self::referenceExiste('tests/Unit/MatriceDesDecisionsTest.php::test_chaque_regle_nomme_au_moins_un_test_et_chaque_test_nomme_existe'));
        $this->assertTrue(self::referenceExiste('tests/Browser/InventaireDesActionsTest.php › chaque action déclarée dans le code figure dans l’inventaire'));
        $this->assertFalse(self::referenceExiste('tests/Unit/MatriceDesDecisionsTest.php::test_qui_n_existe_pas'));
        $this->assertFalse(self::referenceExiste('tests/Unit/MatriceDesDecisionsTest.php::test_chaque_regle'));
        $this->assertFalse(self::referenceExiste('tests/Browser/InventaireDesActionsTest.php › un test qui n’existe pas'));
        $this->assertFalse(self::referenceExiste('tests/Unit/FichierAbsentTest.php::test_quelque_chose'));
        $this->assertFalse(self::referenceExiste('tests/Unit/MatriceDesDecisionsTest.php'));
    }

    /**
     * La lecture de CLAUDE.md trouve le titre de chaque puce des décisions
     * de l'éditeur, et seulement elles.
     */
    public function test_la_lecture_de_claude_md_trouve_chaque_puce_des_decisions_de_l_editeur(): void
    {
        $texte = implode("\n", [
            '# Protocole',
            '- Pas une décision : rien.',
            '## Décisions de l\'éditeur',
            '',
            'Règles métier tranchées par l\'éditeur : elles s\'appliquent à tout le code.',
            '',
            '- Comptes et équipe : un compte appartient à une société.',
            '- Facture électronique (SUPER PDP) : chaque société a une connexion.',
            '## Feuille de route',
            '- Fait : rien.',
        ]);

        $this->assertSame(['Comptes et équipe', 'Facture électronique (SUPER PDP)'], self::pucesDesDecisions($texte));
        $this->assertContains('Comptes et équipe', self::pucesDesDecisions(self::lire('CLAUDE.md')));
    }

    /**
     * @return array{
     *     regles: array<string, array{puce: string, regle: string, tests: list<string>}>,
     *     decisions: array<string, array<string, array{decision: string, regles?: list<string>, hors_logiciel?: string, remplacee?: string, a_venir?: string, explication?: string, a_trancher?: string}>>
     * }
     */
    private static function matrice(): array
    {
        return require __DIR__.'/matrice-des-decisions.php';
    }

    /**
     * Ce qui manque à cette décision : un texte, des règles connues ou une
     * seule raison sans test (les deux seulement quand elle est remplacée
     * en partie, ou, pour une décision de l'onglet « À faire vérifier par
     * un professionnel », appliquée en partie, ce qui reste « a_venir »),
     * et le texte de chaque raison et de la question à trancher.
     *
     * @param  array{decision: string, regles?: list<string>, hors_logiciel?: string, remplacee?: string, a_venir?: string, explication?: string, a_trancher?: string}  $decision
     * @param  array<string, array{puce: string, regle: string, tests: list<string>}>  $regles
     * @param  bool  $deLOnglet  une décision de Claude de l'onglet, qui peut être appliquée en partie
     * @return list<string>
     */
    private static function fautesDeLaDecision(string $nom, array $decision, array $regles, bool $deLOnglet = false): array
    {
        $fautes = [];
        $raisons = array_values(array_intersect(array_keys($decision), self::RAISONS_SANS_TEST));
        $renvois = $decision['regles'] ?? [];
        $question = array_key_exists(self::QUESTION_A_TRANCHER, $decision);

        if (trim($decision['decision']) === '') {
            $fautes[] = $nom.' : décision sans texte';
        }

        if ($renvois === [] && $raisons === [] && ! $question) {
            $fautes[] = $nom.' : il faut des règles, une raison sans test ou une question à trancher';
        }

        if ($renvois !== [] && $raisons !== [] && ! in_array($raisons, $deLOnglet ? [['remplacee'], ['a_venir']] : [['remplacee']], true)) {
            $fautes[] = $nom.' : seule une décision remplacée '.($deLOnglet ? 'ou appliquée ' : '').'en partie garde des règles avec une raison sans test';
        }

        if (count($raisons) > 1) {
            $fautes[] = $nom.' : plusieurs raisons sans test';
        }

        foreach ([...$raisons, ...($question ? [self::QUESTION_A_TRANCHER] : [])] as $raison) {
            if (trim((string) $decision[$raison]) === '') {
                $fautes[] = $nom.' : raison « '.$raison.' » sans texte';
            }
        }

        foreach ($renvois as $renvoi) {
            if (! isset($regles[$renvoi])) {
                $fautes[] = $nom.' : règle inconnue « '.$renvoi.' »';
            }
        }

        return $fautes;
    }

    /**
     * Le titre de chaque puce de la partie « Décisions de l'éditeur » de
     * CLAUDE.md, dans l'ordre (« - Titre : texte »).
     *
     * @return list<string>
     */
    private static function pucesDesDecisions(string $claude): array
    {
        if (preg_match('/^## Décisions de l\'éditeur$(.*?)(?=^## |\z)/msu', $claude, $partie) !== 1) {
            return [];
        }

        preg_match_all('/^- (.+?) : /mu', $partie[1], $puces);

        return $puces[1];
    }

    /**
     * Le test que nomme cette référence existe-t-il ? « chemin::méthode »
     * pour une méthode test_… d'une classe PHPUnit, « chemin › nom » pour
     * un test('nom', …) de Pest.
     */
    private static function referenceExiste(string $reference): bool
    {
        if (str_contains($reference, ' › ')) {
            [$chemin, $nom] = explode(' › ', $reference, 2);

            return in_array($nom, self::nomsDesTestsPest($chemin), true);
        }

        if (str_contains($reference, '::')) {
            [$chemin, $methode] = explode('::', $reference, 2);
            $code = self::lire($chemin);

            return str_starts_with($methode, 'test_') && preg_match('/\bfunction '.preg_quote($methode, '/').'\s*\(/', $code) === 1;
        }

        return false;
    }

    /**
     * Les noms des tests que déclare ce fichier Pest (test('nom', …)).
     *
     * @return list<string>
     */
    private static function nomsDesTestsPest(string $chemin): array
    {
        preg_match_all("/\\btest\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", self::lire($chemin), $tests);

        return array_map(fn (string $nom): string => stripcslashes($nom), $tests[1]);
    }

    /**
     * Le contenu de ce fichier du dépôt, ou rien s'il n'existe pas.
     */
    private static function lire(string $chemin): string
    {
        $fichier = dirname(__DIR__, 2).'/'.$chemin;

        return is_file($fichier) ? (string) file_get_contents($fichier) : '';
    }
}
