<?php

namespace Tests\Feature\Calendrier;

use Closure;
use Illuminate\Support\Facades\File;
use LogicException;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Les règles du calendrier, vérifiées sur le code. Dans l'application
 * (app/, les vues, les fabriques et les seeders), seule la classe
 * Calendrier connaît un fuseau : aucun autre code ne lit un jour, une
 * année, un minuit ou un affichage dans un fuseau implicite, ni ne
 * compare un jour par une date qui porte une heure. Dans les tests, toute
 * heure écrite dit de quel fuseau elle est, aucun test ne voyage en jours
 * ni ne part de l'heure réelle, et aucun voyage dans le temps ne part
 * d'une horloge libre. Une exception n'est permise que listée ici, avec
 * sa raison.
 */
class ReglesDuCalendrierTest extends TestCase
{
    /**
     * Les dossiers de l'application soumis à la règle, hors
     * app/Domain/Calendrier.
     */
    private const DOSSIERS_DE_L_APPLICATION = ['app', 'resources/views', 'database/factories', 'database/seeders'];

    /**
     * Ce que l'application ne fait jamais hors de Calendrier, et ce qui le
     * remplace : lire un fuseau, un jour, une année ou un minuit, mettre
     * une date en forme, décaler en jours ou lire une date dans un fuseau
     * implicite.
     *
     * @var array<string, string>
     */
    private const MOTIFS_DU_FUSEAU = [
        'fuseau écrit ou lu en clair (Calendrier::deParis())' => "/config\\(\\s*'app\\.timezone'\\s*\\)|Europe\\/Paris|date_default_timezone_set/",
        'conversion de fuseau (Calendrier::deParis()->afficher(), jourDe())' => '/setTimezone\\(|->tz\\(|shiftTimezone\\(|->timezone\\(/',
        'jour courant implicite (Calendrier::deParis()->aujourdhui())' => "/\\btoday\\(|\\bnow\\(\\s*'|::now\\(\\s*'/",
        'mise en forme dans un fuseau implicite (Calendrier::deParis()->afficher(), jourDe(), iso8601())' => '/->format\\(|->translatedFormat\\(|->isoFormat\\(|->toDateString\\(|->toDateTimeString\\(|->toIso8601String\\(/',
        'calcul de calendrier sur un instant (Calendrier)' => '/->(?:startOf|endOf)(?!Second\\b)\\w+\\(|->isToday\\(|->isSameDay\\(|->isYesterday\\(|->isTomorrow\\(|->diffInDays\\(|->(?:year|month|day)\\b/',
        'décalage en jours, mois ou années (Calendrier::deParis()->decaler(), ajouterJours())' => '/(?:add|sub)(?:Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)\\(/',
        'lecture d\'une date dans un fuseau implicite (Calendrier::deParis()->heureMurale(), Calendrier::jourValide())' => '/::parse\\(|::createFromFormat\\(|Carbon(?:Immutable)?::create\\(|->setDate\\(|->setDay\\(/',
        'règle de validation au jour implicite (Calendrier::deParis()->aujourdhui())' => '/(?<!:):(?:today|tomorrow|yesterday|now)\\b/',
        'colonne de jour lue comme un instant (->jour(), ou ->dateTime(\'d/m/Y\') pour un instant)' => '/->date\\(/',
        'borne d\'un sélecteur de date sans le calendrier (Calendrier::deParis()->aujourdhui())' => '/(?:minDate|maxDate)\\((?!.*Calendrier::)/',
    ];

    /**
     * Les requêtes qui compareraient un jour par une date qui porte une
     * heure, ou en extrairaient un jour en SQL, dans le fuseau de la base.
     *
     * @var array<string, string>
     */
    private const MOTIFS_DES_REQUETES = [
        'comparaison d\'un jour par une date (where() sur un jour, bornes demi-ouvertes)' => '/whereDate\\(|whereYear\\(|whereMonth\\(|whereDay\\(|whereTime\\(/',
        'date extraite en SQL (bornes calculées en PHP)' => '/(?<![>:\\w])DATE\\(|strftime|date_trunc|EXTRACT\\(|::date\\b(?!\\()|AT TIME ZONE/',
    ];

    /**
     * Les casts qui liraient une colonne de date ou d'heure dans le fuseau
     * de l'application.
     *
     * @var array<string, string>
     */
    private const MOTIFS_DES_CASTS = [
        'cast de jour qui porte une heure (JourCivil::class)' => "/=> '(?:date|immutable_date)(?::[^']*)?'/",
    ];

    /**
     * Les lignes permises malgré un motif, par fichier et par extrait de
     * la ligne, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const LIGNES_PERMISES = [
        'app/Providers/AppServiceProvider.php' => [
            '$composant->timezone(Calendrier::deParis()->fuseau())' => 'le seul réglage du fuseau des colonnes et des entrées de Filament, celui du calendrier',
        ],
        'app/Filament/Societe/Pages/ParametresFiscauxSociete.php' => [
            'Carbon::create(2000, $mois)->translatedFormat(\'F\')' => 'le nom de chacun des douze mois, tiré d\'une date fixe sans heure',
        ],
        'app/Domain/CaisseSecurisee/Services/RegistreFiscal.php' => [
            'CarbonImmutable::now(\'UTC\')' => 'tant que l\'application est à l\'heure de Paris, seule une horloge lue en UTC donne sans ambiguïté le premier passage de l\'heure répétée ; la bascule écrira now()',
            '\'instant\' => $instant->utc->toIso8601String()' => 'journal technique d\'une horloge qui recule (Log::critical), en UTC',
            '\'dernier_verrou\' => $dernierVerrou->toIso8601String()' => 'journal technique d\'une horloge qui recule (Log::critical), en UTC',
        ],
        'app/Domain/Achats/Actions/ValiderFactureAchatAction.php' => [
            '$maintenant = CarbonImmutable::now(\'UTC\');' => 'tant que l\'application est à l\'heure de Paris, seule une horloge lue en UTC donne sans ambiguïté le premier passage de l\'heure répétée ; la bascule écrira now()',
        ],
        'app/Domain/Achats/Actions/EmettreAvoirFournisseurAction.php' => [
            '$maintenant = CarbonImmutable::now(\'UTC\');' => 'tant que l\'application est à l\'heure de Paris, seule une horloge lue en UTC donne sans ambiguïté le premier passage de l\'heure répétée ; la bascule écrira now()',
        ],
        'app/Domain/CaisseSecurisee/Exceptions/HorlogeRegressiveException.php' => [
            '$instant->format(\'d/m/Y H:i:s\')' => 'message d\'une horloge du serveur qui recule, qui dit l\'heure UTC',
            '$dernierVerrou->format(\'d/m/Y H:i:s\')' => 'message d\'une horloge du serveur qui recule, qui dit l\'heure UTC',
        ],
        'app/Console/Commands/DiagnostiquerCaisseSecurisee.php' => [
            'now()->toIso8601String()' => 'contenu d\'essai, signé puis jeté, en UTC',
        ],
        'app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php' => [
            'AT TIME ZONE \'UTC\'' => 'les déclencheurs de purge comparent déjà à l\'heure UTC du serveur de base',
        ],
        'app/Domain/CaisseSecurisee/Casts/HorodatageUtc.php' => [
            'CarbonImmutable::instance($instant)->utc()->format(self::FORMAT)' => 'le format en base d\'un horodatage, en UTC, que la bascule reprend dans Instant',
            'CarbonImmutable::createFromFormat(\'!\'.self::FORMAT, $texte, \'UTC\')' => 'la lecture en UTC d\'un horodatage en base, que la bascule reprend dans Instant',
            'self::depuisLaBase($value)->setTimezone($this->fuseauDeLecture)' => 'le fuseau de lecture du cast, que la bascule retire',
        ],
        'app/Domain/Tenancy/Services/JetonsDuLien.php' => [
            'CarbonImmutable::parse($ligne->created_at)' => 'texte brut d\'une table de Laravel, à l\'heure de l\'application jusqu\'à la bascule, qui le relira par Instant::depuisLaBase()',
        ],
        'app/Console/Commands/FermerDemonstrationsInactives.php' => [
            'CarbonImmutable::parse($instant)' => 'agrégat brut, à l\'heure de l\'application jusqu\'à la bascule, qui le relira par Instant::depuisLaBase()',
        ],
    ];

    /**
     * Une heure écrite en clair (« AAAA-MM-JJ HH:MM », avec ou sans
     * secondes, sans décalage), entre apostrophes ou guillemets.
     */
    private const HEURE_ECRITE = '/([\'"])\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?\1/';

    /**
     * Les heures écrites permises hors de aParis() et de enUtc(), par
     * fichier et par méthode, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const HEURES_ECRITES_PERMISES = [
        'tests/Feature/Facturation/NatureOperationTest.php' => [
            'livraisonsIntracommunautairesRefusees' => 'jeu de données : la méthode de test passe chaque heure à aParis()',
        ],
        'tests/Feature/Providers/AppServiceProviderTest.php' => [
            'reglesDeDate' => 'texte saisi dans un formulaire, que lit la règle de validation before:',
        ],
        'tests/Unit/CalendrierTest.php' => [
            'heuresMurales' => 'jeu de données : la méthode de test passe chaque heure à Calendrier::deParis()->heureMurale(), qu\'elle éprouve',
        ],
    ];

    /**
     * Les voyages permis depuis une horloge qui n'est pas figée par le
     * test, par fichier et par méthode, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const VOYAGES_PERMIS = [
        'tests/Feature/CaisseSecurisee/PurgerTracesTest.php' => [
            'test_une_trace_qui_n_a_expire_qu_a_l_heure_de_l_application_attend_sans_faire_echouer_la_purge' => 'le déclencheur de purge compare à l\'horloge du serveur de base, que faketime ne décale pas sous PostgreSQL : le test la suit (maintenantSelonLaBase())',
        ],
    ];

    /**
     * Hors de Calendrier, l'application ne lit jamais un fuseau implicite
     * ni ne met une date en forme hors de lui. Dans Calendrier, le nom du
     * fuseau de Paris n'est écrit qu'une fois, dans une constante privée
     * que seule la fabrique deParis() lit ; hors de son constructeur
     * privé, aucune méthode ne reçoit un fuseau.
     */
    public function test_seul_le_calendrier_connait_un_fuseau_et_met_les_dates_en_forme(): void
    {
        $this->assertSame([], $this->violationsDansLApplication(self::MOTIFS_DU_FUSEAU));

        $code = implode('', array_map(static fn (SplFileInfo $fichier): string => $fichier->getContents(), File::allFiles(base_path('app/Domain/Calendrier'))));
        preg_match_all('/(\w+) function (\w+)\(([^)]*)\)/', $code, $fonctions, PREG_SET_ORDER);
        $recoiventUnFuseau = array_filter($fonctions, static fn (array $fonction): bool => preg_match('/fuseau|timezone|DateTimeZone/i', $fonction[3]) === 1);

        $this->assertSame([1, 1, 1], [
            substr_count($code, "'Europe/Paris'"),
            substr_count($code, "private const FUSEAU_DE_PARIS = 'Europe/Paris';"),
            substr_count($code, 'self::FUSEAU_DE_PARIS'),
        ]);
        $this->assertSame(1, preg_match('/public static function deParis\(\): self\s*\{\s*return new self\(self::FUSEAU_DE_PARIS\);/', $code));
        $this->assertSame([['private', '__construct']], array_values(array_map(static fn (array $fonction): array => [$fonction[1], $fonction[2]], $recoiventUnFuseau)));
    }

    /**
     * Un jour se compare à un jour par where(), une période de jours sur
     * un instant par des bornes calculées en PHP : aucune requête ne
     * compare par whereDate() ni n'extrait une date en SQL, dans le fuseau
     * de la base.
     */
    public function test_aucune_requete_n_extrait_une_date_en_sql_ni_ne_compare_par_where_date(): void
    {
        $this->assertSame([], $this->violationsDansLApplication(self::MOTIFS_DES_REQUETES));
    }

    /**
     * Une colonne de jour a le cast JourCivil, jamais un cast de date qui
     * la lirait comme un minuit du fuseau de l'application.
     */
    public function test_les_colonnes_de_date_ont_les_casts_du_calendrier_et_aucune_heure_par_defaut(): void
    {
        $this->assertSame([], $this->violationsDansLApplication(self::MOTIFS_DES_CASTS));
    }

    /**
     * Les tests ne lisent ni le fuseau de l'application ni le nom du
     * fuseau de Paris, ni le jour courant dans un fuseau implicite : un
     * jour s'y écrit en clair (« AAAA-MM-JJ ») ou par
     * Calendrier::deParis()->aujourdhui(), un fuseau par
     * Calendrier::deParis()->fuseau().
     */
    public function test_les_tests_lisent_le_jour_et_le_fuseau_par_le_calendrier(): void
    {
        $violations = $this->violationsDansLesTests(static fn (string $ligne): array => array_values(array_filter([
            preg_match("/config\\(\\s*'app\\.timezone'\\s*\\)|Europe\\/Paris/", $ligne) === 1 ? 'fuseau écrit ou lu en clair' : null,
            preg_match("/\\btoday\\(|\\bnow\\(\\s*'|::now\\(\\s*'/", $ligne) === 1 ? 'jour courant implicite' : null,
        ])), []);

        $this->assertSame([], $violations);
    }

    /**
     * Une heure écrite n'apparaît qu'en argument de aParis() ou de
     * enUtc(), ou de Calendrier::heureMurale() que aParis() appelle,
     * quel que soit son usage (voyage, aide, attribut, attente) : sans
     * fuseau, elle se lirait dans le fuseau par défaut de l'application,
     * et un changement de ce fuseau la décalerait en silence. Un voyage
     * ne reçoit jamais une chaîne.
     */
    public function test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite(): void
    {
        $violations = $this->violationsDansLesTests(function (string $ligne): array {
            $sansAides = preg_replace('/\b(?:aParis|enUtc|heureMurale)\(\s*([\'"])[^\'"]*\1/', '', $ligne);

            return array_values(array_filter([
                preg_match(self::HEURE_ECRITE, (string) $sansAides) === 1 ? 'heure écrite sans fuseau' : null,
                preg_match('/travelTo\(\s*[\'"]/', $ligne) === 1 ? 'voyage vers une chaîne' : null,
            ]));
        }, self::HEURES_ECRITES_PERMISES);

        $this->assertSame([], $violations);
    }

    /**
     * Aucun test ne voyage en jours, semaines, mois ou années, ni ne
     * calcule ainsi sur un instant : ce calcul dépendrait du fuseau de
     * l'application. Aucun voyage ne part de l'heure réelle ni de celle du
     * serveur de base, sauf l'exception listée.
     */
    public function test_aucun_test_ne_voyage_en_jours_ni_ne_part_de_l_heure_reelle(): void
    {
        $violations = $this->violationsDansLesTests(static fn (string $ligne): array => array_values(array_filter([
            preg_match('/travel\([^;]*\)->(?:day|days|week|weeks|month|months|year|years)\(/', $ligne) === 1 ? 'voyage en jours' : null,
            preg_match('/->(?:add|sub)(?:Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)\(/', $ligne) === 1 ? 'calcul en jours sur un instant' : null,
            preg_match('/travelTo\(\s*(?:now\(|[\\\\\w]*::now\(|\$this->maintenantSelonLaBase\()/', $ligne) === 1 ? 'voyage parti de l\'heure réelle' : null,
        ])), self::VOYAGES_PERMIS);

        $this->assertSame([], $violations);
    }

    /**
     * travel(), freezeTime() et freezeSecond() refusent de partir d'une
     * horloge libre, qui serait l'heure réelle ; une fois l'horloge figée,
     * ils partent d'elle.
     */
    public function test_un_voyage_dans_le_temps_exige_une_horloge_figee(): void
    {
        foreach (['travel()' => fn () => $this->travel(1)->hours(), 'freezeTime()' => fn () => $this->freezeTime(), 'freezeSecond()' => fn () => $this->freezeSecond()] as $voyage => $partir) {
            $this->assertThrows($partir, LogicException::class, "{$voyage} partirait de l'heure réelle : figez d'abord l'horloge à un instant écrit");
        }

        $this->travelTo(self::enUtc('2026-10-09 08:00:00'));
        $this->travel(1)->hours();
        $this->freezeSecond();
        $this->freezeTime();

        $this->assertMemeInstant(self::enUtc('2026-10-09 09:00:00'), now());
    }

    /**
     * Les lignes des fichiers de tests où la règle trouve un motif
     * interdit, hors des méthodes permises.
     *
     * @param  Closure(string): list<string>  $motifs  les motifs interdits de la ligne
     * @param  array<string, array<string, string>>  $permises
     * @return list<string>
     */
    private function violationsDansLesTests(Closure $motifs, array $permises): array
    {
        $violations = [];

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            $chemin = $this->cheminDansLeDepot($fichier);

            if ($fichier->getExtension() !== 'php' || $chemin === 'tests/Feature/Calendrier/ReglesDuCalendrierTest.php') {
                continue;
            }

            $methode = null;

            foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                if (preg_match('/\bfunction\s+(\w+)\s*\(/', $ligne, $declaration) === 1) {
                    $methode = $declaration[1];
                }

                if (isset($permises[$chemin][$methode])) {
                    continue;
                }

                foreach ($motifs($ligne) as $motif) {
                    $violations[] = sprintf('%s:%d  %s  %s', $chemin, $numero + 1, $motif, trim($ligne));
                }
            }
        }

        return $violations;
    }

    /**
     * Les lignes de l'application où la règle trouve l'un de ces motifs,
     * hors app/Domain/Calendrier, hors commentaires et hors lignes
     * permises.
     *
     * @param  array<string, string>  $motifs
     * @return list<string>
     */
    private function violationsDansLApplication(array $motifs): array
    {
        $violations = [];

        foreach (self::DOSSIERS_DE_L_APPLICATION as $dossier) {
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $chemin = $this->cheminDansLeDepot($fichier);

                if ($fichier->getExtension() !== 'php' || str_starts_with($chemin, 'app/Domain/Calendrier/')) {
                    continue;
                }

                foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                    if (preg_match('#^\s*(?:\*|//|/\*|\{\{--)#', $ligne) === 1 || $this->lignePermise($chemin, $ligne)) {
                        continue;
                    }

                    foreach ($motifs as $motif => $expression) {
                        if (preg_match($expression, $ligne) === 1) {
                            $violations[] = sprintf('%s:%d  %s  %s', $chemin, $numero + 1, $motif, trim($ligne));
                        }
                    }
                }
            }
        }

        return $violations;
    }

    private function lignePermise(string $chemin, string $ligne): bool
    {
        foreach (array_keys(self::LIGNES_PERMISES[$chemin] ?? []) as $extrait) {
            if (str_contains($ligne, $extrait)) {
                return true;
            }
        }

        return false;
    }

    private function cheminDansLeDepot(SplFileInfo $fichier): string
    {
        return str_replace(base_path().'/', '', $fichier->getPathname());
    }
}
