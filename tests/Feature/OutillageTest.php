<?php

namespace Tests\Feature;

use App\Domain\Contacts\Models\Client;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Societe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\SkillComposer;
use Laravel\Boost\Support\Config;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Aucun paquet inutilisé et aucun outil Node hors Playwright, qui ne sert
 * qu'aux tests en navigateur (décisions de l'éditeur) :
 * Filament Shield et Sanctum, retirés, n'exposent plus ni route ni
 * commande, et rien de ce que lisent les agents ne renvoie à un outil
 * absent du projet. PostgreSQL est le seul moteur de base de données, et
 * la base trie les textes dans l'ordre binaire. PHP 8.5 et PostgreSQL 18
 * sont les seules versions visées, de composer.json à la CI. Les aides
 * des tests lisent les PDF de dompdf sans dépendre du hasard de leur
 * contenu, aucun test ne dépend du hasard de Faker ni de celui de
 * Str::random(), et aucun commentaire n'empêche un test de tourner.
 */
class OutillageTest extends TestCase
{
    /**
     * Les pages d'un PDF se lisent même quand un flux compressé finit par
     * un retour chariot : dompdf écrit « stream », un saut de ligne, le
     * flux, un saut de ligne et « endstream », avec la longueur exacte du
     * flux (/Length) ; une lecture jusqu'au mot « endstream » prenait ce
     * dernier octet pour la fin de la ligne et ne décompressait plus le
     * flux (voir pagesDuPdf()).
     */
    public function test_les_pages_d_un_pdf_se_lisent_quand_un_flux_compresse_finit_par_un_retour_chariot(): void
    {
        $nombreDePages = 0;
        do {
            $texte = 'Page 1 sur '.++$nombreDePages;
            $flux = gzcompress('BT 40.000 30.000 Td /F1 9.0 Tf  [('.mb_convert_encoding($texte, 'UTF-16BE', 'UTF-8').')] TJ ET');
        } while (! str_ends_with($flux, "\r"));
        $pdf = "%PDF-1.7\n"
            ."1 0 obj\n<< /Type /Font\n/Subtype /Type0\n/DescendantFonts [2 0 R] >>\nendobj\n"
            ."2 0 obj\n<< /Type /Font\n/Subtype /CIDFontType2\n/DW 600 >>\nendobj\n"
            ."3 0 obj\n<< /Font << /F1 1 0 R >> >>\nendobj\n"
            ."4 0 obj\n<< /Type /Page\n/MediaBox [0.000 0.000 595.280 841.890]\n/Resources 3 0 R\n/Contents 5 0 R\n>>\nendobj\n"
            ."5 0 obj\n<< /Filter /FlateDecode\n/Length ".strlen($flux)." >>\nstream\n".$flux."\nendstream\nendobj\n";

        $pages = $this->pagesDuPdf($pdf);

        $this->assertSame('0.000 0.000 595.280 841.890', $pages[0]['format']);
        $this->assertSame([$texte], array_column($pages[0]['textes'], 'texte'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deuxJeuxDeDonnees(): array
    {
        return ['premier jeu' => ['premier'], 'second jeu' => ['second']];
    }

    /**
     * Faker est semé au début de chaque test par la graine tirée du nom de
     * sa classe, de sa méthode et de son jeu de données (voir
     * TestCase::setUp()) : la rejouer redonne les valeurs que le test a
     * tirées, par fake() comme par une fabrique, si bien qu'un test tire
     * les mêmes valeurs à chaque exécution, quel que soit l'ordre des tests
     * ou le processus qui le fait tourner. Le générateur d'un test
     * précédent, que le ramasse-miettes peut détruire à tout moment, et qui
     * ressème alors au hasard celui de PHP (Faker\Generator::__destruct()),
     * est déjà détruit quand le test commence : le ramasse-miettes lancé
     * ici n'en trouve plus.
     */
    #[DataProvider('deuxJeuxDeDonnees')]
    public function test_faker_est_seme_par_test_et_redonne_les_memes_valeurs_a_chaque_execution(string $jeu): void
    {
        $tirer = fn (): array => [fake()->name(), Client::factory()->make()->only(['nom', 'telephone', 'email']), fake()->numerify('#######')];
        gc_collect_cycles();
        $valeursDuTest = $tirer();

        fake()->seed(crc32(self::class.'::test_faker_est_seme_par_test_et_redonne_les_memes_valeurs_a_chaque_execution with data set "'.$jeu.' jeu"'));

        $this->assertSame($valeursDuTest, $tirer());
    }

    /**
     * Str::random() est semé au début de chaque test par la même graine
     * que Faker (voir TestCase::setUp()) : la rejouer redonne les chaînes
     * que le test a tirées, dont l'identifiant d'un composant Livewire,
     * qui revient partout dans son HTML. Une assertion qui vérifie
     * l'absence d'un mot court (« Luc ») dans ce HTML ne dépend donc plus
     * du hasard de cet identifiant.
     */
    #[DataProvider('deuxJeuxDeDonnees')]
    public function test_str_random_est_seme_par_test_et_redonne_les_memes_chaines_a_chaque_execution(string $jeu): void
    {
        $tirer = fn (): array => [
            Str::random(),
            Livewire::test(new class extends Component
            {
                public function render(): string
                {
                    return '<div>Composant de test</div>';
                }
            })->id(),
        ];
        $chainesDuTest = $tirer();

        Str::createRandomStringsUsing(self::chainesAleatoiresSemees(crc32(self::class.'::test_str_random_est_seme_par_test_et_redonne_les_memes_chaines_a_chaque_execution with data set "'.$jeu.' jeu"')));

        $this->assertSame($chainesDuTest, $tirer());
    }

    public function test_aucune_route_d_un_paquet_retire_n_est_exposee(): void
    {
        $reponse = $this->get('/sanctum/csrf-cookie');

        $reponse->assertNotFound();
    }

    public function test_aucune_commande_d_un_paquet_retire_n_est_proposee(): void
    {
        $commandes = array_keys(Artisan::all());

        $commandesDesPaquetsRetires = array_values(array_filter(
            $commandes,
            fn (string $commande): bool => str_starts_with($commande, 'shield:') || str_starts_with($commande, 'sanctum:'),
        ));

        $this->assertSame([], $commandesDesPaquetsRetires);
    }

    /**
     * PostgreSQL est le seul moteur de PILIQI, en production, en
     * développement et pour les tests (décision de l'éditeur du
     * 08/11/2026) : config/database.php ne déclare que lui et le choisit
     * quand DB_CONNECTION manque, et les tests tournent sur lui. Chaque
     * autre connexion de la configuration de l'application en marche,
     * celles que Laravel y ajoute (sqlite, mysql, mariadb, sqlsrv), est
     * refusée dès son ouverture, en disant quoi régler, comme une
     * connexion PostgreSQL dont l'adresse DB_URL nomme un autre pilote.
     */
    public function test_postgresql_est_le_seul_moteur_de_la_base(): void
    {
        $configuration = require config_path('database.php');
        $defautSansDbConnection = Process::path(base_path())->env(['DB_CONNECTION' => false])->timeout(60)
            ->run([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo (require "config/database.php")["default"];']);
        config(['database.connections.adresse_sqlite' => [...config('database.connections.pgsql'), 'url' => 'sqlite:///'.storage_path('framework/testing/adresse.sqlite')]]);
        $refus = [];

        foreach (array_keys(config('database.connections')) as $connexion) {
            if ($connexion !== 'pgsql') {
                $refus[$connexion] = $this->refusDeLOuverture($connexion);
            }
        }

        $this->assertSame(['pgsql'], array_keys($configuration['connections']));
        $this->assertSame('pgsql', $defautSansDbConnection->output());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame([
            'sqlite' => self::refusDuMoteur('sqlite', 'sqlite'),
            'mysql' => self::refusDuMoteur('mysql', 'mysql'),
            'mariadb' => self::refusDuMoteur('mariadb', 'mariadb'),
            'sqlsrv' => self::refusDuMoteur('sqlsrv', 'sqlsrv'),
            'adresse_sqlite' => self::refusDuMoteur('adresse_sqlite', 'sqlite'),
        ], $refus);
    }

    /**
     * Le poste d'une installation d'avant PostgreSQL, dont le .env dit
     * encore DB_CONNECTION=sqlite, avec une base SQLite déjà migrée : ni
     * les migrations, ni le diagnostic, deux vrais processus artisan, ni
     * une recherche de l'application ne l'ouvrent. Chacun s'arrête en
     * disant quoi régler, sans créer ni toucher le fichier de la base :
     * aucune table, pas même celle des migrations, et plus d'erreur SQL
     * brute (« no such function », « syntax error »). Les processus ont un
     * terminal assez large pour que la console ne coupe pas le message.
     */
    public function test_une_ancienne_base_sqlite_ne_s_ouvre_ni_pour_les_migrations_ni_pour_le_diagnostic_ni_pour_l_application(): void
    {
        $dossier = storage_path('framework/testing/ancienne-base-'.Str::random(8));
        File::ensureDirectoryExists($dossier);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dossier));
        $migree = "{$dossier}/database.sqlite";
        $absente = "{$dossier}/absente.sqlite";
        File::put($migree, "SQLite format 3\0 base migrée avant PostgreSQL");
        $artisan = static fn (string $base, string ...$commande) => Process::path(base_path())->env(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $base, 'COLUMNS' => '1000'])->timeout(60)->run([PHP_BINARY, 'artisan', ...$commande]);

        $migration = $artisan($migree, 'migrate', '--force');
        $migrationSansFichier = $artisan($absente, 'migrate', '--force');
        $diagnostic = $artisan($migree, 'caisse:diagnostiquer');
        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.ancienne_base' => ['driver' => 'sqlite', 'database' => $migree, 'prefix' => '']]);
        DB::setDefaultConnection('ancienne_base');

        try {
            $this->assertThrows(fn () => RechercheSansAccents::appliquer(Societe::query(), 'creme', ['raison_sociale'])->get(), RuntimeException::class, self::refusDuMoteur('ancienne_base', 'sqlite'));
        } finally {
            DB::setDefaultConnection($connexionDuTest);
            DB::purge('ancienne_base');
        }
        $this->assertSame(1, $migration->exitCode());
        $this->assertStringContainsString(self::refusDuMoteur('sqlite', 'sqlite'), $migration->errorOutput().$migration->output());
        $this->assertSame(1, $migrationSansFichier->exitCode());
        $this->assertStringContainsString(self::refusDuMoteur('sqlite', 'sqlite'), $migrationSansFichier->errorOutput().$migrationSansFichier->output());
        $this->assertSame("SQLite format 3\0 base migrée avant PostgreSQL", File::get($migree));
        $this->assertFileDoesNotExist($absente);
        $this->assertSame(1, $diagnostic->exitCode());
        $this->assertMatchesRegularExpression('/\| Fuseau par défaut de la base +\| ÉCHEC +\| '.preg_quote(self::refusDuMoteur('sqlite', 'sqlite'), '/').' +\|/u', $diagnostic->output());
    }

    /**
     * Le message de l'exception levée à l'ouverture de cette connexion,
     * qui est alors oubliée.
     */
    private function refusDeLOuverture(string $connexion): string
    {
        try {
            DB::connection($connexion);
        } catch (RuntimeException $refus) {
            return $refus->getMessage();
        } finally {
            DB::purge($connexion);
        }

        return 'ouverte';
    }

    /**
     * Le refus d'une connexion qui n'est pas PostgreSQL : ce qu'il faut
     * régler, sans erreur SQL.
     */
    private static function refusDuMoteur(string $connexion, string $pilote): string
    {
        return "PILIQI ne fonctionne qu'avec PostgreSQL 18 : la connexion « {$connexion} » utilise le pilote « {$pilote} ». "
            .'Réglez DB_CONNECTION=pgsql et les autres variables DB_ de .env (voir le README), videz la configuration en cache (php artisan config:clear), puis recommencez.';
    }

    /**
     * Le contenu d'un fichier JSON du dépôt (composer.json, composer.lock,
     * tools/psalm/composer.json).
     *
     * @return array<string, mixed>
     */
    private static function contenuJson(string $chemin): array
    {
        $contenu = json_decode((string) file_get_contents(base_path($chemin)), true, flags: JSON_THROW_ON_ERROR);

        return is_array($contenu) ? $contenu : [];
    }

    /**
     * Ce qui, dans les tâches de ce flux de la CI, prendrait une autre
     * version de PHP ou de PostgreSQL que PHP 8.5 et PostgreSQL 18 : une
     * étape setup-php qui n'installe pas PHP 8.5 (la matrice de la tâche,
     * quand l'étape la nomme, est lue valeur par valeur), une tâche qui
     * lance PHP, Composer ou un outil du projet sans installer PHP, un
     * service ou un conteneur PostgreSQL qui n'est pas l'image postgres:18
     * (de même par la matrice).
     *
     * @param  array{jobs: array<string, array{strategy?: array{matrix?: array<string, list<int|float|string>>}, services?: array<string, array{image?: string}>, container?: string|array{image?: string}, steps?: list<array{uses?: string, run?: string, with?: array<string, int|float|string>}>}>}  $flux  un flux de la CI lu par Yaml
     * @return list<string>
     */
    private static function ecartsDeVersionDeLaCI(string $nom, array $flux): array
    {
        $ecarts = [];

        foreach ($flux['jobs'] as $tache => $definition) {
            $lieu = $nom.' › '.$tache;
            $matrice = $definition['strategy']['matrix'] ?? [];
            $etapes = $definition['steps'] ?? [];
            $resoudre = static function (string $texte, string $cle) use ($matrice): array {
                $marque = '${{ matrix.'.$cle.' }}';
                $valeurs = $matrice[$cle] ?? [];

                return str_contains($texte, $marque) && $valeurs !== []
                    ? array_map(static fn (int|float|string $valeur): string => str_replace($marque, (string) $valeur, $texte), $valeurs)
                    : [$texte];
            };
            $installations = array_filter($etapes, static fn (array $etape): bool => str_starts_with($etape['uses'] ?? '', 'shivammathur/setup-php@'));
            $lancePhp = array_filter($etapes, static fn (array $etape): bool => preg_match('/\b(php|composer|artisan)\b|vendor\/bin/', $etape['run'] ?? '') === 1);

            if ($lancePhp !== [] && $installations === []) {
                $ecarts[] = $lieu.' : lance PHP ou Composer sans installer PHP 8.5';
            }

            foreach ($installations as $etape) {
                foreach ($resoudre((string) ($etape['with']['php-version'] ?? ''), 'php') as $version) {
                    if ($version !== '8.5') {
                        $ecarts[] = $lieu.' : setup-php installe PHP « '.$version.' »';
                    }
                }
            }

            $conteneur = $definition['container'] ?? '';
            $images = [
                'service' => array_column($definition['services'] ?? [], 'image'),
                'conteneur' => [is_array($conteneur) ? ($conteneur['image'] ?? '') : $conteneur],
            ];

            foreach ($images as $genre => $lesImages) {
                foreach ($lesImages as $image) {
                    if (preg_match('/postg(res|is)/i', $image) !== 1) {
                        continue;
                    }

                    foreach ($resoudre($image, 'postgres') as $reelle) {
                        if ($reelle !== 'postgres:18') {
                            $ecarts[] = $lieu.' : le '.$genre.' PostgreSQL est « '.$reelle.' »';
                        }
                    }
                }
            }
        }

        return $ecarts;
    }

    /**
     * PostgreSQL, en collation déterministe C.UTF-8, trie les textes dans
     * l'ordre binaire : majuscules d'abord, lettres accentuées après « z »,
     * « . » avant « _ ». Un contenu signé trié en SQL (les autorisations du
     * journal des événements, voir Affectation::autorisations()) garde
     * ainsi toujours le même ordre ; une collation linguistique le
     * changerait.
     */
    public function test_la_base_trie_les_textes_dans_l_ordre_binaire(): void
    {
        $textes = DB::select("select column1 as texte from (values ('Éco'), ('facture_achat.view'), ('eco'), ('facture.view'), ('FAC-1')) as textes order by column1");

        $this->assertSame(['FAC-1', 'eco', 'facture.view', 'facture_achat.view', 'Éco'], array_column($textes, 'texte'));
    }

    /**
     * Le gabarit de Boost écrit sans condition la consigne « Frontend
     * Bundling » (npm run build, npm run dev, composer run dev) et la
     * lecture de package.json : un boost:install les remettrait dans
     * CLAUDE.md alors que le projet n'a ni outil de construction des écrans
     * ni script « dev » (son package.json ne déclare que Playwright, pour
     * les tests en navigateur).
     */
    public function test_les_consignes_des_agents_ne_renvoient_a_aucun_outil_absent(): void
    {
        $consignes = File::get(base_path('CLAUDE.md'));

        $renvoisVersUnOutilAbsent = array_values(array_filter(
            ['package.json', 'npm run', 'composer run dev'],
            fn (string $renvoi): bool => str_contains($consignes, $renvoi),
        ));

        $this->assertSame([], $renvoisVersUnOutilAbsent);
    }

    /**
     * Les skills suivis par Boost (boost.json) et ceux que lisent les agents
     * (.claude/skills) font tous partie de ceux que Boost propose pour ce
     * projet d'après ses paquets installés : un skill resté après le retrait
     * de son paquet (Fortify, Tailwind) enverrait l'agent vers un outil absent.
     */
    public function test_aucun_skill_d_un_paquet_absent_n_est_propose_aux_agents(): void
    {
        $boost = new Config;
        $configuration = new GuidelineConfig;
        $configuration->usesCloud = $boost->getCloud();
        $skillsDesPaquetsInstalles = app(SkillComposer::class)->config($configuration)->skills()->keys();

        $skillsProposesAuxAgents = collect($boost->getSkills())
            ->merge(array_map('basename', File::directories(base_path('.claude/skills'))))
            ->unique();

        $this->assertSame([], $skillsProposesAuxAgents->diff($skillsDesPaquetsInstalles)->values()->all());
    }

    /**
     * Aucun commentaire des tests n'avale une fonction : un docbloc resté
     * ouvert court jusqu'à la fermeture du suivant, et la méthode prise
     * entre les deux ne tourne plus, sans que la suite le signale, comme la
     * garde du schéma de HorodatageEnUtcTest l'a été.
     */
    public function test_aucun_commentaire_des_tests_n_avale_une_fonction(): void
    {
        $fonctionsAvalees = [];

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all($fichier->getContents()) as $jeton) {
                if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true) && preg_match('/\bfunction\s+\w+\s*\(/', $jeton[1]) === 1) {
                    $fonctionsAvalees[] = 'tests/'.$fichier->getRelativePathname().':'.$jeton[2];
                }
            }
        }

        $this->assertSame([], $fonctionsAvalees);
    }

    /**
     * Aucun raccourci dans le code (CLAUDE.md, protocole de rigueur, point 1 ;
     * directive D11) : aucun commentaire du code, des migrations, des routes,
     * de la configuration, des tests ni des vues ne laisse à la place du code
     * une marque de travail à finir, une ligne de points de suspension ni la
     * phrase qui dit que le reste ne change pas (les motifs exacts sont ceux
     * de $estUnRaccourci).
     */
    public function test_aucun_commentaire_du_code_ne_laisse_un_raccourci(): void
    {
        $raccourcis = [];
        $estUnRaccourci = static fn (string $commentaire): bool => preg_match('/\b(TODO|FIXME|XXX)\b/u', $commentaire) === 1
            || preg_match('/\breste inchangé\b|^\s*(\/\/|#|\/?\*+)\s*(\.\.\.|…)\s*(\*\/)?\s*$/miu', $commentaire) === 1;

        $this->assertTrue($estUnRaccourci('// TODO : finir le calcul'));
        $this->assertTrue($estUnRaccourci("/**\n * ...\n */"));
        $this->assertTrue($estUnRaccourci('// Le reste inchangé.'));
        $this->assertFalse($estUnRaccourci('// La requête reste inchangée quand elle est refusée.'));

        foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'] as $dossier) {
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $chemin = $dossier.'/'.$fichier->getRelativePathname();

                if ($fichier->getExtension() !== 'php' || str_starts_with($chemin, 'bootstrap/cache/') || str_starts_with($chemin, 'tests/Feature/Scratch/')) {
                    continue;
                }

                if (str_ends_with($chemin, '.blade.php')) {
                    preg_match_all('/\{\{--(.*?)--\}\}/su', $fichier->getContents(), $commentaires);

                    foreach ($commentaires[1] as $commentaire) {
                        if ($estUnRaccourci($commentaire)) {
                            $raccourcis[] = $chemin;
                        }
                    }

                    continue;
                }

                foreach (token_get_all($fichier->getContents()) as $jeton) {
                    if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true) && $estUnRaccourci($jeton[1])) {
                        $raccourcis[] = $chemin.':'.$jeton[2];
                    }
                }
            }
        }

        $this->assertSame([], $raccourcis);
    }

    /**
     * Les commentaires devenus inutiles sont supprimés (CLAUDE.md,
     * « Développement ») : aucun texte du code, des migrations ni des vues
     * ne garde une formule que la conception de la société a retirée
     * (critères de fin de sa section 14), ni un « magasin réel », que le
     * retrait du magasin de démonstration a rendu sans objet (choix 23 du
     * cahier DEMO-COPIE, D76), même coupée en fin de ligne d'un
     * commentaire, où une recherche ligne par ligne ne la voit pas.
     */
    public function test_aucun_texte_du_code_ne_garde_une_formule_retiree_meme_coupee_en_fin_de_ligne(): void
    {
        $formules = ['Super Admin', 'un seul magasin', 'magasin principal', 'écran de sélection', 'seul le propriétaire coche', 'magasin réel', 'magasins réels'];
        $enUneLigne = static fn (string $texte): string => (string) preg_replace('/(?:\s|\*|\/\/|#)+/u', ' ', $texte);
        $trouvees = [];

        $this->assertStringContainsString('un seul magasin', $enUneLigne("Il n'appartient qu'à un seul\n * magasin de la société"));
        $this->assertStringContainsString('seul le propriétaire coche', $enUneLigne("celles que seul le\n     // propriétaire coche"));
        $this->assertStringContainsString('magasins réels', $enUneLigne("les responsables des magasins\n *   réels de sa portée"));

        foreach (['app', 'database', 'resources'] as $dossier) {
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $texte = mb_strtolower($enUneLigne($fichier->getContents()));

                foreach ($formules as $formule) {
                    if (str_contains($texte, mb_strtolower($formule))) {
                        $trouvees[] = $dossier.'/'.$fichier->getRelativePathname().' : « '.$formule.' »';
                    }
                }
            }
        }

        $this->assertSame([], $trouvees);
    }

    /**
     * Aucun outil Node tant qu'aucun thème d'écran personnalisé n'existe,
     * sauf Playwright, qui ne sert qu'aux tests en navigateur (CLAUDE.md,
     * « Outillage » ; directive D51) : package.json ne déclare que lui, en
     * dépendance de développement, sans script de construction des écrans.
     */
    public function test_package_json_ne_declare_que_playwright_pour_les_tests_en_navigateur(): void
    {
        $paquet = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($paquet);
        $this->assertTrue($paquet['private'] ?? false);
        $this->assertSame(['playwright'], array_keys($paquet['devDependencies'] ?? []));
        $this->assertArrayNotHasKey('dependencies', $paquet);
        $this->assertArrayNotHasKey('scripts', $paquet);
        $this->assertFileDoesNotExist(base_path('vite.config.js'));
        $this->assertFileDoesNotExist(base_path('tailwind.config.js'));
    }

    /**
     * Le logiciel est sous licence propriétaire et déclare les extensions
     * PHP de la signature, des PDF, des archives et de PostgreSQL (CLAUDE.md,
     * « Outillage » ; décisions D13 et H3 du 09/10/2026).
     */
    public function test_composer_declare_la_licence_proprietaire_et_les_extensions_php(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($composer);
        $this->assertSame('proprietary', $composer['license'] ?? null);

        foreach (['ext-gd', 'ext-openssl', 'ext-zip', 'ext-pdo_pgsql', 'ext-sodium'] as $extension) {
            $this->assertArrayHasKey($extension, $composer['require'] ?? [], $extension.' n\'est pas déclarée dans composer.json.');
        }
    }

    /**
     * Décision de l'éditeur du 12/11/2026 : PILIQI ne vise que les dernières
     * versions, PHP 8.5 et PostgreSQL 18, en développement, en test, en CI
     * et en production. composer.json, son verrou et celui de Psalm
     * n'acceptent que PHP 8.5, la suite complète de la CI ne passe que sur
     * une seule combinaison, PHP 8.5 et PostgreSQL 18, et chaque tâche de la
     * CI, comme l'audit quotidien, installe PHP 8.5 et lance l'image
     * postgres:18 : un retour à plusieurs versions, ou à une tâche qui
     * prendrait la version par défaut du serveur d'intégration, fait échouer
     * ce test.
     */
    public function test_piliqi_ne_vise_que_php_8_5_et_postgresql_18_dans_composer_et_dans_la_ci(): void
    {
        $ci = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
        $audit = Yaml::parseFile(base_path('.github/workflows/audit-quotidien.yml'));
        $matrice = array_map(fn (array $valeurs): array => array_map('strval', $valeurs), $ci['jobs']['tests']['strategy']['matrix']);

        $this->assertSame('^8.5', data_get(self::contenuJson('composer.json'), 'require.php'));
        $this->assertSame('^8.5', data_get(self::contenuJson('composer.lock'), 'platform.php'));
        $this->assertSame('8.5.0', data_get(self::contenuJson('tools/psalm/composer.json'), 'config.platform.php'));
        $this->assertSame(['php' => ['8.5'], 'postgres' => ['18']], $matrice);
        $this->assertSame([], self::ecartsDeVersionDeLaCI('tests.yml', $ci));
        $this->assertSame([], self::ecartsDeVersionDeLaCI('audit-quotidien.yml', $audit));
    }

    /**
     * Témoins du contrôle des versions de la CI : chaque écart est reconnu
     * (une autre version de PHP, installée en clair ou par la matrice, une
     * autre image de PostgreSQL, en service, par la matrice ou en conteneur,
     * une tâche qui lance PHP ou Composer sans l'installer), et une tâche
     * en PHP 8.5 et postgres:18, qu'elle les écrive ou les prenne dans sa
     * matrice, n'en a aucun.
     */
    public function test_le_controle_des_versions_de_la_ci_reconnait_chaque_ecart(): void
    {
        $installer = fn (int|float|string $php): array => ['uses' => 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2', 'with' => ['php-version' => $php]];
        $composer = ['run' => 'composer install --no-interaction'];
        $postgres = fn (string $image): array => ['postgres' => ['image' => $image], 'redis' => ['image' => 'redis:7']];
        $tache = fn (array $reste, array ...$etapes): array => ['jobs' => ['t' => [...$reste, 'steps' => $etapes]]];
        $matrice = fn (array $php, array $postgres): array => ['strategy' => ['matrix' => ['php' => $php, 'postgres' => $postgres]]];

        $this->assertSame([], self::ecartsDeVersionDeLaCI('ci', $tache(['services' => $postgres('postgres:18')], $installer(8.5), $composer)));
        $this->assertSame([], self::ecartsDeVersionDeLaCI('ci', $tache([...$matrice([8.5], [18]), 'services' => $postgres('postgres:${{ matrix.postgres }}')], $installer('${{ matrix.php }}'), $composer)));
        $this->assertSame([], self::ecartsDeVersionDeLaCI('ci', $tache([], ['run' => 'echo "rien de PHP ici"'])));
        $this->assertSame(['ci › t : setup-php installe PHP « 8.3 »'], self::ecartsDeVersionDeLaCI('ci', $tache([], $installer(8.3), $composer)));
        $this->assertSame(['ci › t : setup-php installe PHP « 8.4 »'], self::ecartsDeVersionDeLaCI('ci', $tache($matrice([8.4, 8.5], [18]), $installer('${{ matrix.php }}'), $composer)));
        $this->assertSame(['ci › t : setup-php installe PHP « ${{ matrix.php }} »'], self::ecartsDeVersionDeLaCI('ci', $tache([], $installer('${{ matrix.php }}'), $composer)));
        $this->assertSame(['ci › t : lance PHP ou Composer sans installer PHP 8.5'], self::ecartsDeVersionDeLaCI('ci', $tache([], $composer)));
        $this->assertSame(['ci › t : lance PHP ou Composer sans installer PHP 8.5'], self::ecartsDeVersionDeLaCI('ci', $tache([], ['run' => 'vendor/bin/pint --test'])));
        $this->assertSame(['ci › t : le service PostgreSQL est « postgres:17 »'], self::ecartsDeVersionDeLaCI('ci', $tache(['services' => $postgres('postgres:17')], $installer(8.5), $composer)));
        $this->assertSame(['ci › t : le service PostgreSQL est « postgres:17 »'], self::ecartsDeVersionDeLaCI('ci', $tache([...$matrice([8.5], [17, 18]), 'services' => $postgres('postgres:${{ matrix.postgres }}')], $installer(8.5), $composer)));
        $this->assertSame(['ci › t : le service PostgreSQL est « postgres:${{ matrix.postgres }} »'], self::ecartsDeVersionDeLaCI('ci', $tache(['services' => $postgres('postgres:${{ matrix.postgres }}')], $installer(8.5), $composer)));
        $this->assertSame(['ci › t : le conteneur PostgreSQL est « postgres:16 »'], self::ecartsDeVersionDeLaCI('ci', $tache(['container' => 'postgres:16'], $installer(8.5), $composer)));
        $this->assertSame(['ci › t : le conteneur PostgreSQL est « postgis/postgis:18-3.5 »'], self::ecartsDeVersionDeLaCI('ci', $tache(['container' => ['image' => 'postgis/postgis:18-3.5']], $installer(8.5), $composer)));
    }

    /**
     * D76 : chaque point légal ou réglementaire a une décision de Claude,
     * appliquée sans attendre ; aucun texte du code, des migrations, des
     * vues ni de CLAUDE.md ne dit attendre l'avis d'un expert-comptable ou
     * d'un professionnel, ni n'en garde la marque (« à confirmer », point
     * soumis à l'éditeur, règle attendue). Témoins : chaque tournure est
     * reconnue.
     */
    public function test_aucun_texte_n_attend_l_avis_d_un_professionnel(): void
    {
        $motif = '/confirmation de l\'expert-comptable|confirm(é|ée|er) par l\'expert-comptable|faire confirmer|validée par l\'expert-comptable|en attente de (la confirmation|l\'avis) d(e l\'|u |\'un )(expert|professionnel)|Point soumis à l\'éditeur|\(à confirmer\)|attend la règle/u';
        $fichiers = [base_path('CLAUDE.md')];

        foreach (['app', 'database', 'resources'] as $dossier) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dossier), \FilesystemIterator::SKIP_DOTS)) as $fichier) {
                if ($fichier instanceof \SplFileInfo && in_array($fichier->getExtension(), ['php', 'md'], true)) {
                    $fichiers[] = $fichier->getPathname();
                }
            }
        }

        $trouves = array_values(array_filter($fichiers, fn (string $fichier): bool => preg_match($motif, (string) file_get_contents($fichier)) === 1));

        $this->assertSame([], $trouves);
        foreach (['en attente de la confirmation de l\'expert-comptable', 'à faire confirmer par l\'expert-comptable', 'une durée validée par l\'expert-comptable', 'Point soumis à l\'éditeur : x', 'Encaissement le 05/10 (à confirmer)', 'la répartition attend la règle'] as $temoin) {
            $this->assertMatchesRegularExpression($motif, $temoin);
        }
    }
}
