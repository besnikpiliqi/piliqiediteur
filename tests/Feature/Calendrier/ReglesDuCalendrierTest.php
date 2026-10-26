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
 * compare un jour par une date qui porte une heure. Le code d'un magasin
 * prend le calendrier de son magasin, jamais celui de Paris, et le
 * calendrier d'affichage du magasin actif ne sert qu'à Filament. Dans les
 * tests, toute heure écrite dit de quel fuseau elle est, aucun test ne
 * voyage en jours ni ne part de l'heure réelle, et aucun voyage dans le
 * temps ne part d'une horloge libre. Une exception n'est permise que
 * listée ici, avec sa raison.
 */
class ReglesDuCalendrierTest extends TestCase
{
    /**
     * Les dossiers de l'application soumis à la règle, hors
     * app/Domain/Calendrier.
     */
    private const DOSSIERS_DE_L_APPLICATION = ['app', 'resources/views', 'database/factories', 'database/seeders'];

    /**
     * Un nom de fuseau écrit en clair, quel qu'il soit, sauf les noms de
     * l'UTC, qui ne sont pas des calendriers : la configuration, la
     * session PostgreSQL et le diagnostic de la caisse sécurisée les
     * écrivent.
     */
    private const NOM_DE_FUSEAU = '#\\b(?:Europe|America|Indian|Pacific|Atlantic|Africa|Asia|Australia|Antarctica)/|\\bEtc/(?!UTC\\b)#';

    /**
     * Le code d'un magasin, où Calendrier::deParis() n'a pas cours : un
     * jour, une année ou un minuit y est celui du magasin
     * ($magasin->calendrier(), ou celui du magasin du document).
     */
    private const CODE_D_UN_MAGASIN = [
        'app/Domain/Facturation/',
        'app/Domain/Achats/',
        'app/Domain/Stock/',
        'app/Domain/Catalogue/',
        'app/Domain/CaisseSecurisee/',
        'app/Domain/Contacts/',
        'app/Domain/Tenancy/Services/PeriodesDActivite.php',
        'app/Filament/App/',
        'resources/views/factures/',
        'resources/views/avoirs/',
        'resources/views/devis/',
        'resources/views/documents/',
        'resources/views/filament/app/',
        'app/Console/Commands/ClotureAutomatiqueCaisse.php',
        'app/Console/Commands/MarquerFacturesEnRetard.php',
        'app/Console/Commands/ExpirerDevis.php',
        'app/Console/Commands/RelancerFacturesImpayees.php',
    ];

    /**
     * Les fichiers du code d'un magasin où Calendrier::deParis() reste
     * permis, avec leur raison.
     *
     * @var array<string, string>
     */
    private const PARIS_PERMIS_DANS_UN_MAGASIN = [
        'app/Filament/App/Pages/Auth/ModifierProfil.php' => 'échéance du lien de l\'adresse de connexion du propriétaire : heure propre à un compte de la société, sur une page commune aux deux panneaux',
        'app/Domain/CaisseSecurisee/Models/TraceEvenement.php' => 'expiration d\'une trace technique (adresse IP et navigateur) après un an : délai technique, qui ne date aucune opération du magasin',
    ];

    /**
     * Ce que l'application ne fait jamais hors de Calendrier, et ce qui le
     * remplace : lire un fuseau, un jour, une année ou un minuit, mettre
     * une date en forme, décaler en jours ou lire une date dans un fuseau
     * implicite.
     *
     * @var array<string, string>
     */
    private const MOTIFS_DU_FUSEAU = [
        'fuseau lu en clair (Calendrier::deParis(), $magasin->calendrier())' => "/config\\(\\s*'app\\.timezone'\\s*\\)|date_default_timezone_set/",
        'nom de fuseau écrit en clair (Calendrier::deParis(), Calendrier::duTerritoire())' => self::NOM_DE_FUSEAU,
        'conversion de fuseau (Calendrier::deParis()->afficher(), jourDe())' => '/setTimezone\\(|->tz\\(|shiftTimezone\\(|->timezone\\(/',
        'jour courant implicite (Calendrier::deParis()->aujourdhui())' => "/\\btoday\\(|\\bnow\\(\\s*'|::now\\(\\s*'/",
        'mise en forme dans un fuseau implicite (Calendrier::deParis()->afficher(), jourDe(), iso8601())' => '/->format\\(|->translatedFormat\\(|->isoFormat\\(|->toDateString\\(|->toDateTimeString\\(|->toIso8601String\\(/',
        'calcul de calendrier sur un instant (Calendrier)' => '/->(?:startOf|endOf)(?!Second\\b)\\w+\\(|->isToday\\(|->isSameDay\\(|->isYesterday\\(|->isTomorrow\\(|->diffInDays\\(|->(?:year|month|day)\\b/',
        'décalage en jours, mois ou années (Calendrier::deParis()->decaler(), ajouterJours())' => '/(?:add|sub)(?:Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)\\(/',
        'lecture d\'une date dans un fuseau implicite (Calendrier::deParis()->heureMurale(), Calendrier::jourValide())' => '/::parse\\(|::createFromFormat\\(|Carbon(?:Immutable)?::create\\(|->setDate\\(|->setDay\\(/',
        'fonction de date de PHP au fuseau par défaut (Calendrier::deParis()->afficher(), anneeDe(), heureMurale())' => '/(?<!function )(?<![\\w>:$\\\\])\\\\?(?:date|gmdate|strtotime|mktime|gmmktime|idate|getdate|localtime|date_create|date_create_immutable)\\(|new \\\\?DateTime(?:Immutable)?\\(/',
        'règle de validation au jour implicite (Calendrier::deParis()->aujourdhui())' => '/(?<!:):(?:today|tomorrow|yesterday|now)\\b/',
        'colonne de jour lue comme un instant (->jour(), ou ->dateTime(\'d/m/Y\') pour un instant)' => '/->date\\(/',
        'borne d\'un sélecteur de date sans le calendrier ($magasin->calendrier()->aujourdhui())' => '/(?:minDate|maxDate)\\((?!.*(?:Calendrier::|calendrierDuMagasin\\(\\)|->calendrier\\(\\)))/',
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
     * de l'application, et les heures par défaut qu'écrirait la base.
     *
     * @var array<string, string>
     */
    private const MOTIFS_DES_CASTS = [
        'cast de jour qui porte une heure (JourCivil::class)' => "/=> '(?:date|immutable_date)(?::[^']*)?'/",
        'cast d\'instant dans le fuseau de l\'application (Instant::class)' => "/=> '(?:datetime|immutable_datetime)(?::[^']*)?'|HorodatageUtc/",
    ];

    /**
     * Les lignes permises malgré un motif, par fichier et par extrait de
     * la ligne, avec leur raison.
     *
     * @var array<string, array<string, string>>
     */
    private const LIGNES_PERMISES = [
        'app/Providers/AppServiceProvider.php' => [
            '$composant->timezone(static fn (): string => app(TenantContext::class)->calendrierDAffichage()->fuseau())' => 'le seul réglage du fuseau des colonnes et des entrées de Filament, celui du calendrier du magasin actif, sinon de Paris',
        ],
        'app/Filament/Societe/Pages/ParametresFiscauxSociete.php' => [
            'Carbon::create(2000, $mois)->translatedFormat(\'F\')' => 'le nom de chacun des douze mois, tiré d\'une date fixe sans heure',
        ],
        'app/Domain/CaisseSecurisee/Services/RegistreFiscal.php' => [
            '\'instant\' => $instant->utc->toIso8601String()' => 'journal technique d\'une horloge qui recule (Log::critical), en UTC',
            '\'dernier_verrou\' => $dernierVerrou->toIso8601String()' => 'journal technique d\'une horloge qui recule (Log::critical), en UTC',
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
     * Hors de Calendrier, l'application ne lit jamais un fuseau implicite,
     * n'écrit aucun nom de fuseau (sauf ceux de l'UTC) ni ne met une date
     * en forme hors de lui. Dans Calendrier, le nom du fuseau de Paris
     * n'est écrit qu'une fois, dans une constante privée que seule la
     * fabrique deParis() lit ; les autres noms ne sont écrits que dans la
     * table de duTerritoire(), qui renvoie à deParis() pour la métropole
     * et la Corse ; hors de son constructeur privé, aucune méthode ne
     * reçoit un fuseau.
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

        $this->assertSame(1, preg_match('/public static function duTerritoire\(Territoire \$territoire\): self\s*\{(.*?)\n    \}/s', $code, $table));
        $nomsHorsDeParis = static fn (string $texte): int => (int) preg_match_all(self::NOM_DE_FUSEAU, str_replace("'Europe/Paris'", '', $texte));

        $this->assertSame(5, $nomsHorsDeParis($table[1]));
        $this->assertSame($nomsHorsDeParis($table[1]), $nomsHorsDeParis($code));
        $this->assertStringContainsString('Territoire::Metropole, Territoire::Corse => self::deParis()', $table[1]);
    }

    /**
     * Le code d'un magasin (ses documents, sa caisse, ses achats, son
     * stock, ses consultations VIES, ses périodes d'activité, son panneau,
     * ses PDF et ses tâches planifiées) prend le calendrier de son magasin,
     * jamais celui de Paris : un magasin de la Martinique ou de La Réunion
     * a son propre minuit. Seules les exceptions listées, avec leur raison,
     * gardent Paris.
     */
    public function test_le_code_d_un_magasin_prend_le_calendrier_de_son_magasin(): void
    {
        $violations = [];

        foreach ($this->fichiersDeLApplication() as $chemin => $fichier) {
            $dansUnMagasin = array_filter(self::CODE_D_UN_MAGASIN, static fn (string $portee): bool => str_starts_with($chemin, $portee));

            if ($dansUnMagasin === [] || isset(self::PARIS_PERMIS_DANS_UN_MAGASIN[$chemin])) {
                continue;
            }

            foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                if (str_contains($ligne, 'Calendrier::deParis()')) {
                    $violations[] = sprintf('%s:%d  calendrier de Paris dans le code d\'un magasin ($magasin->calendrier())  %s', $chemin, $numero + 1, trim($ligne));
                }
            }
        }

        $this->assertSame([], $violations);

        foreach (array_keys(self::PARIS_PERMIS_DANS_UN_MAGASIN) as $chemin) {
            $this->assertStringContainsString('Calendrier::deParis()', File::get(base_path($chemin)), "{$chemin} : exception devenue inutile, à retirer");
        }
    }

    /**
     * Le calendrier d'affichage du magasin actif (celui de Paris hors d'un
     * magasin) ne sert qu'au réglage du fuseau des colonnes et des entrées
     * de Filament : tout autre code prend le calendrier du magasin pour
     * lequel il calcule, ou celui du magasin du document, jamais celui du
     * contexte.
     */
    public function test_le_calendrier_d_affichage_ne_sert_qu_a_filament(): void
    {
        $appels = [];

        foreach ($this->fichiersDeLApplication() as $chemin => $fichier) {
            foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                if (str_contains($ligne, 'calendrierDAffichage(') && preg_match('#^\s*(?:\*|//|/\*|\{\{--)#', $ligne) !== 1) {
                    $appels[] = sprintf('%s:%d', $chemin, $numero + 1);
                }
            }
        }

        $this->assertCount(2, $appels);
        $this->assertStringStartsWith('app/Domain/Tenancy/Services/TenantContext.php:', $appels[0]);
        $this->assertStringStartsWith('app/Providers/AppServiceProvider.php:', $appels[1]);
        $this->assertStringContainsString('public function calendrierDAffichage(): Calendrier', explode("\n", File::get(base_path('app/Domain/Tenancy/Services/TenantContext.php')))[(int) explode(':', $appels[0])[1] - 1]);
        $this->assertSame([], $this->violationsDansLesTests(static fn (string $ligne): array => str_contains($ligne, 'calendrierDAffichage(') ? ['calendrier d\'affichage hors de Filament'] : [], []));
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
     * Une colonne de jour a le cast JourCivil, une colonne d'instant le
     * cast Instant, jamais un cast qui les lirait dans le fuseau de
     * l'application ; aucune migration ne donne à une colonne l'heure de
     * la base pour valeur par défaut : l'application écrit chaque
     * instant.
     */
    public function test_les_colonnes_de_date_ont_les_casts_du_calendrier_et_aucune_heure_par_defaut(): void
    {
        $this->assertSame([], $this->violationsDansLApplication(self::MOTIFS_DES_CASTS));

        $heuresParDefaut = [];

        foreach (File::allFiles(base_path('database/migrations')) as $fichier) {
            foreach (explode("\n", $fichier->getContents()) as $numero => $ligne) {
                if (preg_match('/useCurrent(?:OnUpdate)?\\(|CURRENT_TIMESTAMP|now\\(\\)/i', $ligne) === 1) {
                    $heuresParDefaut[] = sprintf('%s:%d  %s', $this->cheminDansLeDepot($fichier), $numero + 1, trim($ligne));
                }
            }
        }

        $this->assertSame([], $heuresParDefaut);
    }

    /**
     * Les tests ne lisent pas le fuseau de l'application, n'écrivent aucun
     * nom de fuseau (sauf ceux de l'UTC) et ne lisent pas le jour courant
     * dans un fuseau implicite : un jour s'y écrit en clair
     * (« AAAA-MM-JJ ») ou par le calendrier
     * (Calendrier::deParis()->aujourdhui(), $magasin->calendrier()), un
     * fuseau par Calendrier::deParis()->fuseau() ou
     * Calendrier::duTerritoire(). Seul le test de la table de
     * duTerritoire() écrit les noms qu'il vérifie.
     */
    public function test_les_tests_lisent_le_jour_et_le_fuseau_par_le_calendrier(): void
    {
        $violations = $this->violationsDansLesTests(static fn (string $ligne): array => array_values(array_filter([
            preg_match("/config\\(\\s*'app\\.timezone'\\s*\\)/", $ligne) === 1 || preg_match(self::NOM_DE_FUSEAU, $ligne) === 1 ? 'fuseau écrit ou lu en clair' : null,
            preg_match("/\\btoday\\(|\\bnow\\(\\s*'|::now\\(\\s*'/", $ligne) === 1 ? 'jour courant implicite' : null,
        ])), [
            'tests/Unit/CalendrierTest.php' => [
                'test_chaque_territoire_a_son_fuseau' => 'il vérifie la table de Calendrier::duTerritoire(), nom par nom',
            ],
        ]);

        $this->assertSame([], $violations);
    }

    /**
     * Une heure écrite n'apparaît qu'en argument de aParis(), de
     * aLHeureDe() (après son territoire) ou de enUtc(), ou de
     * Calendrier::heureMurale() que les deux premières appellent, quel que
     * soit son usage (voyage, aide, attribut, attente) : sans fuseau, elle
     * se lirait dans le fuseau par défaut de l'application, et un
     * changement de ce fuseau la décalerait en silence. Un voyage ne
     * reçoit jamais une chaîne.
     */
    public function test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite(): void
    {
        $violations = $this->violationsDansLesTests(function (string $ligne): array {
            $sansAides = preg_replace('/\b(?:aParis|enUtc|heureMurale)\(\s*([\'"])[^\'"]*\1|\baLHeureDe\([^,()]*,\s*([\'"])[^\'"]*\2/', '', $ligne);

            return array_values(array_filter([
                preg_match(self::HEURE_ECRITE, (string) $sansAides) === 1 ? 'heure écrite sans fuseau' : null,
                preg_match('/travelTo\(\s*[\'"]/', $ligne) === 1 ? 'voyage vers une chaîne' : null,
            ]));
        }, self::HEURES_ECRITES_PERMISES);

        $this->assertSame([], $violations);
    }

    /**
     * Aucun test ne voyage en jours, semaines, mois ou années, ni ne
     * calcule ainsi sur un instant, ni n'y règle une heure murale : ce
     * calcul dépendrait du fuseau de l'application. Aucun voyage ne part de l'heure réelle ni de celle du
     * serveur de base, sauf l'exception listée.
     */
    public function test_aucun_test_ne_voyage_en_jours_ni_ne_part_de_l_heure_reelle(): void
    {
        $violations = $this->violationsDansLesTests(static fn (string $ligne): array => array_values(array_filter([
            preg_match('/travel\([^;]*\)->(?:day|days|week|weeks|month|months|year|years)\(/', $ligne) === 1 ? 'voyage en jours' : null,
            preg_match('/->(?:add|sub)(?:Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)\(/', $ligne) === 1 ? 'calcul en jours sur un instant' : null,
            preg_match('/->(?:setTime|setHour|setMinute|setDate|setDay|startOfDay|endOfDay)\\(/', $ligne) === 1 ? 'heure murale réglée sur un instant' : null,
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

        foreach ($this->fichiersDeLApplication() as $chemin => $fichier) {
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

        return $violations;
    }

    /**
     * Les fichiers PHP de l'application soumis aux règles, par chemin dans
     * le dépôt, hors app/Domain/Calendrier.
     *
     * @return array<string, SplFileInfo>
     */
    private function fichiersDeLApplication(): array
    {
        $fichiers = [];

        foreach (self::DOSSIERS_DE_L_APPLICATION as $dossier) {
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $chemin = $this->cheminDansLeDepot($fichier);

                if ($fichier->getExtension() === 'php' && ! str_starts_with($chemin, 'app/Domain/Calendrier/')) {
                    $fichiers[$chemin] = $fichier;
                }
            }
        }

        ksort($fichiers);

        return $fichiers;
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
