<?php

namespace Tests\Feature\Qualite;

use Illuminate\Filesystem\Filesystem;
use Infection\Command\RunCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Le contrôle de la couverture de tests/Qualite/qualite.php (décision de
 * l'éditeur du 01/11/2026, point 1) range chaque fichier d'un rapport
 * Clover dans sa partie par son chemin relatif à la racine du dépôt, quel
 * que soit le dossier où le dépôt est monté (« /app » d'un conteneur
 * compris), refuse un rapport venu d'un autre dépôt, et ne laisse jamais
 * passer en silence une partie qui a un seuil sans être mesurée ; il
 * nomme depuis la racine du dépôt les fichiers sensibles changés, que la
 * CI donne entiers à Infection, qui les lit tous comme des chemins (la
 * commande de la CI, lue dans son fichier) ; le contrôle du témoin de
 * l'analyse des flux exige exactement les chemins qu'il annonce ; le
 * contrôle des seuils (décision de l'éditeur du 09/11/2026, cahier de
 * l'analyse statique, § 4.3) refuse toute baisse d'un seuil et tout
 * réglage de l'analyse statique que seuils.json n'enregistre pas, lu par
 * le PHPStan du dépôt, appelé par son chemin absolu. Chaque cas de l'outil
 * le lance, comme la CI, sur une copie de tests/Qualite placée dans un
 * dépôt de test nommé « app », avec son phpstan.neon et son psalm.xml.
 */
class OutilDeQualiteTest extends TestCase
{
    private string $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = sys_get_temp_dir().'/piliqi-qualite-'.getmypid().'/app';
        $fichiers = new Filesystem;
        $fichiers->deleteDirectory(dirname($this->depot));
        $fichiers->ensureDirectoryExists($this->depot.'/tests/Qualite');
        $this->depot = (string) realpath($this->depot);

        foreach (['qualite.php', 'parties.php'] as $outil) {
            $fichiers->copy(__DIR__.'/../../Qualite/'.$outil, $this->depot.'/tests/Qualite/'.$outil);
        }

        $this->ecrire('tests/Qualite/seuils.json', json_encode([
            'analyse_statique' => ['niveau' => 6],
            'couverture' => ['calculs' => 100, 'droits_et_isolation' => 50],
            'mutation' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(dirname($this->depot));

        parent::tearDown();
    }

    public function test_un_depot_monte_sous_app_range_chaque_fichier_dans_sa_partie(): void
    {
        $clover = $this->clover([
            $this->depot.'/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3, 11 => 1],
            $this->depot.'/app/Policies/FacturePolicy.php' => [20 => 2, 21 => 0],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(0, $code, $sortie);
        $this->assertStringContainsString('| calculs | 2 | 2 | 100.0 % | 100.0 % |', $sortie);
        $this->assertStringContainsString('| droits_et_isolation | 2 | 1 | 50.0 % | 50.0 % |', $sortie);
        $this->assertStringNotContainsString('ecrans_et_socle', $sortie);
    }

    public function test_une_partie_qui_a_un_seuil_sans_aucune_ligne_mesuree_fait_echouer_le_controle(): void
    {
        $clover = $this->clover([
            $this->depot.'/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('| droits_et_isolation | 0 | 0 | non mesurée | 50.0 % |', $sortie);
        $this->assertStringContainsString('non mesurée', $sortie);
    }

    public function test_un_rapport_venu_d_un_autre_depot_est_refuse(): void
    {
        $clover = $this->clover([
            '/ailleurs/app/Domain/Facturation/Services/CalculateurLigne.php' => [10 => 3],
        ]);

        [$code, $sortie] = $this->lancer('couverture', $clover);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Fichier hors du dépôt', $sortie);
    }

    public function test_le_nouveau_code_d_un_depot_monte_sous_app_retrouve_ses_lignes_changees(): void
    {
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\nfunction a(): int\n{\n    return 1;\n}\n");
        $this->git('init', '--quiet');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Base');
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\nfunction a(): int\n{\n    return 2;\n}\n");
        $clover = $this->clover([$this->depot.'/app/Domain/Exemple.php' => [5 => 0]]);

        [$code, $sortie] = $this->lancer('nouveau-code', $clover, 'HEAD');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('app/Domain/Exemple.php:5', $sortie);
    }

    /**
     * La CI donne à Infection, comme chemins, les fichiers des parties
     * sensibles que l'envoi ajoute, modifie ou renomme, pour qu'il mute
     * chacun en entier (avec un filtre de git, il ne muterait que leurs
     * lignes changées) : chacun nommé depuis la racine du dépôt, jamais un
     * fichier d'une autre partie ni un fichier retiré.
     */
    public function test_les_fichiers_sensibles_changes_se_nomment_depuis_la_racine_du_depot(): void
    {
        foreach (['app/Domain/Facturation/Services/CalculateurLigne.php', 'app/Policies/FacturePolicy.php', 'app/Policies/DevisPolicy.php', 'app/Domain/Exemple.php'] as $fichier) {
            $this->ecrire($fichier, "<?php\n\n// avant l'envoi\n");
        }
        $this->git('init', '--quiet');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Base');
        $this->ecrire('app/Domain/Facturation/Services/CalculateurLigne.php', "<?php\n\n// modifié\n");
        $this->ecrire('app/Domain/Exemple.php', "<?php\n\n// modifié\n");
        $this->ecrire('app/Models/User.php', "<?php\n\n// ajouté\n");
        $this->git('mv', 'app/Policies/FacturePolicy.php', 'app/Policies/FacturesPolicy.php');
        $this->git('rm', '--quiet', 'app/Policies/DevisPolicy.php');
        $this->git('add', '.');
        $this->git('-c', 'user.name=PILIQI', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'Envoi');

        [$code, $sortie] = $this->lancer('sensibles-changes', 'HEAD~1');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame("app/Domain/Facturation/Services/CalculateurLigne.php\napp/Models/User.php\napp/Policies/FacturesPolicy.php\n", $sortie);
    }

    /**
     * La tâche de mutation de la CI donne ces fichiers à Infection, qui les
     * lit tous comme les chemins à muter : aucun filtre de git
     * (--git-diff-filter, --git-diff-lines), avec lequel il ne muterait que
     * leurs lignes changées, et aucun chemin pris pour la valeur d'une option
     * qui en accepte une (--ignore-msi-with-no-mutations avalait le premier
     * quand les chemins le suivaient sans « -- »).
     */
    public function test_la_ci_donne_a_infection_les_chemins_des_fichiers_sensibles_changes(): void
    {
        $ci = Yaml::parseFile(__DIR__.'/../../../.github/workflows/tests.yml');
        $etape = array_values(array_filter($ci['jobs']['couverture']['steps'], fn (array $etape): bool => ($etape['name'] ?? '') === 'Tests de mutation des parties sensibles changées'))[0];
        preg_match('#vendor/bin/infection (.+)$#m', $etape['run'], $commande);
        $fichiers = ['app/Domain/Facturation/Services/CalculateurLigne.php', 'app/Models/User.php', 'app/Policies/FacturePolicy.php'];

        $arguments = new StringInput(str_replace('$fichiers', implode(' ', $fichiers), $commande[1]));
        $arguments->bind((new RunCommand)->getDefinition());

        $this->assertStringContainsString('fichiers=$(php tests/Qualite/qualite.php sensibles-changes "$BASE")', $etape['run']);
        $this->assertSame($fichiers, $arguments->getArgument('paths'));
        $this->assertNull($arguments->getOption('git-diff-filter'));
        $this->assertFalse($arguments->getOption('git-diff-lines'));
    }

    public function test_le_temoin_de_l_analyse_des_flux_exige_exactement_les_chemins_qu_il_annonce(): void
    {
        $this->ecrire('tests/Qualite/Psalm/Temoin/Temoin.php', "<?php\n\necho \$x; // attendu : TaintedHtml, TaintedTextWithQuotes\nDB::select(\$y); // attendu : TaintedSql\n");
        $chemin = fn (int $ligne, string $type): array => ['file_name' => $this->depot.'/tests/Qualite/Psalm/Temoin/Temoin.php', 'line_from' => $ligne, 'type' => $type, 'message' => '', 'is_security' => true];
        $tous = [$chemin(3, 'TaintedHtml'), $chemin(3, 'TaintedTextWithQuotes'), $chemin(4, 'TaintedSql')];

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode($tous, JSON_THROW_ON_ERROR)));
        $this->assertSame(0, $code, $sortie);

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode(array_slice($tous, 0, 2), JSON_THROW_ON_ERROR)));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('chemin non vu : tests/Qualite/Psalm/Temoin/Temoin.php:4 TaintedSql', $sortie);

        [$code, $sortie] = $this->lancer('flux-temoin', $this->ecrire('psalm-temoin.json', json_encode([...$tous, $chemin(2, 'TaintedSql')], JSON_THROW_ON_ERROR)));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('chemin en trop : tests/Qualite/Psalm/Temoin/Temoin.php:2 TaintedSql', $sortie);
    }

    public function test_les_seuils_sans_leur_ancien_fichier_ecrivent_l_usage_et_sortent_avec_le_code_2(): void
    {
        [$code, $sortie] = $this->lancer('seuils');

        $this->assertSame(2, $code, $sortie);
        $this->assertStringContainsString('php tests/Qualite/qualite.php seuils <ancien seuils.json>', $sortie);
    }

    /**
     * phpstan.neon règle l'analyse exactement comme seuils.json l'enregistre :
     * rien ne baisse, que l'ancien seuils.json enregistre les mêmes réglages
     * ou qu'il n'en enregistre aucun (une clé absente ne compte pas).
     */
    public function test_une_analyse_reglee_comme_seuils_json_l_enregistre_est_acceptee(): void
    {
        $this->depotAnalyse();

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());
        $this->assertSame(0, $code, $sortie);

        [$code, $sortie] = $this->lancerLesSeuils(['analyse_statique' => ['niveau' => 6], 'couverture' => [], 'mutation' => []]);
        $this->assertSame(0, $code, $sortie);
        $this->assertStringContainsString('Aucun seuil ne baisse', $sortie);
    }

    public function test_un_niveau_qui_baisse_est_refuse(): void
    {
        $this->depotAnalyse(niveau: 6);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString("niveau de l'analyse statique : 7 → 6", $sortie);
    }

    /**
     * Les paramètres effectifs que PHPStan lit dans phpstan.neon doivent
     * être ceux que seuils.json enregistre : un niveau, un chemin, une
     * exclusion ou une option qui diffère est refusé.
     */
    public function test_un_phpstan_neon_qui_ne_regle_pas_l_analyse_comme_seuils_json_est_refuse(): void
    {
        $this->depotAnalyse();
        $this->ecrireLaConfiguration(niveau: 8, chemins: ['app'], exclusions: [], options: "    parseModelCastsMethod: false\n");

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('phpstan.neon analyse au niveau 8, seuils.json enregistre le niveau 7', $sortie);
        $this->assertStringContainsString('chemins : phpstan.neon donne ["app"], seuils.json enregistre ["app","tests/Qualite"]', $sortie);
        $this->assertStringContainsString('exclusions : phpstan.neon donne [], seuils.json enregistre ["tests/Qualite/Psalm"]', $sortie);
        $this->assertStringContainsString('option parseModelCastsMethod : phpstan.neon donne false, seuils.json enregistre true', $sortie);
    }

    public function test_un_chemin_qui_n_est_plus_analyse_est_refuse(): void
    {
        $this->depotAnalyse(chemins: ['app']);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString("le chemin tests/Qualite n'est plus analysé", $sortie);
    }

    public function test_une_option_qui_disparait_ou_change_de_valeur_est_refusee(): void
    {
        $this->depotAnalyse(options: []);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(options: ['parseModelCastsMethod' => true]));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString("l'option parseModelCastsMethod disparaît", $sortie);

        $this->depotAnalyse();
        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(options: ['parseModelCastsMethod' => false]));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString("l'option parseModelCastsMethod change de valeur : false → true", $sortie);

        $this->depotAnalyse(options: ['reportUnsafeArrayStringKeyCasting' => 'detect']);
        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(options: ['reportUnsafeArrayStringKeyCasting' => 'prevent']));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString("l'option reportUnsafeArrayStringKeyCasting change de valeur : \"prevent\" → \"detect\"", $sortie);
    }

    /**
     * Une exclusion, un stub ou une règle désactivée que l'ancien seuils.json
     * n'enregistrait pas est refusé, même permis.
     */
    public function test_une_exclusion_un_stub_ou_une_regle_desactivee_qui_apparait_est_refuse(): void
    {
        $this->depotAnalyse(exclusions: ['tests/Qualite/Psalm', 'tests/Qualite/Temoins'], regles: ['strictRules.dynamicCallOnStaticMethod']);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(stubs: [], regles: []));

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('nouvelle exclusion : tests/Qualite/Temoins', $sortie);
        $this->assertStringContainsString('nouveau stub : tests/Qualite/macros.stub', $sortie);
        $this->assertStringContainsString('nouvelle règle désactivée : strictRules.dynamicCallOnStaticMethod', $sortie);
    }

    /**
     * Seules les exclusions, les options (à leur valeur stricte), les stubs
     * et la règle désactivée du cahier s'enregistrent.
     */
    public function test_une_exclusion_une_option_un_stub_ou_une_regle_hors_du_cahier_est_refuse(): void
    {
        $this->depotAnalyse(exclusions: ['tests/Qualite/Psalm', 'app'], options: ['parseModelCastsMethod' => true, 'checkUninitializedProperties' => false, 'inferPrivatePropertyTypeFromConstructor' => true], stubs: ['tests/Qualite/macros.stub', 'tests/Qualite/autre.stub'], regles: ['noModelMake']);

        [$code, $sortie] = $this->lancerLesSeuils(['couverture' => [], 'mutation' => []]);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('exclusion non permise : app', $sortie);
        $this->assertStringContainsString('option checkUninitializedProperties : false n\'est pas sa valeur stricte (true)', $sortie);
        $this->assertStringContainsString('option non permise : inferPrivatePropertyTypeFromConstructor', $sortie);
        $this->assertStringContainsString('stub non permis : tests/Qualite/autre.stub', $sortie);
        $this->assertStringContainsString('règle qui ne se désactive pas : noModelMake', $sortie);
    }

    /**
     * Un contrôle que le niveau ou un ensemble de règles active, coupé dans
     * phpstan.neon sans que seuils.json l'enregistre, est refusé : celui du
     * niveau 6 sur les types manquants, une règle de Larastan.
     */
    public function test_un_controle_relache_dans_phpstan_neon_est_refuse(): void
    {
        $this->depotAnalyse(neon: "    checkMissingTypehints: false\n    noModelMake: false\n");

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('checkMissingTypehints vaut false au lieu de true', $sortie);
        $this->assertStringContainsString('noModelMake vaut false au lieu de true', $sortie);
    }

    /**
     * phpstan.neon n'inclut que l'extension de Larastan : un autre fichier
     * inclus, ici un fichier du dépôt, est refusé, et ce qu'il relâche reste
     * vu comme s'il était écrit dans phpstan.neon (il n'entre pas dans la
     * référence) : un contrôle du niveau coupé, des stubs ajoutés, dans le
     * dépôt ou hors de lui, une classe aux propriétés libres.
     */
    public function test_un_fichier_inclus_autre_que_l_extension_de_larastan_est_refuse_avec_chaque_relachement(): void
    {
        $horsDuDepot = dirname($this->depot).'/hors-du-depot.stub';
        file_put_contents($horsDuDepot, "<?php\n");
        $this->ecrire('tests/Qualite/relache.neon', "parameters:\n    checkNullables: false\n    stubFiles:\n        - autre.stub\n        - {$horsDuDepot}\n    universalObjectCratesClasses:\n        - App\\Models\\User\n");
        $this->depotAnalyse(niveau: 8, inclusions: ['tests/Qualite/relache.neon']);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(niveau: 8));

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('fichier inclus non permis : tests/Qualite/relache.neon', $sortie);
        $this->assertStringContainsString('checkNullables vaut false au lieu de true', $sortie);
        $this->assertStringContainsString('stubs : phpstan.neon donne ["'.$horsDuDepot.'","tests/Qualite/autre.stub","tests/Qualite/macros.stub"], seuils.json enregistre ["tests/Qualite/macros.stub"]', $sortie);
        $this->assertStringContainsString('universalObjectCratesClasses ajoute App\\Models\\User', $sortie);
    }

    /**
     * Les stubs que charge phpstan.neon sont ceux que seuils.json
     * enregistre, ni moins ni plus, même quand l'ancien seuils.json dit la
     * même chose que le nouveau.
     */
    public function test_un_stub_que_phpstan_neon_charge_sans_que_seuils_json_l_enregistre_ou_l_inverse_est_refuse(): void
    {
        $this->depotAnalyse();
        $this->ecrireLaConfiguration(stubs: []);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('stubs : phpstan.neon donne [], seuils.json enregistre ["tests/Qualite/macros.stub"]', $sortie);

        $this->depotAnalyse(stubs: []);
        $this->ecrireLaConfiguration();

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(stubs: []));
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('stubs : phpstan.neon donne ["tests/Qualite/macros.stub"], seuils.json enregistre []', $sortie);
    }

    /**
     * Une règle que seuils.json enregistre désactivée l'est aussi dans
     * phpstan.neon, même quand l'ancien seuils.json l'enregistrait déjà.
     */
    public function test_une_regle_que_seuils_json_enregistre_desactivee_sans_que_phpstan_neon_la_desactive_est_refusee(): void
    {
        $this->depotAnalyse(regles: ['strictRules.dynamicCallOnStaticMethod']);

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse(regles: ['strictRules.dynamicCallOnStaticMethod']));

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('règle strictRules.dynamicCallOnStaticMethod : seuils.json l\'enregistre désactivée, phpstan.neon donne null', $sortie);
    }

    public function test_une_erreur_ignoree_ou_une_classe_aux_proprietes_libres_est_refusee(): void
    {
        $this->depotAnalyse(neon: "    ignoreErrors:\n        - '#.*#'\n    universalObjectCratesClasses:\n        - App\\Models\\User\n    reportUnmatchedIgnoredErrors: false\n");

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('ignoreErrors cache des erreurs', $sortie);
        $this->assertStringContainsString('universalObjectCratesClasses ajoute App\\Models\\User', $sortie);
        $this->assertStringContainsString('reportUnmatchedIgnoredErrors ne passe jamais à false', $sortie);
    }

    public function test_les_types_des_phpdoc_restent_tenus_pour_certains(): void
    {
        $this->depotAnalyse(neon: "    treatPhpDocTypesAsCertain: false\n");

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('treatPhpDocTypesAsCertain ne passe jamais à false', $sortie);
    }

    /**
     * Un commentaire d'un fichier analysé qui fait taire PHPStan est refusé,
     * avec son fichier et sa ligne ; une chaîne qui le cite, et un fichier
     * exclu, ne le sont pas.
     */
    public function test_un_commentaire_qui_fait_taire_phpstan_est_refuse(): void
    {
        $consigne = '@phpstan-'.'ignore';
        $this->depotAnalyse();
        $this->ecrire('app/Cite.php', "<?php\n\n\$texte = '{$consigne}';\n");
        $this->ecrire('tests/Qualite/Psalm/Exclu.php', "<?php\n\n// {$consigne}\n");

        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());
        $this->assertSame(0, $code, $sortie);

        $this->ecrire('app/Tait.php', "<?php\n\n/** {$consigne}-next-line */\n\$a = 1;\n");
        [$code, $sortie] = $this->lancerLesSeuils($this->seuilsDeLAnalyse());
        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('commentaire qui fait taire PHPStan : app/Tait.php:3', $sortie);
        $this->assertStringNotContainsString('app/Cite.php', $sortie);
        $this->assertStringNotContainsString('Exclu.php', $sortie);
    }

    public function test_un_seuil_de_couverture_ou_de_mutation_qui_baisse_est_refuse(): void
    {
        $this->depotAnalyse();
        $this->ecrire('tests/Qualite/seuils.json', json_encode([
            'analyse_statique' => $this->seuilsDeLAnalyse()['analyse_statique'],
            'couverture' => ['calculs' => 99.9],
            'mutation' => [],
        ], JSON_THROW_ON_ERROR));

        [$code, $sortie] = $this->lancerLesSeuils([...$this->seuilsDeLAnalyse(), 'couverture' => ['calculs' => 100], 'mutation' => ['droits_et_isolation' => 99.9]]);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('couverture de calculs : 100 → 99.9', $sortie);
        $this->assertStringContainsString('mutation de droits_et_isolation : 99.9 → retiré', $sortie);
    }

    /**
     * Le dépôt de test réglé pour l'analyse statique : son phpstan.neon,
     * son psalm.xml, le stub des macros et les dossiers analysés, et son
     * seuils.json qui enregistre ce réglage, sauf ce que le cas y change
     * (le phpstan.neon seul : ses paramètres en plus, les fichiers qu'il
     * inclut en plus de l'extension de Larastan).
     *
     * @param  list<string>  $chemins
     * @param  list<string>  $exclusions
     * @param  array<string, bool|string>  $options
     * @param  list<string>  $stubs
     * @param  list<string>  $regles
     * @param  list<string>  $inclusions
     */
    private function depotAnalyse(int $niveau = 7, array $chemins = ['app', 'tests/Qualite'], array $exclusions = ['tests/Qualite/Psalm'], array $options = ['parseModelCastsMethod' => true], array $stubs = ['tests/Qualite/macros.stub'], array $regles = [], string $neon = '', array $inclusions = []): void
    {
        foreach ([...$chemins, ...$exclusions] as $dossier) {
            (new Filesystem)->ensureDirectoryExists($this->depot.'/'.$dossier);
        }

        foreach (['tests/Qualite/macros.stub', 'psalm.xml'] as $fichier) {
            (new Filesystem)->copy(__DIR__.'/../../../'.$fichier, $this->depot.'/'.$fichier);
        }

        $this->ecrire('tests/Qualite/autre.stub', "<?php\n");
        $this->ecrireLaConfiguration($niveau, $chemins, $exclusions, implode('', array_map(
            fn (string $option, bool|string $valeur): string => "    {$option}: ".(is_bool($valeur) ? ($valeur ? 'true' : 'false') : $valeur)."\n",
            array_keys($options),
            $options,
        )).$neon, $stubs, $inclusions);
        $this->ecrire('tests/Qualite/seuils.json', json_encode($this->seuilsDeLAnalyse(options: $options, regles: $regles, stubs: $stubs, chemins: $chemins, exclusions: $exclusions, niveau: $niveau), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Le phpstan.neon du dépôt de test : il inclut l'extension de Larastan
     * installée avec le PHPStan qui l'analyse (celui du dépôt), puis ces
     * fichiers du dépôt de test.
     *
     * @param  list<string>  $chemins
     * @param  list<string>  $exclusions
     * @param  list<string>  $stubs
     * @param  list<string>  $inclusions
     */
    private function ecrireLaConfiguration(int $niveau = 7, array $chemins = ['app', 'tests/Qualite'], array $exclusions = ['tests/Qualite/Psalm'], string $options = "    parseModelCastsMethod: true\n", array $stubs = ['tests/Qualite/macros.stub'], array $inclusions = []): void
    {
        $liste = fn (string $cle, array $valeurs): string => $valeurs === [] ? '' : "    {$cle}:\n".implode('', array_map(fn (string $valeur): string => "        - {$valeur}\n", $valeurs));
        $inclus = [(string) realpath(__DIR__.'/../../../vendor/larastan/larastan/extension.neon'), ...$inclusions];

        $this->ecrire('phpstan.neon', "includes:\n".implode('', array_map(fn (string $fichier): string => "    - {$fichier}\n", $inclus))."\nparameters:\n"
            .$liste('paths', $chemins).$liste('excludePaths', $exclusions)."    level: {$niveau}\n".$liste('stubFiles', $stubs).$options);
    }

    /**
     * Les seuils qu'enregistre le dépôt de test : ceux de la couverture et
     * de la mutation, et le réglage de l'analyse statique.
     *
     * @param  list<string>  $chemins
     * @param  list<string>  $exclusions
     * @param  array<string, bool|string>  $options
     * @param  list<string>  $stubs
     * @param  list<string>  $regles
     * @return array<string, mixed>
     */
    private function seuilsDeLAnalyse(int $niveau = 7, array $chemins = ['app', 'tests/Qualite'], array $exclusions = ['tests/Qualite/Psalm'], array $options = ['parseModelCastsMethod' => true], array $stubs = ['tests/Qualite/macros.stub'], array $regles = []): array
    {
        return [
            'analyse_statique' => ['niveau' => $niveau, 'chemins' => $chemins, 'exclusions' => $exclusions, 'stubs' => $stubs, 'options' => (object) $options, 'regles_desactivees' => $regles],
            'couverture' => [],
            'mutation' => [],
        ];
    }

    /**
     * Lance le contrôle des seuils du dépôt de test contre cet ancien
     * seuils.json.
     *
     * @param  array<string, mixed>  $ancien
     * @return array{0: int, 1: string}
     */
    private function lancerLesSeuils(array $ancien): array
    {
        return $this->lancer('seuils', $this->ecrire('ancien.json', json_encode($ancien, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * Un rapport Clover de ces fichiers : par chemin, le nombre de tests
     * qui exécutent chaque ligne.
     *
     * @param  array<string, array<int, int>>  $fichiers
     */
    private function clover(array $fichiers): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project>';

        foreach ($fichiers as $nom => $lignes) {
            $xml .= '<file name="'.htmlspecialchars($nom).'">';

            foreach ($lignes as $numero => $nombre) {
                $xml .= '<line num="'.$numero.'" type="stmt" count="'.$nombre.'"/>';
            }

            $xml .= '</file>';
        }

        return $this->ecrire('clover.xml', $xml.'</project></coverage>');
    }

    private function ecrire(string $chemin, string $contenu): string
    {
        (new Filesystem)->ensureDirectoryExists(dirname($this->depot.'/'.$chemin));
        file_put_contents($this->depot.'/'.$chemin, $contenu);

        return $this->depot.'/'.$chemin;
    }

    private function git(string ...$arguments): void
    {
        $git = new Process(['git', '-C', $this->depot, ...$arguments]);
        $git->mustRun();
    }

    /**
     * @return array{0: int, 1: string} le code de sortie et la sortie de l'outil
     */
    private function lancer(string ...$arguments): array
    {
        $outil = new Process([PHP_BINARY, $this->depot.'/tests/Qualite/qualite.php', ...$arguments], $this->depot, ['QUALITE_PHPSTAN' => realpath(__DIR__.'/../../../vendor/bin/phpstan')]);
        $outil->setTimeout(120);
        $outil->run();

        return [(int) $outil->getExitCode(), $outil->getOutput().$outil->getErrorOutput()];
    }
}
