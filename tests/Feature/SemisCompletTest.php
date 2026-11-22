<?php

namespace Tests\Feature;

use App\Console\SemisEnCours;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Jobs\EnvoyerLeLienDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Isolation\ControlesDeLApplication;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Semis\ComptesSemes;
use Database\Seeders\Semis\SemisComplet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
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
        'actions_traitees' => 4745,
        'actions_traitees_de_la_societe' => 348,
        'affectations' => 40,
        'autorisations_affectations' => 533,
        'autorisations_cabinet' => 41,
        'autorisations_societe' => 6,
        'avoir_allocations' => 121,
        'avoirs' => 118,
        'avoirs_fournisseur' => 2,
        'bordereaux_detaxe' => 2,
        'cabinets' => 4,
        'changements_fin_exercice' => 1,
        'charges' => 181,
        'clients' => 46,
        'clients_confies' => 2,
        'clotures_caisse' => 2621,
        'clotures_caisse_personnelles' => 3329,
        'clotures_caisse_personnelles_factures' => 4007,
        'clotures_caisse_personnelles_paiements' => 3918,
        'codes_confirmation' => 26,
        'comptes_expert_comptable' => 3,
        'consultations_vies' => 5,
        'demandes_inscription' => 1,
        'demonstrations' => 15,
        'devis' => 225,
        'ecarts_sirene' => 2,
        'enregistrements_fiscaux' => 10830,
        'envois_documents' => 13,
        'evenements_transmission_pdp' => 61,
        'factures' => 4008,
        'factures_achat' => 185,
        'fournisseurs' => 12,
        'identifiants' => 67,
        'inventaires_ouverture' => 11,
        'invitations_cabinet' => 6,
        'journal_audit' => 1811,
        'liens_cabinet' => 15,
        'liens_des_devis' => 6,
        'lignes_achat' => 371,
        'lignes_avoir' => 146,
        'lignes_avoir_fournisseur' => 2,
        'lignes_de_charge' => 180,
        'lignes_devis' => 393,
        'lignes_facture' => 5988,
        'lignes_inventaire_ouverture' => 23,
        'lignes_recu_paiement' => 160,
        'magasins' => 22,
        'magasins_cabinet' => 1,
        'mandats_pdp' => 2,
        'messages_vers_la_demonstration' => 26,
        'mouvements_stock' => 5124,
        'notifications' => 190,
        'paiement_allocations' => 3918,
        'paiements' => 3918,
        'paniers_caisse' => 1,
        'periodes_activite' => 23,
        'pieces_de_charge' => 181,
        'pieces_fournisseur' => 1,
        'pieces_verification' => 4,
        'postes_reconnus' => 1,
        'preferences_tableau_de_bord' => 4,
        'preuves_operation' => 5,
        'produits' => 70,
        'recus_paiement' => 160,
        'referentiel_autorisations_magasin' => 51,
        'referentiel_autorisations_societe' => 5,
        'reponses_des_devis' => 3,
        'sequences_documents' => 98,
        'services' => 9,
        'sirets_magasins' => 23,
        'site_de_la_base' => 1,
        'societes' => 21,
        'taux_avoirs_fournisseur' => 2,
        'taux_factures_achat' => 242,
        'telechargements_documents' => 5,
        'tetes_journal_audit' => 105,
        'tetes_registre_fiscal' => 22,
        'traces_audit' => 8,
        'traces_evenements' => 2,
        'transmissions_pdp' => 57,
        'transmissions_propriete' => 2,
        'types_de_charge_de_la_societe' => 2,
        'users' => 67,
        'verifications_societe' => 566,
        'visas_detaxe' => 1,
    ];

    public function test_le_semis_complet_remplit_toute_la_base_et_tous_les_cas(): void
    {
        config(['piliqi.semis_mot_de_passe' => null]);
        $debut = self::aParis('2026-11-13 10:00:00');
        $this->travelTo($debut);
        $passages = $this->guetterLePlanificateur($debut);

        Storage::fake('local');
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->artisan('db:seed')
            ->expectsOutputToContain('Isolation vérifiée')
            ->expectsOutputToContain('Semis terminé.')
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
        $this->assertLesCasDeLEcartDesPrixDAchat();
        $this->assertLesFormulesDArrondiDuPrixDeVente();
        $this->assertLesDatesDeLOperation();
        $this->assertLaListeDesComptesOuvreChaqueCompteActif();
        $this->assertLesRenouvellementsDesDemonstrations();

        $this->assertSame(0, Artisan::call('caisse:verifier', ['--complet' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('app:verifier-journal-audit'), Artisan::output());
        $this->assertSame(0, Artisan::call('app:verifier-isolation'), Artisan::output());
        $this->assertContains('app:cloturer-caisses-automatiquement', $this->passageDuPlanificateurA($debut), 'Les tâches repartent après le semis.');
    }

    /**
     * Les renouvellements des liens des démonstrations (lot DEMO-COPIE, F.7)
     * de part et d'autre de chaque phrase de la carte : Maison Ferrand,
     * accusé (« Ancien lien coupé le … ») ; le cabinet Martin, sans accusé
     * depuis plus d'un quart d'heure (« La démonstration n'a pas encore reçu
     * le renouvellement… ») ; la Boulangerie Duval, accusé, mais son e-mail
     * en échec après ses trois tentatives, dont seul son propriétaire a
     * l'avis ; FLEURS DE SAISON, qui attend son code, a sa copie prête. La
     * coupure « en cours » (moins d'un quart d'heure) dépend de
     * l'heure de la consultation : le semis, qui finit la veille, ne peut pas
     * la laisser à l'écran (voir le compte rendu du lot).
     */
    private function assertLesRenouvellementsDesDemonstrations(): void
    {
        $demonstrations = Demonstration::query()->withoutGlobalScopes()->where('generation_du_lien', '>', 1)->get();
        $nom = static fn (Demonstration $demonstration): string => (string) DB::table('societes')->where('id', $demonstration->societe_id)->value('raison_sociale');
        $sansAccuse = $demonstrations->filter(fn (Demonstration $demonstration): bool => $demonstration->renouvellementSansAccuse());

        $this->assertSame(['MARTIN EXPERTISE COMPTABLE'], $sansAccuse->map($nom)->values()->all());
        $this->assertTrue($sansAccuse->every(fn (Demonstration $demonstration): bool => $demonstration->lien_tire_le !== null && $demonstration->lien_tire_le->addMinutes(15)->isPast()), 'Sans accusé depuis plus d\'un quart d\'heure : la carte le dit.');
        $accuses = $demonstrations->reject(fn (Demonstration $demonstration): bool => $demonstration->renouvellementSansAccuse())->map($nom)->sort()->values()->all();
        $this->assertSame(['BOULANGERIE DUVAL', 'MAISON FERRAND'], $accuses, 'Un renouvellement accusé : « Ancien lien coupé le … ».');

        $avis = DatabaseNotification::query()->where('type', AvisDeLEquipe::class)->get()
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['body'] ?? null) === EnvoyerLeLienDeLaDemonstration::MESSAGE_NON_PARTI)
            ->map(fn (DatabaseNotification $notification): string => (string) DB::table('users')->join('societes', 'societes.id', '=', 'users.societe_id')->where('users.id', $notification->notifiable_id)->value('societes.raison_sociale'))
            ->values()->all();
        $this->assertSame(['BOULANGERIE DUVAL'], $avis, 'Seul le propriétaire dont l\'e-mail du lien n\'est pas parti en a l\'avis.');

        $pretesAvantLaValidation = DB::table('demonstrations')
            ->join('societes', 'societes.id', '=', 'demonstrations.societe_id')
            ->where('demonstrations.etat', EtatDeLaDemonstration::Prete->value)
            ->whereNull('societes.validee_le')
            ->pluck('societes.raison_sociale')->all();
        $this->assertSame(['FLEURS DE SAISON'], $pretesAvantLaValidation, 'Une copie prête d\'une société qui attend encore son code.');
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
        $this->assertSame([], $this->lignes('select s.raison_sociale from societes s where s.regime_tva is null and exists (select 1 from factures f join magasins m on m.id = f.magasin_id where m.societe_id = s.id and f.numero is not null)'));
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
     * membre retiré n'a plus d'affectation, donc plus de rôle de magasin :
     * il ne compte pour aucun rôle.
     */
    private function assertChaqueRoleAUnCompteQuiASesDonnees(): void
    {
        $roles = $this->lignes(<<<'SQL'
            select distinct case
                when u.role_societe = 'membre' then 'membre ' || a.role
                else u.role_societe end
            from users u
            left join affectations a on a.user_id = u.id
            where (u.role_societe <> 'membre' or a.id is not null)
              and (exists (select 1 from factures f where f.cree_par = u.id)
               or exists (select 1 from charges c where c.deposee_par = u.id)
               or exists (select 1 from preferences_tableau_de_bord p where p.user_id = u.id)
               or exists (select 1 from notifications n where n.notifiable_id = u.id)
               or exists (select 1 from journal_audit j where j.auteur_id = u.id))
            order by 1
            SQL);

        $this->assertSame([
            ['administrateur'], ['collaborateur'], ['expert_comptable'],
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
     * ses deux magasins), un collaborateur du cabinet n'ouvre que les magasins
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
        $this->assertSame([['EPICERIE']], $this->lignes("select m.code from clients_confies cc join users u on u.id = cc.user_id join liens_cabinet l on l.id = cc.lien_id join magasins m on m.societe_id = l.societe_id where u.name = 'Emma Rousseau'"));
        $this->assertSame(['ENTREPOT', 'EPHEMERE', 'FDF', 'HALLES', 'MARCHE', 'REPUB'], User::query()->where('name', 'Paul Lefèvre')->sole()->magasinsAccessibles()->pluck('code')->sort()->values()->all(), 'Le collaborateur du cabinet ouvre les six magasins de Maison Ferrand, Fort-de-France et les trois magasins d\'achats compris (« tous les magasins, présents et futurs »).');
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
     * Les trois formules d'arrondi du prix de vente et le calcul sans
     * arrondi (décision de l'éditeur du 22/11/2026), chacun dans un magasin
     * qui a activé le barème par la vraie action des paramètres, et les
     * produits nés d'une facture d'achat validée dont le prix de vente vient
     * du barème : de part et d'autre de chaque limite de chaque formule, un
     * prix arrondi vers le haut, vers le bas, pile au milieu, un prix exact
     * sous le milieu alors que son prix au centime l'atteint, et un prix que
     * la formule laisse tel quel. Chaque prix est recalculé ici, en centimes
     * entiers, sans le code du barème.
     */
    private function assertLesFormulesDArrondiDuPrixDeVente(): void
    {
        $this->assertSame(
            [['EPICERIE', 'aucun'], ['FDF', 'euro'], ['HALLES', 'x9'], ['REPUB', 'cinq']],
            $this->lignes('select code, bareme_prix_vente->>\'arrondi\' from magasins where prix_vente_automatique order by code'),
            'Chaque formule d\'arrondi, et le calcul sans arrondi, a son magasin.',
        );
        $this->assertGreaterThan(0, DB::table('magasins')->where('prix_vente_automatique', false)->count(), 'Les autres magasins gardent le calcul désactivé.');
        $this->assertSame(4, DB::table('journal_audit')->where('action', 'parametres_du_magasin_modifies')->whereRaw('nouvelles_valeurs->\'bareme_prix_vente\'->>\'arrondi\' is not null')->count(), 'Chaque réglage du barème s\'est inscrit au journal d\'audit.');

        $paliers = [[30, 5], [100, 40], [1000, 400], [5000, 800]];
        $produits = [
            'HALLES.SAVON-ALEP' => ['1.85', 2000, 'x9', '9.00'],
            'HALLES.CHAUFFE-PLAT' => ['0.20', 2000, 'x9', '0.30'],
            'HALLES.PLANCHE-HETRE' => ['6.40', 2000, 'x9', '9.00'],
            'HALLES.COFFRET-DEGUST' => ['29.50', 2000, 'x9', '49.00'],
            'HALLES.CARAFE-SOUFFLEE' => ['37.83', 2000, 'x9', '49.00'],
            'REPUB.CAHIER-SPIRALES' => ['2.25', 2000, 'cinq', '10.00'],
            'REPUB.CAHIER-BROUILLON' => ['2.246', 2000, 'cinq', '5.00'],
            'REPUB.GOMME-BLANCHE' => ['0.20', 2000, 'cinq', '0.30'],
            'REPUB.LAMPE-BUREAU' => ['14.00', 2000, 'cinq', '25.00'],
            'REPUB.PORTE-DOCS-CUIR' => ['62.00', 2000, 'cinq', '100.00'],
            'FDF.FONTAINE-INT' => ['80.00', 850, 'euro', '109.00'],
            'FDF.SAVON-MARSEILLE' => ['1.065', 850, 'euro', '5.00'],
            'FDF.VANILLE-SACHET' => ['0.20', 850, 'euro', '0.27'],
            'FDF.BOUGIE-MASSAGE' => ['6.40', 850, 'euro', '11.00'],
            'FDF.PAREO-COTON' => ['5.10', 850, 'euro', '10.00'],
            'EPICERIE.TISANE-SOIR' => ['2.35', 550, 'aucun', '6.70'],
            'EPICERIE.SEL-GUERANDE' => ['0.20', 550, 'aucun', '0.26'],
            'EPICERIE.CONFITURE-FIGUE' => ['3.10', 550, 'aucun', '7.49'],
        ];
        $cles = array_keys($produits);
        $lus = DB::table('produits')->join('magasins', 'magasins.id', '=', 'produits.magasin_id')
            ->whereIn(DB::raw("magasins.code || '.' || produits.reference"), $cles)
            ->selectRaw("magasins.code || '.' || produits.reference as cle, produits.prix_vente_ttc as prix")
            ->pluck('prix', 'cle')->all();
        $nes = DB::table('lignes_achat')->join('produits', fn ($jointure) => $jointure->on('produits.magasin_id', '=', 'lignes_achat.magasin_id')->on('produits.id', '=', 'lignes_achat.produit_id'))
            ->join('factures_achat', fn ($jointure) => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
            ->join('magasins', 'magasins.id', '=', 'produits.magasin_id')
            ->where('factures_achat.statut', 'validee')
            ->whereIn(DB::raw("magasins.code || '.' || produits.reference"), $cles)
            ->selectRaw("magasins.code || '.' || produits.reference as cle")
            ->pluck('cle')->all();
        $cas = [];

        foreach ($produits as $cle => [$achat, $taux, $formule, $prixAttendu]) {
            [$prix, $genres] = $this->prixDuBaremeEnEntiers($achat, $taux, $formule, $paliers);
            $this->assertSame($prixAttendu, number_format($prix / 100, 2, '.', ''), "{$cle} : le prix du semis écrit est celui du calcul en entiers.");
            $this->assertEqualsWithDelta((float) $prixAttendu, (float) ($lus[$cle] ?? -1), 0.0001, "{$cle} : le produit a le prix que le barème donne.");
            $this->assertContains($cle, $nes, "{$cle} est né d'une facture d'achat validée.");
            $cas[$formule] = array_values(array_unique([...($cas[$formule] ?? []), ...$genres]));
        }

        foreach (['x9', 'euro', 'cinq'] as $formule) {
            foreach (['haut', 'bas', 'milieu', 'sous_le_milieu', 'reste'] as $genre) {
                $this->assertContains($genre, $cas[$formule], "La formule {$formule} a un produit « {$genre} ».");
            }
        }

        $this->assertSame(['centime'], $cas['aucun'], 'Sans arrondi, chaque produit a son prix au centime.');
    }

    /**
     * Le prix de vente d'un prix d'achat, en centimes entiers, sans le code
     * du barème : (achat + bénéfice) x (1 + TVA) arrondi une seule fois au
     * multiple le plus proche du pas de la formule, un prix pile au milieu
     * montant, puis ce que chaque formule en garde ; et ce que le cas
     * éprouve (haut, bas, milieu, sous_le_milieu, reste, ou centime).
     *
     * @param  list<array{int, int}>  $paliers  « jusqu'à » et bénéfice, en centimes
     * @return array{int, list<string>}
     */
    private function prixDuBaremeEnEntiers(string $achat, int $taux, string $formule, array $paliers): array
    {
        [$euros, $decimales] = array_pad(explode('.', $achat), 2, '');
        $dixMilliemes = (int) $euros * 10000 + (int) str_pad($decimales, 4, '0');
        $benefice = 2000;

        foreach ($paliers as [$jusquA, $beneficeDuPalier]) {
            if ($dixMilliemes < $jusquA * 100) {
                $benefice = $beneficeDuPalier;

                break;
            }
        }

        $exact = ($dixMilliemes + $benefice * 100) * (10000 + $taux);
        $plusProche = static fn (int $unite): int => intdiv($exact, $unite) + (2 * ($exact % $unite) >= $unite ? 1 : 0);
        $cible = $plusProche(1_000_000);

        if ($formule === 'aucun') {
            return [$cible, ['centime']];
        }

        $pas = ['x9' => 1000, 'euro' => 100, 'cinq' => 500][$formule];
        $multiple = $plusProche($pas * 1_000_000) * $pas;
        $reste = $formule === 'x9' ? $multiple < 1000 : $multiple <= 0;
        $prix = $reste ? $cible : ($formule === 'x9' ? $multiple - 100 : $multiple);
        $genres = [];

        if ($reste) {
            $genres[] = 'reste';
        } else {
            $genres[] = $prix > $cible ? 'haut' : 'bas';
        }

        if (! $reste && 2 * ($exact % ($pas * 1_000_000)) === $pas * 1_000_000) {
            $genres[] = 'milieu';
        }

        if (! $reste && 2 * ($exact % ($pas * 1_000_000)) < $pas * 1_000_000 && 2 * ($cible % $pas) >= $pas) {
            $genres[] = 'sous_le_milieu';
        }

        return [$prix, $genres];
    }

    /**
     * Les règles visibles de la carte « Écart des prix d'achat » (le
     * dix-neuvième graphique ; décision de l'éditeur du 21/11/2026, D76,
     * B10), chacune avec une donnée qui la déclenche et une qui ne la
     * déclenche pas, de part et d'autre de sa limite : les six magasins de
     * Maison Ferrand lus un par un et tous ensemble, pour les 12 derniers
     * mois, l'Entrepôt pour l'année précédente et les Halles pour l'année en
     * cours. Chaque état de l'Entrepôt, mois après mois, est écrit en
     * centimes. Puis chaque règle de lecture (date, prix, ordre, statut,
     * nom, magasins) et qui voit la carte.
     */
    private function assertLesCasDeLEcartDesPrixDAchat(): void
    {
        $societe = Societe::query()->where('siren', SemisComplet::SIREN_COMMERCE)->sole();
        $proprietaire = $societe->proprietaire()->sole();
        $vues = $this->lesVuesDeLaCarte($proprietaire, $societe);

        $this->assertLesDeuxCotesDeChaqueRegleDeLaCarte($vues);
        $this->assertLesMoisDeLEntrepot($vues['ENTREPOT']);
        $this->assertLesDouzeMoisDeLEntrepot($vues['ENTREPOT'], $vues['ENTREPOT:annee-precedente']);
        $this->assertLEphemereEtLeMarche($vues['EPHEMERE'], $vues['MARCHE']);
        $this->assertLesRegleDeLectureDesAchats($vues);
        $this->assertQuiVoitLaCarte($societe);
        $this->assertLesPreferencesDeLaCarte($societe);
    }

    /**
     * Le calcul de la carte pour la propriétaire de Maison Ferrand, qui
     * choisit tour à tour « Tous mes magasins » et chacun de ses six
     * magasins, pour les 12 derniers mois, puis l'Entrepôt pour l'année
     * précédente et les Halles pour l'année en cours.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lesVuesDeLaCarte(User $proprietaire, Societe $societe): array
    {
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $societe);
        $uuid = fn (string $code): string => (string) $this->magasinDeMaisonFerrand($code)->uuid;
        $vues = ['TOUS' => $this->calculDeLaCarte($perimetre)];

        foreach (['ENTREPOT', 'EPHEMERE', 'MARCHE', 'HALLES', 'REPUB', 'FDF'] as $code) {
            $vues[$code] = $this->calculDeLaCarte($perimetre->restreintA($uuid($code)));
        }

        $vues['ENTREPOT:annee-precedente'] = $this->calculDeLaCarte($perimetre->restreintA($uuid('ENTREPOT')), PeriodeDuTableauDeBord::AnneePrecedente);
        $vues['HALLES:annee-en-cours'] = $this->calculDeLaCarte($perimetre->restreintA($uuid('HALLES')), PeriodeDuTableauDeBord::AnneeEnCours);

        return $vues;
    }

    /**
     * Le magasin de ce code de Maison Ferrand (le code d'un magasin est
     * unique dans sa société, non dans la base).
     */
    private function magasinDeMaisonFerrand(string $code): Magasin
    {
        $societe = Societe::query()->where('siren', SemisComplet::SIREN_COMMERCE)->sole();

        return Magasin::query()->withoutGlobalScopes()->where('societe_id', $societe->getKey())->where('code', $code)->sole();
    }

    /**
     * Ce que la série de la carte porte pour ce périmètre et cette période.
     *
     * @return array<string, mixed>
     */
    private function calculDeLaCarte(Perimetre $perimetre, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois): array
    {
        $cartes = [CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)];
        $calcul = app(SeriesDesGraphiques::class)->pour(new CalculDesSeries($perimetre, new ReglagesDuTableauDeBord($cartes, $periode), $cartes))[Graphique::EcartDesPrixDAchat->value]->ecartDesPrixDAchat;
        $this->assertNotNull($calcul, 'La série de la carte porte son calcul.');

        return $calcul;
    }

    /**
     * Chaque règle visible de la carte est déclenchée par une des lectures
     * semées et ne l'est pas par une autre (une règle n'est jugée que là où
     * elle a un sens : un mois sans achat n'a pas de produit masqué).
     *
     * @param  array<string, array<string, mixed>>  $vues
     */
    private function assertLesDeuxCotesDeChaqueRegleDeLaCarte(array $vues): void
    {
        /** @var array<string, list<bool>> $observees */
        $observees = [];
        $noter = static function (string $regle, ?bool $declenchee) use (&$observees): void {
            if ($declenchee !== null) {
                $observees[$regle][] = $declenchee;
            }
        };

        foreach ($vues as $calcul) {
            for ($mois = 0; $mois < 12; $mois++) {
                $lecture = TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, $mois);
                $m = $calcul['calculs'][$mois];
                $aDesAchats = $m['premiers'] !== [];
                $compare = $m['n'] > 0;
                $masques = count($m['premiers']) - count(array_unique(array_column(array_filter($m['pas'], static fn (array $pas): bool => $pas['ecart'] !== 0), 'i')));
                $texte = implode(' ', array_map(static fn (array $ligne): string => implode(' ', array_column($ligne['cellules'], 't')), $lecture['calcul']['lignes']));
                $quatreChiffres = array_filter([...array_column($m['premiers'], 'e'), ...array_column($m['pas'], 'e')], static fn (array $achat): bool => $achat['q'] >= 1000) !== [];

                $noter('mois : aucun achat', $lecture['pastille']['texte'] === 'Aucun achat ce mois-ci');
                $noter('mois : des achats mais aucun produit acheté deux fois', $lecture['pastille']['texte'] === 'Aucun achat comparable ce mois-ci');
                $noter('mois : écart positif (payé plus cher)', $compare ? $lecture['pastille']['classe'] === 'pastille plus' : null);
                $noter('mois : écart négatif (payé moins cher)', $compare ? $lecture['pastille']['classe'] === 'pastille moins' : null);
                $noter('mois : écart nul (prix inchangés)', $compare ? $lecture['pastille']['texte'] === 'Prix inchangés' : null);
                $noter('mois : écart nul avec hausses et baisses de même montant', $compare && $m['ecart'] === 0 ? $m['hausses'] > 0 : null);
                $noter('mois : un seul achat comparé', $compare ? $m['n'] === 1 && str_contains($lecture['detail'], 'sur l’achat comparé') : null);
                $noter('mois : plusieurs achats comparés', $compare ? $m['n'] > 1 && str_contains($lecture['detail'], 'sur les '.$m['n'].' achats comparés') : null);
                $noter('mois : sans hausses', $compare ? $m['hausses'] === 0 : null);
                $noter('mois : sans baisses', $compare ? $m['baisses'] === 0 : null);
                $noter('mois : aucun produit masqué', $aDesAchats ? $masques === 0 : null);
                $noter('mois : un produit masqué', $aDesAchats ? $masques === 1 && str_contains($lecture['calcul']['notes'][0], '(1 produit sans écart est masqué).') : null);
                $noter('mois : plusieurs produits masqués', $aDesAchats ? $masques > 1 && str_contains($lecture['calcul']['notes'][0], '('.$masques.' produits sans écart sont masqués).') : null);
                $noter('mois : « Aucun produit avec un écart ce mois-ci »', $aDesAchats ? str_contains($texte, 'Aucun produit avec un écart ce mois-ci.') : null);
                $noter('mois : pourcentage « +0,0 % » (signe de l’écart, non de l’arrondi)', $compare ? str_contains($lecture['detail'], '+0,0 %') : null);
                $noter('mois : pourcentage « −0,0 % »', $compare ? str_contains($lecture['detail'], '−0,0 %') : null);
                $noter('mois : base nulle, pourcentage « — »', $compare ? str_contains($lecture['detail'], '. — sur') : null);
                $noter('mois : quantité de quatre chiffres', $aDesAchats ? $quatreChiffres : null);
            }

            $lecture = TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS, 11);
            $p = $calcul['calculDeLaPeriode'];
            $sans = $p['uneFois'] + $p['memePrix'];
            $avecEcart = $p['visibles'] > 0;
            $phrases = implode(' ', [$lecture['detail'], ...$lecture['calcul']['notes']]);
            $quatreChiffres = array_filter(array_merge([], ...array_map(static fn (array $ligne): array => [$ligne['ref'], ...array_column($ligne['pas'], 'e')], $p['lignes'])), static fn (array $achat): bool => $achat['q'] >= 1000) !== [];

            $noter('12 mois : écart positif', $p['ecart'] > 0);
            $noter('12 mois : écart négatif', $p['ecart'] < 0);
            $noter('12 mois : au moins un produit avec écart', $avecEcart);
            $noter('12 mois : un seul produit avec écart (« Total du produit avec écart »)', $avecEcart ? $p['visibles'] === 1 && str_contains($lecture['detail'], 'Total du produit avec écart') : null);
            $noter('12 mois : plusieurs produits avec écart (« Total des N produits avec écart »)', $avecEcart ? $p['visibles'] > 1 && str_contains($lecture['detail'], 'Total des '.$p['visibles'].' produits avec écart') : null);
            $noter('12 mois : des produits sans écart', $sans > 0);
            $noter('12 mois : un seul produit sans écart (« ne est pas compté »)', $sans > 0 ? $sans === 1 && str_contains($phrases, 'n’a pas d’écart et ne est pas compté') : null);
            $noter('12 mois : plusieurs produits sans écart (« ne sont pas comptés »)', $sans > 0 ? $sans > 1 && str_contains($phrases, 'n’ont pas d’écart et ne sont pas comptés') : null);
            $noter('12 mois : produits achetés une seule fois', $sans > 0 ? $p['uneFois'] > 0 : null);
            $noter('12 mois : produits toujours au même prix', $sans > 0 ? $p['memePrix'] > 0 : null);
            $noter('12 mois : aucun achat à comparer', $lecture['detail'] === 'Aucun achat à comparer sur les 12 mois.');
            $noter('12 mois : aucun achat au même prix que le départ caché', $avecEcart ? $p['egaux']['n'] === 0 : null);
            $noter('12 mois : un achat d’une pièce au même prix que le départ caché', $avecEcart ? $p['egaux']['n'] === 1 && $p['egaux']['qte'] === 1 : null);
            $noter('12 mois : plusieurs achats au même prix que le départ cachés', $avecEcart ? $p['egaux']['n'] > 1 : null);
            $noter('12 mois : quantité de quatre chiffres', $avecEcart ? $quatreChiffres : null);
            $noter('12 mois : « Payé en plus » non nul', $p['hausses'] > 0);
            $noter('12 mois : « Payé en moins » non nul', $p['baisses'] > 0);
            $noter('12 mois : base nulle, « soit — »', $p['base'] === 0);
        }

        $this->assertCount(36, $observees, 'Chaque règle de la carte est jugée au moins une fois : '.implode(' | ', array_keys($observees)));

        $sansUnCote = [];

        foreach ($observees as $regle => $valeurs) {
            if (! in_array(true, $valeurs, true)) {
                $sansUnCote[] = "Aucune donnée semée ne déclenche la règle « {$regle} ».";
            }

            if (! in_array(false, $valeurs, true)) {
                $sansUnCote[] = "Aucune donnée semée ne laisse la règle « {$regle} » sans effet.";
            }
        }

        $this->assertSame([], $sansUnCote);
    }

    /**
     * Les douze mois de l'Entrepôt, un état par mois, en centimes : mois
     * sans achat, achats sans produit acheté deux fois, prix inchangés
     * (deux produits masqués), hausse seule sur un achat comparé, baisse
     * seule sur trois achats, hausse et baisse de même montant, un centime
     * par pièce sur 100 pièces dans un sens et dans l'autre, un produit
     * offert puis payé (base nulle, trois produits masqués), un produit
     * masqué et une facture corrigée, un mois ordinaire sans produit
     * masqué, un mois ordinaire à écart négatif.
     *
     * @param  array<string, mixed>  $entrepot
     */
    private function assertLesMoisDeLEntrepot(array $entrepot): void
    {
        $this->assertSame(
            ['déc. 2025', 'janv. 2026', 'févr. 2026', 'mars 2026', 'avr. 2026', 'mai 2026', 'juin 2026', 'juil. 2026', 'août 2026', 'sept. 2026', 'oct. 2026', 'nov. 2026'],
            $entrepot['mois'],
            'Les 12 derniers mois du 13/11/2026 sont ceux de la maquette.',
        );

        // Par mois : achats comparés, hausses, baisses, écart, base, produits achetés, produits masqués.
        $attendus = [
            [0, 0, 0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0, 2, 2],
            [2, 0, 0, 0, 60 * 540 + 36 * 310, 2, 2],
            [1, 2400, 0, 2400, 80 * 520, 1, 0],
            [2, 0, 3000, -3000, 2 * 20 * 1180, 1, 0],
            [2, 1000, 1000, 0, 10 * 3200 + 20 * 800, 2, 0],
            [1, 100, 0, 100, 100 * 4000, 1, 0],
            [1, 0, 100, -100, 100 * 4002, 1, 0],
            [1, 10000, 0, 10000, 0, 4, 3],
            [1, 960, 0, 960, 48 * 390, 2, 1],
            [3, 3800, 0, 3800, 80 * 525 + 40 * 625 + 1200 * 86, 3, 0],
            [2, 0, 2140, -2140, 24 * 1120 + 10 * 3350, 2, 0],
        ];

        foreach ($attendus as $mois => $attendu) {
            $m = $entrepot['calculs'][$mois];
            $avecEcart = count(array_unique(array_column(array_filter($m['pas'], static fn (array $pas): bool => $pas['ecart'] !== 0), 'i')));

            $this->assertSame($attendu, [$m['n'], $m['hausses'], $m['baisses'], $m['ecart'], $m['base'], count($m['premiers']), count($m['premiers']) - $avecEcart], "Entrepôt, {$m['m']} : achats comparés, hausses, baisses, écart, base, produits achetés et masqués.");
        }

        $lire = static fn (int $mois): array => TextesDeLEcartDesPrixDAchat::lecture($entrepot, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, $mois);

        $this->assertSame('Aucun achat ce mois-ci', $lire(0)['pastille']['texte']);
        $this->assertSame('Aucun achat comparable ce mois-ci', $lire(1)['pastille']['texte']);
        $this->assertSame('Aucun produit acheté deux fois dans le mois : pas d’écart à calculer.', $lire(1)['detail']);
        $this->assertSame('Prix inchangés', $lire(2)['pastille']['texte']);
        $this->assertStringContainsString('+0,0 %', $lire(6)['detail']);
        $this->assertStringContainsString('−0,0 %', $lire(7)['detail']);
        $this->assertStringContainsString(". — sur l’achat comparé, qui vaut 0,00\u{a0}€", $lire(8)['detail']);
        $this->assertStringContainsString('(3 produits sans écart sont masqués).', $lire(8)['calcul']['notes'][0]);
        $this->assertStringContainsString('(1 produit sans écart est masqué).', $lire(9)['calcul']['notes'][0]);
        $this->assertStringEndsWith('Seuls les produits qui ont un écart dans le mois apparaissent.', $lire(10)['calcul']['notes'][0]);
    }

    /**
     * Les douze mois de l'Entrepôt : les neuf produits avec écart, les trois
     * achetés une seule fois, les deux toujours au même prix, aucun achat au
     * même prix que son départ ; puis l'année précédente, où les
     * achats d'avant la période (novembre 2025) sont ceux de la période.
     *
     * @param  array<string, mixed>  $entrepot
     * @param  array<string, mixed>  $anneePrecedente
     */
    private function assertLesDouzeMoisDeLEntrepot(array $entrepot, array $anneePrecedente): void
    {
        $this->assertSame(
            ['hausses' => 25060, 'baisses' => 5680, 'ecart' => 19380, 'visibles' => 9, 'uneFois' => 3, 'memePrix' => 2, 'egaux' => ['n' => 0, 'qte' => 0, 'montant' => 0]],
            $this->resumeDeLaPeriode($entrepot),
            'Entrepôt, 12 derniers mois.',
        );

        $this->assertSame([520, 550, 525, 535], array_column($this->achatsDuProduit($entrepot, 'Bougie parfumée ambre 180 g'), 'c'), 'Le premier achat de la période (5,20 €) est le départ : celui de novembre 2025 (7,20 €) ne compte jamais.');
        $this->assertSame([0, 200], array_column(array_slice($this->achatsDuProduit($entrepot, 'Sachet de lavande'), 0, 2), 'c'), 'Un produit offert, puis payé 2,00 €.');
        $this->assertSame(1200, $this->achatsDuProduit($entrepot, 'Boîte cadeau carrée')[0]['q'], 'Une quantité de quatre chiffres.');

        $this->assertSame(
            ['hausses' => 300, 'baisses' => 0, 'ecart' => 300, 'visibles' => 1, 'uneFois' => 0, 'memePrix' => 1, 'egaux' => ['n' => 1, 'qte' => 1, 'montant' => 4500]],
            $this->resumeDeLaPeriode($anneePrecedente),
            'Entrepôt, année précédente (2025) : un présentoir acheté trois fois dont un achat de 45 € au même prix caché, une bougie toujours au même prix.',
        );
        $this->assertSame(['Présentoir en chêne', 'Bougie parfumée ambre 180 g'], $anneePrecedente['produits']);
    }

    /**
     * @param  array<string, mixed>  $calcul
     * @return array<string, mixed>
     */
    private function resumeDeLaPeriode(array $calcul): array
    {
        $p = $calcul['calculDeLaPeriode'];

        return ['hausses' => $p['hausses'], 'baisses' => $p['baisses'], 'ecart' => $p['ecart'], 'visibles' => $p['visibles'], 'uneFois' => $p['uneFois'], 'memePrix' => $p['memePrix'], 'egaux' => $p['egaux']];
    }

    /**
     * L'Éphémère n'a aucun achat validé (un seul brouillon, qui ne compte
     * pas) ; le Marché n'a qu'un produit, acheté une seule fois.
     *
     * @param  array<string, mixed>  $ephemere
     * @param  array<string, mixed>  $marche
     */
    private function assertLEphemereEtLeMarche(array $ephemere, array $marche): void
    {
        $magasin = (int) $this->magasinDeMaisonFerrand('EPHEMERE')->getKey();
        $this->assertSame(0, DB::table('factures_achat')->where('magasin_id', $magasin)->where('statut', 'validee')->count());
        $this->assertSame(1, DB::table('factures_achat')->where('magasin_id', $magasin)->where('statut', 'brouillon')->count(), 'Un brouillon d\'achat, qui ne compte pas.');
        $this->assertSame([], $ephemere['produits']);
        $this->assertSame('Aucun achat à comparer sur les 12 mois.', TextesDeLEcartDesPrixDAchat::lecture($ephemere, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS, 11)['detail']);
        $this->assertSame('Aucun achat ce mois-ci', TextesDeLEcartDesPrixDAchat::lecture($ephemere, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, 11)['pastille']['texte']);

        $this->assertSame(['Panier en osier'], $marche['produits']);
        $this->assertSame(
            '1 produit n’a pas d’écart et ne est pas compté (1 acheté une seule fois).',
            TextesDeLEcartDesPrixDAchat::lecture($marche, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS, 11)['detail'],
        );
        $this->assertSame(0, $marche['calculDeLaPeriode']['ecart']);
    }

    /**
     * Les achats d'un produit, dans l'ordre du temps.
     *
     * @param  array<string, mixed>  $calcul
     * @return list<array{k: int, j: int, q: int, c: int}>
     */
    private function achatsDuProduit(array $calcul, string $nom): array
    {
        $rang = array_search($nom, $calcul['produits'], true);
        $this->assertIsInt($rang, "Le produit « {$nom} » n'est pas dans la carte.");
        $achats = [];

        foreach ($calcul['calculs'] as $mois) {
            foreach ($mois['premiers'] as $premier) {
                if ($premier['i'] === $rang) {
                    $achats[] = $premier['e'];
                }
            }

            foreach ($mois['pas'] as $pas) {
                if ($pas['i'] === $rang) {
                    $achats[] = $pas['e'];
                }
            }
        }

        usort($achats, static fn (array $un, array $autre): int => [$un['k'], $un['j']] <=> [$autre['k'], $autre['j']]);

        return $achats;
    }

    /**
     * Les règles de lecture des achats (B3 à B5) sur les achats semés : ce
     * qui ne compte pas (brouillon, avoir fournisseur, inventaire
     * d'ouverture), la date de la facture contre celle de la validation, un
     * achat reçu sans date rangé au jour de sa validation en Martinique, un
     * achat d'un mois à venir, un prix net arrondi une seule fois, un
     * produit renommé ou archivé, une facture corrigée, deux magasins qui
     * achètent un produit de même nom, et un nom de magasin jamais écrit
     * quand un seul magasin est compté.
     *
     * @param  array<string, array<string, mixed>>  $vues
     */
    private function assertLesRegleDeLectureDesAchats(array $vues): void
    {
        $moisDuSemis = Mois::duJour(Calendrier::deParis()->aujourdhui());
        $this->assertSame('2026-11', $moisDuSemis);
        $magasin = fn (string $code): int => (int) $this->magasinDeMaisonFerrand($code)->getKey();

        // Ce qui compte : une ligne de facture d'achat validée, datée dans les 12 mois ;
        // un brouillon, un avoir fournisseur et un inventaire d'ouverture ne comptent pas.
        foreach (['HALLES', 'REPUB', 'ENTREPOT', 'FDF'] as $code) {
            $lignes = DB::table('lignes_achat')
                ->join('factures_achat', fn ($jointure) => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
                ->where('factures_achat.magasin_id', $magasin($code))
                ->where('factures_achat.statut', 'validee');
            $dansLesDouzeMois = (clone $lignes)->whereBetween('factures_achat.date_facture', ['2025-12-01', '2026-11-30'])->count()
                + ($code === 'FDF' ? (clone $lignes)->whereNull('factures_achat.date_facture')->count() : 0);
            $p = $vues[$code]['calculDeLaPeriode'];
            $lues = $p['uneFois'] + array_sum(array_map(static fn (array $ligne): int => 1 + count($ligne['pas']), $p['lignes']));

            $this->assertSame($dansLesDouzeMois, $lues, "{$code} : la carte compte exactement les lignes de factures d'achat validées des 12 mois.");
        }

        $this->assertGreaterThan(0, DB::table('factures_achat')->where('magasin_id', $magasin('HALLES'))->where('statut', 'brouillon')->count(), 'Les Halles ont un brouillon d\'achat, que la carte ne compte pas.');
        $this->assertGreaterThan(0, DB::table('avoirs_fournisseur')->where('magasin_id', $magasin('HALLES'))->count(), 'Les Halles ont un avoir fournisseur, qui ne change aucune quantité d\'achat.');
        $this->assertGreaterThan(0, DB::table('inventaires_ouverture')->where('magasin_id', $magasin('REPUB'))->count(), 'La République a un inventaire d\'ouverture, que la carte ne compte pas.');

        // La date de la facture contre celle de la validation : une facture du 31 janvier validée le 1er février compte en janvier.
        $decalees = DB::select(<<<'SQL'
            select f.date_facture, l.quantite, l.prix_unitaire_ht
            from lignes_achat l
            join factures_achat f on f.magasin_id = l.magasin_id and f.id = l.facture_achat_id
            where f.magasin_id = ? and f.statut = 'validee' and f.date_facture between '2025-12-01' and '2026-11-30'
              and to_char(f.date_validation at time zone 'UTC' at time zone ?, 'YYYY-MM') <> to_char(f.date_facture, 'YYYY-MM')
            SQL, [$magasin('HALLES'), Calendrier::deParis()->fuseau()]);
        $this->assertGreaterThanOrEqual(2, count($decalees), 'Des réassorts des Halles sont datés d\'un mois et validés le suivant.');

        foreach ($decalees as $ligne) {
            $rang = array_search(substr((string) $ligne->date_facture, 0, 7), array_map(static fn (int $k): string => Mois::decaler($moisDuSemis, $k - 11), range(0, 11)), true);
            $this->assertIsInt($rang);
            $achats = $vues['HALLES']['calculs'][$rang];
            $trouves = array_filter([...array_column($achats['premiers'], 'e'), ...array_column($achats['pas'], 'e')], static fn (array $achat): bool => $achat['j'] === (int) substr((string) $ligne->date_facture, 8, 2) && $achat['q'] === (int) $ligne->quantite && $achat['c'] === (int) round((float) $ligne->prix_unitaire_ht * 100));
            $this->assertNotEmpty($trouves, "L'achat du {$ligne->date_facture} compte dans le mois de sa facture, non dans celui de sa validation.");
        }

        // Un achat reçu sans date, comme l'API le crée : rangé au jour de sa validation dans le calendrier de son magasin.
        $sansDate = DB::table('factures_achat')->where('magasin_id', $magasin('FDF'))->whereNull('date_facture')->sole();
        $validee = CarbonImmutable::parse((string) $sansDate->date_validation, 'UTC');
        $dernierJour = Calendrier::ajouterJours(Mois::decaler($moisDuSemis, -6).'-01', -1);
        $this->assertSame($dernierJour, $this->magasinDeMaisonFerrand('FDF')->calendrier()->jourDe($validee), 'Validé à 23 h 30 en Martinique le dernier jour d\'un mois.');
        $this->assertSame(Mois::decaler($moisDuSemis, -6).'-01', Calendrier::deParis()->jourDe($validee), 'Le premier du mois suivant à Paris.');
        $savon = $this->achatsDuProduit($vues['FDF'], 'Savon de coco artisanal');
        $this->assertSame([[4, 10, 60, 240], [4, (int) substr($dernierJour, 8, 2), 60, 260]], array_map(static fn (array $achat): array => [$achat['k'], $achat['j'], $achat['q'], $achat['c']], $savon), 'L\'achat sans date compte dans le mois de sa validation en Martinique (avril), non à Paris (mai).');

        // Un achat d'un mois à venir compte dans « Année en cours », pas dans « 12 derniers mois ».
        $boule = 'Boule de Noël en verre (boîte de 6)';
        $this->assertCount(1, $this->achatsDuProduit($vues['HALLES'], $boule));
        $this->assertSame([[9, 10, 30, 800], [11, 10, 30, 840]], array_map(static fn (array $achat): array => [$achat['k'], $achat['j'], $achat['q'], $achat['c']], $this->achatsDuProduit($vues['HALLES:annee-en-cours'], $boule)));

        // Le coût net arrondi une seule fois : remise de ligne, escompte de facture, six décimales, HT de ligne écrit.
        $set = $this->achatsDuProduit($vues['HALLES'], 'Set de table en coton (lot de 4)');
        $this->assertSame([1200, 1200, 1200, 1200, 1201, 1240], array_column($set, 'c'), 'Un prix écrit à 15,00 € moins 20 %, à 12,50 € moins 4 %, à 12,004950 € coûtent 12,00 € ; 12,0040 € avec un HT écrit de 120,05 € coûte 12,01 €.');
        $this->assertSame([10, 10, 10, 10, 10, 10], array_column($set, 'q'));
        $this->assertSame([5, 9, 13, 17, 21, 25], array_column($set, 'j'));

        // Un produit renommé entre deux achats porte son nom actuel ; un produit archivé reste nommé.
        $this->assertContains('Plateau en bambou clair', $vues['HALLES']['produits']);
        $this->assertNotContains('Plateau en bambou', $vues['HALLES']['produits']);
        $this->assertSame([650, 680], array_column($this->achatsDuProduit($vues['HALLES'], 'Plateau en bambou clair'), 'c'));
        $this->assertContains('Cadre photo 13 × 18', $vues['REPUB']['produits']);
        $this->assertSame(1, DB::table('produits')->where('magasin_id', $magasin('REPUB'))->where('nom', 'Cadre photo 13 × 18')->whereNotNull('deleted_at')->count(), 'Le cadre photo est archivé.');

        // Une facture validée corrigée après coup : la carte lit le prix corrigé.
        $this->assertSame([390, 410], array_column($this->achatsDuProduit($vues['ENTREPOT'], 'Diffuseur rotin 100 ml'), 'c'));
        $this->assertSame(1, DB::table('journal_audit')->where('magasin_id', $magasin('ENTREPOT'))->where('action', 'facture_achat_modifiee')->count(), 'La correction s\'est inscrite au journal d\'audit.');
        $this->assertSame(0, DB::table('lignes_achat')->where('magasin_id', $magasin('ENTREPOT'))->where('prix_unitaire_ht', '4.010000')->count(), 'Plus aucune ligne au prix saisi par erreur.');

        // Deux magasins qui achètent un produit de même nom le même mois à deux prix ne mélangent jamais leurs prix.
        $this->assertSame([1800, 1850], array_column($this->achatsDuProduit($vues['HALLES'], 'Théière en fonte 1 l'), 'c'));
        $this->assertSame([2100, 2000], array_column($this->achatsDuProduit($vues['REPUB'], 'Théière en fonte 1 l'), 'c'));
        $this->assertSame([1800, 1850], array_column($this->achatsDuProduit($vues['TOUS'], 'Théière en fonte 1 l · Maison Ferrand Halles'), 'c'));
        $this->assertSame([2100, 2000], array_column($this->achatsDuProduit($vues['TOUS'], 'Théière en fonte 1 l · Maison Ferrand République'), 'c'));
        $this->assertSame([], array_filter($vues['HALLES']['produits'], static fn (string $nom): bool => str_contains($nom, ' · ')), 'Avec un seul magasin compté, un nom de produit ne dit jamais un magasin.');
        $this->assertSame([], array_filter($vues['TOUS']['produits'], static fn (string $nom): bool => ! str_contains($nom, ' · ')), 'Avec plusieurs magasins comptés, chaque nom de produit dit le sien.');

        // La lampe de la République perd 210 € sur les 12 mois : l'écart de ce magasin est négatif.
        $this->assertSame([1000, 850, 800], array_column($this->achatsDuProduit($vues['REPUB'], 'Lampe de chevet en céramique'), 'c'));
        $this->assertLessThan(0, $vues['REPUB']['calculDeLaPeriode']['ecart']);
        $this->assertGreaterThan(0, $vues['ENTREPOT']['calculDeLaPeriode']['ecart']);
    }

    /**
     * Qui voit la carte et qui ne la voit pas : la propriétaire de Maison
     * Ferrand (espace de la société et panneau de chaque magasin), son
     * administratrice cochée aux graphiques, la responsable des Halles
     * cochée, le cabinet Martin (son propriétaire et Paul Lefèvre pour Maison
     * Ferrand), le propriétaire de l'Épicerie ;
     * pas les responsables sans la case, l'administrateur sans la case, les
     * vendeurs, l'observatrice, l'expert-comptable d'une société de services,
     * ni Emma Rousseau ni le cabinet pour l'Épicerie Lemoine, dont le lien
     * reste actif mais dont le propriétaire a retiré au cabinet les
     * consultations d'achats.
     */
    private function assertQuiVoitLaCarte(Societe $societe): void
    {
        $cabinet = Societe::query()->where('siren', FauxRepertoireSirene::SIREN_CABINET)->sole();
        $epicerie = Societe::query()->where('siren', SemisComplet::SIREN_CLIENT_INVITE)->sole();
        $services = Societe::query()->where('siren', SemisComplet::SIREN_SERVICES)->sole();

        $voient = [
            'la propriétaire de Maison Ferrand' => $societe->proprietaire()->sole(),
            'Nadia Benali' => User::query()->where('name', 'Nadia Benali')->sole(),
            'Camille Laurent' => User::query()->where('name', 'Camille Laurent')->sole(),
            'le propriétaire du cabinet Martin' => $cabinet->proprietaire()->sole(),
            'Paul Lefèvre' => User::query()->where('name', 'Paul Lefèvre')->sole(),
            'le propriétaire de l\'Épicerie Lemoine' => $epicerie->proprietaire()->sole(),
        ];
        $neVoientPas = [
            'Emma Rousseau (Épicerie, consultations d\'achats retirées au cabinet)' => User::query()->where('name', 'Emma Rousseau')->sole(),
            'Hugo Lefebvre' => User::query()->where('name', 'Hugo Lefebvre')->sole(),
            'Joëlle Marie-Sainte' => User::query()->where('name', 'Joëlle Marie-Sainte')->sole(),
            'Thomas Girard' => User::query()->where('name', 'Thomas Girard')->sole(),
            'Inès Garcia' => User::query()->where('name', 'Inès Garcia')->sole(),
            'Sarah Petit' => User::query()->where('name', 'Sarah Petit')->sole(),
            'Kévin Louisy' => User::query()->where('name', 'Kévin Louisy')->sole(),
            'Élodie Fabre' => User::query()->where('name', 'Élodie Fabre')->sole(),
            'Marc Petit (société de services)' => User::query()->where('name', 'Marc Petit')->sole(),
            'la propriétaire de la société de services' => $services->proprietaire()->sole(),
            'Claire Dubois (société de services)' => User::query()->where('name', 'Claire Dubois')->sole(),
        ];

        foreach ($voient as $qui => $compte) {
            $this->assertTrue($this->voitLaCarte($compte), "{$qui} voit la carte « Écart des prix d'achat ».");
        }

        foreach ($neVoientPas as $qui => $compte) {
            $this->assertFalse($this->voitLaCarte($compte), "{$qui} ne voit pas la carte « Écart des prix d'achat ».");
        }

        // Le cabinet la voit pour Maison Ferrand, pas pour l'Épicerie Lemoine : son lien reste actif,
        // mais sa propriétaire en a retiré les consultations d'achats.
        $proprietaireDuCabinet = $cabinet->proprietaire()->sole();
        app(TenantContext::class)->clear();
        $clientsParcourus = [];
        $clientsQuiLaMontrent = [];

        foreach (app(AccesDesCabinets::class)->liensOuverts($proprietaireDuCabinet)->get() as $lien) {
            $client = (string) Societe::query()->findOrFail($lien->societe_id)->raison_sociale;
            $clientsParcourus[] = $client;

            if (app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuClient($proprietaireDuCabinet, $lien) && app(PerimetreDesGraphiques::class)->duClient($proprietaireDuCabinet, $lien)->voit(Graphique::EcartDesPrixDAchat)) {
                $clientsQuiLaMontrent[] = $client;
            }
        }

        sort($clientsParcourus);
        sort($clientsQuiLaMontrent);
        $this->assertSame(['EPICERIE LEMOINE', 'MAISON FERRAND'], $clientsParcourus, 'Le cabinet a deux liens actifs à la fin du semis.');
        $this->assertSame(['MAISON FERRAND'], $clientsQuiLaMontrent);

        $lienDeLEpicerie = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->getKey())->where('societe_id', $epicerie->getKey())->where('etat', 'actif')->sole();
        $this->assertNotContains(PermissionRegistry::FACTURE_ACHAT_VIEW, $lienDeLEpicerie->autorisations(), 'L\'Épicerie a retiré « Factures d\'achat : voir » au cabinet.');
        $this->assertNotContains(PermissionRegistry::AVOIR_FOURNISSEUR_VIEW, $lienDeLEpicerie->autorisations(), 'L\'Épicerie a retiré « Avoirs fournisseur : voir » au cabinet.');
        $lienDeMaisonFerrand = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->getKey())->where('societe_id', $societe->getKey())->where('etat', 'actif')->sole();
        $this->assertContains(PermissionRegistry::FACTURE_ACHAT_VIEW, $lienDeMaisonFerrand->autorisations(), 'Maison Ferrand garde « Factures d\'achat : voir » au cabinet.');
    }

    /**
     * Voit-il la carte, sur l'écran où son compte l'ouvre ?
     */
    private function voitLaCarte(User $compte): bool
    {
        app(TenantContext::class)->clear();
        $perimetres = app(PerimetreDesGraphiques::class);
        $compte = $compte->refresh();

        try {
            if ($compte->estExpertComptable()) {
                return $perimetres->delExpertComptable($compte)->voit(Graphique::EcartDesPrixDAchat);
            }

            if ($compte->societe->estUnCabinet()) {
                foreach (app(AccesDesCabinets::class)->liensOuverts($compte)->get() as $lien) {
                    try {
                        if ($perimetres->duClient($compte, $lien)->voit(Graphique::EcartDesPrixDAchat)) {
                            return true;
                        }
                    } catch (AuthorizationException) {
                        continue;
                    }
                }

                return false;
            }

            if ($compte->peutAdministrer()) {
                return $perimetres->deLaSociete($compte, $compte->societe)->voit(Graphique::EcartDesPrixDAchat);
            }

            return $perimetres->duMagasin($compte, $compte->magasinsAccessibles()->firstOrFail())->voit(Graphique::EcartDesPrixDAchat);
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * Les préférences semées sur le poste : la propriétaire de Maison
     * Ferrand a la carte à l'affichage des 12 mois (espace de la société),
     * Camille Laurent à l'affichage par mois (panneau des Halles) et le
     * propriétaire du cabinet à l'affichage par mois (espace du cabinet),
     * chacun avec des achats à lire ; les autres comptes gardent leurs
     * cartes par défaut.
     */
    private function assertLesPreferencesDeLaCarte(Societe $societe): void
    {
        $perimetres = app(PerimetreDesGraphiques::class);
        $preferences = app(PreferencesDesGraphiques::class);
        $proprietaire = $societe->proprietaire()->sole();
        $camille = User::query()->where('name', 'Camille Laurent')->sole();
        $cabinet = Societe::query()->where('siren', FauxRepertoireSirene::SIREN_CABINET)->sole();
        $proprietaireDuCabinet = $cabinet->proprietaire()->sole();
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->getKey())->where('societe_id', $societe->getKey())->where('etat', 'actif')->sole();

        app(TenantContext::class)->clear();
        $parEcran = [
            [EcranDuTableauDeBord::Societe, $proprietaire, $perimetres->deLaSociete($proprietaire, $societe), TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS],
            [EcranDuTableauDeBord::Magasin, $camille, $perimetres->duMagasin($camille, $this->magasinDeMaisonFerrand('HALLES')), TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS],
            [EcranDuTableauDeBord::Cabinet, $proprietaireDuCabinet, $perimetres->duClient($proprietaireDuCabinet, $lien), TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS],
        ];

        foreach ($parEcran as [$ecran, $compte, $perimetre, $affichage]) {
            $this->assertSame($ecran, $perimetre->ecran);
            $reglages = $preferences->lire($compte, $perimetre);
            $carte = $reglages->carte(Graphique::EcartDesPrixDAchat);

            $this->assertNotNull($carte, "{$compte->name} a la carte sur l'écran « {$ecran->value} ».");
            $this->assertSame($affichage, $carte->affichage);
            $this->assertContains(Graphique::EcartDesPrixDAchat, array_map(static fn (CarteDuTableauDeBord $visible): Graphique => $visible->graphique, $reglages->cartesVisibles($perimetre)), 'Elle s\'affiche sans aucun geste.');
            $this->assertNotSame([], $this->calculDeLaCarte($perimetre, $reglages->periode)['produits'], "{$compte->name} y lit de vrais achats.");
        }

        $nadia = User::query()->where('name', 'Nadia Benali')->sole();
        $this->assertNull($preferences->lire($nadia, $perimetres->deLaSociete($nadia, $societe))->carte(Graphique::EcartDesPrixDAchat), 'Les autres comptes gardent leurs cartes par défaut et l\'ajoutent par « Ajouter un graphique ».');
    }

    /**
     * La date de l'opération (T49) : des factures émises sans date, avec
     * un jour et avec une période ; une qui imprime sa date (un autre jour
     * que son émission, ou une période) et une qui ne l'imprime pas (le
     * jour même), et la rectificative qui imprime celle de son origine ;
     * un devis qui prévoit la sienne.
     */
    private function assertLesDatesDeLOperation(): void
    {
        $this->assertSame(
            [['aucune'], ['jour'], ['periode']],
            $this->lignes("select distinct case when date_operation_fin is null then 'aucune' when date_operation_debut is null then 'jour' else 'periode' end from factures where numero is not null order by 1"),
            'Des factures émises n\'ont pas de date de l\'opération, d\'autres un jour, une autre une période.',
        );
        $this->assertSame(
            [[false], [true]],
            $this->lignes("select distinct contenu like '%Date de la livraison ou de la prestation : %' from enregistrements_fiscaux where type = 'facture' and contenu like '%\"date_operation\":{\"debut\":%' and contenu not like '%\"fin\":null}%' order by 1"),
            'Une facture datée imprime sa date (un autre jour que son émission), une autre ne l\'imprime pas (le jour même).',
        );
        $this->assertSame(
            [[true]],
            $this->lignes("select count(*) > 0 from enregistrements_fiscaux where contenu like '%Date de la livraison ou de la prestation d''origine : %'"),
            'La rectificative imprime la date de l\'opération d\'origine.',
        );
        $this->assertSame(
            [[true]],
            $this->lignes('select count(*) > 0 from devis where date_operation_fin is not null'),
            'Un devis prévoit la date de sa livraison.',
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
     * Etat, Resultat ou Motif, ou qui dit le mode de fermeture d'une caisse ;
     * pas ceux du registre du site de démonstration, dont les tables vivent
     * dans une autre base que celle du poste (leurs états se sèment par le
     * registre semé du site de démonstration, F.8, lot DEMO-COPIE-3).
     *
     * @return array<string, array<string, class-string<BackedEnum>>>
     */
    private function colonnesDEtat(): array
    {
        $colonnes = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
            $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($fichier->getPathname(), app_path().DIRECTORY_SEPARATOR));

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract() || str_starts_with($classe, ControlesDeLApplication::MODELES_DU_REGISTRE)) {
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
     * Un écouteur qui, à la première vente d'un magasin, fait passer
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
