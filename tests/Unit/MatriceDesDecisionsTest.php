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
 * - chaque décision de l'éditeur, de chaque fichier qui les porte, renvoie
 *   aux règles qui la font vivre, ou dit pourquoi aucun test ne la porte :
 *   une façon de travailler, hors du logiciel ; une décision remplacée, par
 *   celle qui la remplace ; une étape de la feuille de route qui n'est pas
 *   encore faite ; une explication donnée à l'éditeur, qui ne décide rien.
 * Ce test lit les fichiers, sans base de données.
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
     * Pourquoi une décision n'a pas de test : chaque clé, avec sa raison
     * écrite.
     *
     * @var list<string>
     */
    private const RAISONS_SANS_TEST = ['hors_logiciel', 'remplacee', 'a_venir', 'explication'];

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
                $nom = $source.' › '.$identifiant;
                $raisons = array_values(array_intersect(array_keys($decision), self::RAISONS_SANS_TEST));
                $renvois = $decision['regles'] ?? [];

                if (trim($decision['decision']) === '') {
                    $fautes[] = $nom.' : décision sans texte';
                }

                if (($renvois === []) === ($raisons === [])) {
                    $fautes[] = $nom.' : il faut soit des règles, soit une seule raison sans test';
                }

                if (count($raisons) > 1) {
                    $fautes[] = $nom.' : plusieurs raisons sans test';
                }

                foreach ($raisons as $raison) {
                    if (trim((string) $decision[$raison]) === '') {
                        $fautes[] = $nom.' : raison « '.$raison.' » sans texte';
                    }
                }

                foreach ($renvois as $renvoi) {
                    if (! isset($regles[$renvoi])) {
                        $fautes[] = $nom.' : règle inconnue « '.$renvoi.' »';
                    }
                }
            }
        }

        $this->assertSame([], $fautes);
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
     *     decisions: array<string, array<string, array{decision: string, regles?: list<string>, hors_logiciel?: string, remplacee?: string, a_venir?: string, explication?: string}>>
     * }
     */
    private static function matrice(): array
    {
        return require __DIR__.'/matrice-des-decisions.php';
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
