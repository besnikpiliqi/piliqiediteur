<?php

namespace Tests\Feature;

use App\Console\SemisEnCours;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Semis\ComptesSemes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\SemeSansTransaction;
use Tests\TestCase;

/**
 * Le semis complet de toutes les tables et de tous les cas (demande de
 * l'éditeur du 15/11/2026, cahier SEED-TOUT § 5, point 6 du protocole de
 * rigueur de CLAUDE.md) : après php artisan db:seed, sur plus de treize
 * mois, chaque table de la base hors tables techniques du cadre a des
 * lignes, chaque valeur de chaque état apparaît (hors les états qu'aucune
 * action ne fait encore naître dans leur colonne, chacun avec sa raison), chaque société qui a vendu a un régime de TVA choisi, chaque
 * rôle a un compte qui a ses propres données, chaque compte qui vend a ses
 * ventes et un relevé de caisse, la caisse, le stock, le journal d'audit
 * et l'isolation se vérifient sans anomalie, les nombres de chaque table
 * sont toujours les mêmes (le semis est déterministe), la liste des comptes
 * ouvre chaque compte actif, et le planificateur ne lance aucune tâche
 * pendant le semis. Le semis prend plusieurs minutes et valide ses gestes
 * un à un (voir SemeSansTransaction) : ce test est dans le groupe
 * « semis », hors de la suite par défaut (phpunit.xml), qu'une tâche de la
 * CI lance à part.
 */
#[Group('semis')]
class SemisCompletTest extends TestCase
{
    use RefreshDatabase;
    use SemeSansTransaction;

    /**
     * Les tables techniques du cadre, que le semis n'a pas à remplir
     * (point 6 du protocole de rigueur).
     */
    private const TABLES_TECHNIQUES = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'];

    /**
     * Les valeurs d'état qu'aucune action de PILIQI ne fait naître dans
     * cette colonne : chacune avec sa raison (l'étape de la feuille de
     * route qui la crée, ou la colonne qui la porte à la place).
     *
     * @var array<string, string>
     */
    private const ETATS_SANS_DONNEE = [
        'transmissions_pdp.statut.livree' => 'cycle de vie d\'une facture déposée sur SUPER PDP, étape 2',
        'transmissions_pdp.statut.refusee' => 'cycle de vie d\'une facture déposée sur SUPER PDP, étape 2',
        'transmissions_pdp.statut.archivee' => 'cycle de vie d\'une facture déposée sur SUPER PDP, étape 2',
        'mandats_pdp.statut.pending' => 'mandat en attente de SUPER PDP, étape 2',
        'mandats_pdp.statut.rejected' => 'mandat refusé par SUPER PDP, étape 2',
        'magasins.motif_fermeture.resiliation_societe' => 'résiliation d\'une société et ses clôtures finales, étape 12',
        'magasins.motif_fermeture.revocation_societe' => 'la révocation d\'une société ferme la période d\'activité de chaque magasin (periodes_activite.motif_fin) sans changer le statut du magasin, qui suit celui de sa société',
        'periodes_activite.motif_fin.resiliation_societe' => 'résiliation d\'une société et ses clôtures finales, étape 12',
    ];

    /**
     * Le nombre de lignes de chaque table hors tables techniques du cadre
     * après le semis, toujours le même d'un semis à l'autre (le semis est
     * déterministe : Faker semé, horloge du semis, instant de départ figé
     * par ce test) ; chaque lot qui change le semis met ce tableau à jour.
     *
     * @var array<string, int>
     */
    private const NOMBRES_DE_LIGNES = [
        'actions_traitees' => 4015,
        'actions_traitees_de_la_societe' => 323,
        'affectations' => 40,
        'autorisations_affectations' => 533,
        'autorisations_cabinet' => 43,
        'autorisations_societe' => 6,
        'avoir_allocations' => 121,
        'avoirs' => 118,
        'avoirs_fournisseur' => 2,
        'bordereaux_detaxe' => 2,
        'cabinets' => 4,
        'changements_fin_exercice' => 1,
        'charges' => 168,
        'clients' => 48,
        'clients_confies' => 2,
        'clotures_caisse' => 2241,
        'clotures_caisse_personnelles' => 2824,
        'clotures_caisse_personnelles_factures' => 3375,
        'clotures_caisse_personnelles_paiements' => 3286,
        'codes_confirmation' => 26,
        'comptes_expert_comptable' => 3,
        'consultations_vies' => 5,
        'demandes_inscription' => 1,
        'devis' => 225,
        'ecarts_sirene' => 2,
        'enregistrements_fiscaux' => 9176,
        'envois_documents' => 12,
        'evenements_transmission_pdp' => 61,
        'factures' => 3376,
        'factures_achat' => 90,
        'fournisseurs' => 7,
        'identifiants' => 68,
        'inventaires_ouverture' => 10,
        'invitations_cabinet' => 6,
        'journal_audit' => 1492,
        'liens_cabinet' => 15,
        'liens_des_devis' => 6,
        'lignes_achat' => 238,
        'lignes_avoir' => 146,
        'lignes_avoir_fournisseur' => 2,
        'lignes_de_charge' => 167,
        'lignes_devis' => 393,
        'lignes_facture' => 5037,
        'lignes_inventaire_ouverture' => 21,
        'lignes_recu_paiement' => 160,
        'magasins' => 20,
        'magasins_cabinet' => 1,
        'mandats_pdp' => 2,
        'mouvements_stock' => 4039,
        'notifications' => 189,
        'paiement_allocations' => 3286,
        'paiements' => 3286,
        'paniers_caisse' => 1,
        'periodes_activite' => 21,
        'pieces_de_charge' => 168,
        'pieces_fournisseur' => 1,
        'pieces_verification' => 4,
        'postes_reconnus' => 1,
        'preferences_tableau_de_bord' => 4,
        'preuves_operation' => 5,
        'produits' => 32,
        'recus_paiement' => 160,
        'referentiel_autorisations_magasin' => 51,
        'referentiel_autorisations_societe' => 5,
        'reponses_des_devis' => 3,
        'sequences_documents' => 93,
        'services' => 15,
        'sirets_magasins' => 19,
        'societes' => 21,
        'taux_avoirs_fournisseur' => 2,
        'taux_factures_achat' => 119,
        'telechargements_documents' => 5,
        'tetes_journal_audit' => 97,
        'tetes_registre_fiscal' => 20,
        'traces_audit' => 8,
        'traces_evenements' => 2,
        'transmissions_pdp' => 57,
        'transmissions_propriete' => 2,
        'types_de_charge_de_la_societe' => 2,
        'users' => 68,
        'verifications_societe' => 502,
        'visas_detaxe' => 1,
    ];

    public function test_le_semis_complet_remplit_toute_la_base_et_tous_les_cas(): void
    {
        config(['piliqi.demo_mot_de_passe' => null]);
        $debut = self::aParis('2026-11-13 10:00:00');
        $this->travelTo($debut);
        $passages = $this->guetterLePlanificateur($debut);

        Storage::fake('local');
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->artisan('db:seed')
            ->expectsOutputToContain('Isolation vérifiée')
            ->expectsOutputToContain('Données de démonstration semées.')
            ->assertSuccessful();

        $this->assertTrue(now()->equalTo($debut), 'Le semis rend l\'horloge telle qu\'elle était avant lui.');
        $this->assertSame([[]], $passages->getArrayCopy(), 'Le planificateur ne lance aucune tâche pendant le semis.');
        $this->assertFalse(SemisEnCours::estEnCours());

        $this->assertChaqueTableARemplir();
        $this->assertChaqueEtat();
        $this->assertSame(self::NOMBRES_DE_LIGNES, $this->nombresDeLignes(), 'Le semis n\'a pas semé les mêmes nombres de lignes : il doit rester déterministe (Faker semé, horloge du semis), et ce tableau se met à jour avec le semis.');
        $this->assertSame(
            [['corse'], ['guadeloupe'], ['martinique'], ['metropole'], ['reunion']],
            $this->lignes('select distinct territoire from magasins where territoire is not null order by 1'),
            'Chaque territoire accepté a un magasin (la Guyane et Mayotte ne sont pas des territoires acceptés).',
        );
        $this->assertChaqueSocieteQuiVendAUnRegimeDeTva();
        $this->assertChaqueRoleAUnCompteQuiASesDonnees();
        $this->assertChaqueCompteQuiVendASesVentesEtSonReleve();
        $this->assertLesComptesEtLeursMagasins();
        $this->assertLesCasDesGraphiquesEtDesCaisses();
        $this->assertLaListeDesComptesOuvreChaqueCompteActif();

        $this->assertSame(0, Artisan::call('caisse:verifier', ['--complet' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('app:verifier-journal-audit'), Artisan::output());
        $this->assertSame(0, Artisan::call('app:verifier-isolation'), Artisan::output());
        $this->assertContains('app:cloturer-caisses-automatiquement', $this->passageDuPlanificateurA($debut), 'Les tâches repartent après le semis.');
    }

    /**
     * Chaque table de la base, hors tables techniques du cadre, a au moins
     * une ligne.
     */
    private function assertChaqueTableARemplir(): void
    {
        $vides = array_values(array_filter(
            $this->tables(),
            fn (string $table): bool => ! in_array($table, self::TABLES_TECHNIQUES, true) && ! DB::table($table)->exists(),
        ));

        $this->assertSame([], $vides, 'Tables vides après le semis.');
    }

    /**
     * Chaque valeur de chaque énumération d'état portée par un modèle
     * (statut, état, résultat, motif, mode de fermeture…) apparaît en base,
     * hors ETATS_SANS_DONNEE.
     */
    private function assertChaqueEtat(): void
    {
        $manquantes = [];

        foreach ($this->colonnesDEtat() as $table => $colonnes) {
            foreach ($colonnes as $colonne => $enum) {
                $presentes = DB::table($table)->whereNotNull($colonne)->distinct()->pluck($colonne)->map(fn (mixed $valeur): string => (string) $valeur)->all();

                foreach ($enum::cases() as $cas) {
                    $cle = $table.'.'.$colonne.'.'.$cas->value;

                    if (! in_array((string) $cas->value, $presentes, true) && ! array_key_exists($cle, self::ETATS_SANS_DONNEE)) {
                        $manquantes[] = $cle;
                    }
                }
            }
        }

        $this->assertSame([], $manquantes, 'États jamais semés.');
    }

    /**
     * Chaque société qui a une vente réelle a choisi son régime de TVA
     * (constat de l'éditeur du 15/11/2026), et les régimes semés couvrent
     * chaque cas : réel normal mensuel et trimestriel, réel simplifié,
     * franchise en base, et l'option pour les débits.
     */
    private function assertChaqueSocieteQuiVendAUnRegimeDeTva(): void
    {
        $this->assertSame([], $this->lignes('select s.raison_sociale from societes s where s.regime_tva is null and exists (select 1 from factures f join magasins m on m.id = f.magasin_id where m.societe_id = s.id and m.est_demonstration = false and f.numero is not null)'));
        $this->assertSame(
            [['franchise_en_base'], ['reel_normal_mensuel'], ['reel_normal_trimestriel'], ['reel_simplifie']],
            $this->lignes('select distinct regime_tva from societes where regime_tva is not null order by 1'),
        );
        $this->assertSame([[1]], $this->lignes('select count(*) from societes where option_debits_tva'));
    }

    /**
     * Chaque rôle a au moins un compte qui a ses propres données : un
     * document à son nom, une charge déposée, une préférence de ses
     * graphiques, une notification ou un geste au journal d'audit. Un
     * membre retiré n'a plus d'affectation, donc plus de rôle de magasin
     * (un vendeur retiré, un compte d'entraînement d'une démonstration
     * fermée) : il ne compte pour aucun rôle.
     */
    private function assertChaqueRoleAUnCompteQuiASesDonnees(): void
    {
        $roles = $this->lignes(<<<'SQL'
            select distinct case
                when u.role_societe = 'membre' and m.est_demonstration then 'entrainement'
                when u.role_societe = 'membre' then 'membre ' || a.role
                else u.role_societe end
            from users u
            left join affectations a on a.user_id = u.id
            left join magasins m on m.id = a.magasin_id
            where (u.role_societe <> 'membre' or a.id is not null)
              and (exists (select 1 from factures f where f.cree_par = u.id)
               or exists (select 1 from charges c where c.deposee_par = u.id)
               or exists (select 1 from preferences_tableau_de_bord p where p.user_id = u.id)
               or exists (select 1 from notifications n where n.notifiable_id = u.id)
               or exists (select 1 from journal_audit j where j.auteur_id = u.id))
            order by 1
            SQL);

        $this->assertSame([
            ['administrateur'], ['collaborateur'], ['entrainement'], ['expert_comptable'],
            ['membre observateur'], ['membre responsable'], ['membre vendeur'], ['proprietaire'],
        ], $roles);
    }

    /**
     * Chaque compte qui a vendu a ses ventes et au moins un relevé de sa
     * caisse ; plusieurs comptes vendent, et sur plusieurs jours.
     */
    private function assertChaqueCompteQuiVendASesVentesEtSonReleve(): void
    {
        $this->assertSame([], $this->lignes(<<<'SQL'
            select u.name from users u
            where exists (select 1 from factures f where f.cree_par = u.id and f.numero is not null and f.type_client = 'particulier')
              and not exists (select 1 from clotures_caisse_personnelles c where c.vendeur_id = u.id)
            SQL));
        $this->assertGreaterThan(20, (int) DB::table('factures')->whereNotNull('numero')->distinct()->count('cree_par'));
        $this->assertGreaterThan(390, (int) DB::table('clotures_caisse')->distinct()->count('date_cloture'));
    }

    /**
     * Les magasins que chaque compte actif ouvre : un membre n'ouvre que le
     * magasin de son compte (une même personne a un compte dans chacun de
     * ses deux magasins), un compte d'entraînement n'ouvre que la
     * démonstration, un collaborateur du cabinet n'ouvre que les magasins
     * des clients qui lui sont confiés, un administrateur ceux de sa
     * portée, et aucun compte n'ouvre un magasin d'une autre société hors
     * le cabinet chez ses clients liés.
     */
    private function assertLesComptesEtLeursMagasins(): void
    {
        foreach (User::query()->where('statut', StatutCompte::Actif)->with('societe')->get() as $compte) {
            $magasins = $compte->magasinsAccessibles();

            if ($compte->role_societe?->value === 'membre') {
                $this->assertCount(1, $magasins, "{$compte->name} n'ouvre pas un seul magasin.");
            }

            if ($compte->role_societe?->value !== 'collaborateur' && $compte->role_societe?->value !== 'proprietaire') {
                $this->assertSame([], $magasins->reject(fn ($magasin): bool => $magasin->societe_id === $compte->societe_id)->pluck('code')->all(), "{$compte->name} ouvre un magasin d'une autre société.");
            }
        }

        $this->assertSame(
            [['HALLES', 'vendeur'], ['REPUB', 'responsable']],
            $this->lignes("select m.code, a.role from users u join affectations a on a.user_id = u.id join magasins m on m.id = a.magasin_id where u.name = 'Lucas Moreau' order by m.code"),
            'La même personne a un compte dans chacun de ses deux magasins, promu responsable à la République.',
        );
        $this->assertSame([['EPICERIE']], $this->lignes("select m.code from clients_confies cc join users u on u.id = cc.user_id join liens_cabinet l on l.id = cc.lien_id join magasins m on m.societe_id = l.societe_id where u.name = 'Emma Rousseau' and m.est_demonstration = false"));
        $this->assertSame(['HALLES', 'REPUB'], User::query()->where('name', 'Paul Lefèvre')->sole()->magasinsAccessibles()->pluck('code')->sort()->values()->all());
        $this->assertSame([['EPICERIE']], $this->lignes('select m.code from magasins_cabinet mc join magasins m on m.id = mc.magasin_id'), 'L\'Épicerie n\'ouvre au cabinet que son magasin choisi.');
    }

    /**
     * Chaque règle d'écran du lot DATEOP (graphiques, reçus, relevés de
     * caisse) a une donnée qui la déclenche et une qui ne la déclenche pas :
     * une société validée dont l'exercice finit en décembre (menu
     * « Période » sans « Exercice ») et une dont il finit un autre mois ;
     * un compte dont tous les graphiques sont affichés (« Tous les
     * graphiques sont affichés ») et d'autres qui en ont à ajouter ; une
     * facture avec un reçu de paiement (phrase de la série RP) et une
     * facture payée à son émission, sans reçu ; un relevé de caisse d'un
     * seul mode et un de plusieurs modes (ligne « Total »).
     */
    private function assertLesCasDesGraphiquesEtDesCaisses(): void
    {
        $this->assertSame(
            [[false], [true]],
            $this->lignes("select distinct mois_fin_exercice = 12 from societes where type_activite <> 'cabinet' and validee_le is not null order by 1"),
            'Une société finit son exercice en décembre et une autre un autre mois.',
        );
        $this->assertSame(
            [[false], [true]],
            $this->lignes('select distinct jsonb_array_length(reglages->\'cartes\') = '.count(Graphique::cases()).' from preferences_tableau_de_bord order by 1'),
            'Un compte a tous les graphiques affichés, les autres en ont encore à ajouter.',
        );
        $this->assertSame(
            [[false], [true]],
            $this->lignes('select distinct p.recu_paiement_id is not null from paiements p join paiement_allocations pa on pa.paiement_id = p.id order by 1'),
            'Une facture a un reçu de paiement, une autre a été payée à son émission, sans reçu.',
        );
        $this->assertSame(
            [[false], [true]],
            $this->lignes('select distinct (select count(*) from json_object_keys(encaissements)) > 1 from clotures_caisse_personnelles where json_typeof(encaissements) = \'object\' order by 1'),
            'Un relevé de caisse a un seul mode d\'encaissement, un autre en a plusieurs.',
        );
    }

    /**
     * La liste des comptes donne, pour chaque compte actif, son identifiant
     * et un mot de passe qui l'ouvre, et ce qu'il faut regarder avec lui ;
     * la seconde experte-comptable déclarée, qui n'a pas encore choisi son
     * mot de passe, y est marquée, comme les vendeurs qui gardent leur
     * identifiant.
     */
    private function assertLaListeDesComptesOuvreChaqueCompteActif(): void
    {
        $texte = (string) Storage::disk('local')->get(DatabaseSeeder::FICHIER_DES_COMPTES);
        $comptes = [];

        foreach (explode(PHP_EOL, $texte) as $ligne) {
            if (str_starts_with($ligne, '| ')) {
                $cellules = array_map(trim(...), explode(' | ', Str::between($ligne, '| ', ' |')));
                $comptes[$cellules[4]] = $cellules;
            }
        }

        unset($comptes['Identifiant']);

        foreach (User::query()->where('statut', StatutCompte::Actif)->get() as $compte) {
            $identifiant = (string) $compte->identifiantDeConnexion();
            $this->assertArrayHasKey($identifiant, $comptes, "{$compte->name} manque à la liste des comptes.");
            $cellules = $comptes[$identifiant];
            $this->assertNotSame('', end($cellules), "La liste ne dit pas quoi regarder avec {$compte->name}.");

            if (str_starts_with($cellules[6], 'pas encore choisi')) {
                $this->assertSame('Sophie Petit', $compte->name);

                continue;
            }

            $motDePasse = $cellules[6] === ComptesSemes::SON_IDENTIFIANT ? $identifiant : $cellules[6];
            $this->assertTrue(Hash::check($motDePasse, (string) $compte->password), "Le mot de passe listé n'ouvre pas le compte de {$compte->name}.");
        }
    }

    /**
     * Le nombre de lignes de chaque table hors tables techniques du cadre.
     *
     * @return array<string, int>
     */
    private function nombresDeLignes(): array
    {
        $nombres = [];

        foreach ($this->tables() as $table) {
            if (! in_array($table, self::TABLES_TECHNIQUES, true)) {
                $nombres[$table] = DB::table($table)->count();
            }
        }

        return $nombres;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map(fn (object $ligne): string => (string) $ligne->tablename, DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'));
    }

    /**
     * Les colonnes d'état de chaque table : chaque attribut d'un modèle
     * d'app/ converti en une énumération dont le nom commence par Statut,
     * Etat, Resultat ou Motif, ou qui dit le mode de fermeture d'une caisse.
     *
     * @return array<string, array<string, class-string<BackedEnum>>>
     */
    private function colonnesDEtat(): array
    {
        $colonnes = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
            $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($fichier->getPathname(), app_path().DIRECTORY_SEPARATOR));

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $modele = new $classe;

            foreach ($modele->getCasts() as $colonne => $conversion) {
                $enum = explode(':', $conversion)[0];

                if (enum_exists($enum) && is_subclass_of($enum, BackedEnum::class) && preg_match('/^(Statut|Etat|Resultat|Motif|ModeFermeture)/', class_basename($enum)) === 1) {
                    $colonnes[$modele->getTable()][$colonne] = $enum;
                }
            }
        }

        ksort($colonnes);

        return $colonnes;
    }

    /**
     * Un écouteur qui, à la première vente d'un magasin réel, fait passer
     * le planificateur à l'heure réelle, comme php artisan schedule:work
     * qu'un autre processus garde ouvert : il ne doit rien lancer.
     *
     * @return \ArrayObject<int, list<string>|string>
     */
    private function guetterLePlanificateur(CarbonImmutable $heureReelle): \ArrayObject
    {
        $passages = new \ArrayObject;

        Event::listen(FactureEmise::class, function (FactureEmise $emise) use ($heureReelle, $passages): void {
            if (count($passages) > 0 || ! str_contains((string) $emise->facture->numero, '-HALLES-')) {
                return;
            }

            $passages[0] = 'attendu';
            DB::afterCommit(function () use ($heureReelle, $passages): void {
                $passages[0] = $this->passageDuPlanificateurA($heureReelle);
            });
        });

        return $passages;
    }

    /**
     * Un passage du planificateur à cet instant : chaque tâche due dont
     * les conditions passent s'exécute. Rend les commandes exécutées, et
     * l'horloge à l'heure où elle était.
     *
     * @return list<string>
     */
    private function passageDuPlanificateurA(CarbonImmutable $instant): array
    {
        $heureDAvant = CarbonImmutable::now();
        Carbon::setTestNow($instant);

        try {
            $executees = [];

            foreach (app(Schedule::class)->dueEvents($this->app) as $tache) {
                if ($tache->filtersPass($this->app)) {
                    $commande = Str::after((string) $tache->command, "'artisan' ");
                    $this->assertSame(0, Artisan::call($commande), Artisan::output());
                    $executees[] = $commande;
                }
            }

            return $executees;
        } finally {
            Carbon::setTestNow($heureDAvant);
        }
    }

    /**
     * @param  list<int|string>  $liaisons
     * @return list<list<mixed>>
     */
    private function lignes(string $requete, array $liaisons = []): array
    {
        return array_map(fn (object $ligne): array => array_values((array) $ligne), DB::select($requete, $liaisons));
    }
}
