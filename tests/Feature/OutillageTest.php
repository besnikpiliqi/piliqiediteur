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
use Tests\TestCase;

/**
 * Aucun paquet inutilisé et aucun outil Node hors Playwright, qui ne sert
 * qu'aux tests en navigateur (décisions de l'éditeur) :
 * Filament Shield et Sanctum, retirés, n'exposent plus ni route ni
 * commande, et rien de ce que lisent les agents ne renvoie à un outil
 * absent du projet. PostgreSQL est le seul moteur de base de données, et
 * la base trie les textes dans l'ordre binaire. Les aides des tests
 * lisent les PDF de dompdf sans
 * dépendre du hasard de leur contenu, aucun test ne dépend du hasard
 * de Faker ni de celui de Str::random(), et aucun commentaire n'empêche un
 * test de tourner.
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
        return "PILIQI ne fonctionne qu'avec PostgreSQL (17 ou 18) : la connexion « {$connexion} » utilise le pilote « {$pilote} ». "
            .'Réglez DB_CONNECTION=pgsql et les autres variables DB_ de .env (voir le README), videz la configuration en cache (php artisan config:clear), puis recommencez.';
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
}
