<?php

namespace Tests\Feature;

use App\Console\SemisEnCours;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Semis\ComptesSemes;
use Database\Seeders\Semis\MotsDePasseDuSemis;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Les données de démonstration d'un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026) : php artisan db:seed remplit une
 * base vide par les vraies actions et les fournisseurs factices, donne la
 * liste des comptes pour se connecter, et refuse, sans rien écrire, la
 * production, les fournisseurs réels, une base déjà remplie et un mot de
 * passe commun hors de la règle des mots de passe.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_semis_remplit_la_base_par_les_vraies_actions_et_donne_la_liste_des_comptes(): void
    {
        config(['piliqi.demo_mot_de_passe' => null]);
        $debut = self::aParis('2026-11-13 10:00:00');
        $this->travelTo($debut);

        $this->semer();

        $this->assertTrue(now()->equalTo($debut), 'Le semis rend l\'horloge telle qu\'elle était avant lui.');
        $this->assertSame([
            ['000000109', 'MAISON FERRAND', 'commerce', true],
            ['000000117', 'CONSEIL VASSEUR', 'services', true],
            ['000000125', 'EPICERIE LEMOINE', 'commerce', true],
            ['000000133', 'FLEURS DE SAISON', 'commerce', false],
            ['000000943', 'MARTIN EXPERTISE COMPTABLE', 'cabinet', true],
        ], DB::table('societes')->orderBy('siren')->get()->map(fn (object $societe): array => [$societe->siren, $societe->raison_sociale, $societe->type_activite, $societe->validee_le !== null])->all());
        $this->assertSame([
            ['000000109', 'DEMO', 'Démonstration', true, 'ferme'],
            ['000000109', 'HALLES', 'Maison Ferrand Halles', false, 'actif'],
            ['000000109', 'REPUB', 'Maison Ferrand République', false, 'actif'],
            ['000000117', 'PARIS', 'Conseil Vasseur Paris', false, 'actif'],
            ['000000125', 'EPICERIE', 'Épicerie Lemoine', false, 'actif'],
            ['000000133', 'DEMO', 'Démonstration', true, 'actif'],
        ], $this->lignes('select s.siren, m.code, m.nom, m.est_demonstration, m.statut from magasins m join societes s on s.id = m.societe_id order by s.siren, m.code'));

        $this->assertLesComptesEtLeursMagasins();
        $this->assertLeCabinetEtLExpertComptableDeclare();
        $this->assertLesDocumentsDesMagasins();
        $this->assertLaListeDesComptesOuvreChaqueCompte();

        $this->assertSame(0, Artisan::call('caisse:verifier', ['--complet' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('app:verifier-journal-audit'), Artisan::output());
    }

    /**
     * Le planificateur (php artisan schedule:work, que l'installation garde
     * ouvert) passe à l'heure réelle au milieu du semis, juste après la
     * première vente d'un magasin réel : il ne lance aucune tâche, et le
     * semis va jusqu'au bout ; ensuite, ses tâches repartent.
     */
    public function test_le_planificateur_ne_lance_aucune_tache_pendant_le_semis(): void
    {
        $heureReelle = self::aParis('2026-11-13 10:00:00');
        $this->travelTo($heureReelle);
        $passages = [];
        Event::listen(FactureEmise::class, function (FactureEmise $emise) use ($heureReelle, &$passages): void {
            if ($passages !== [] || ! str_contains((string) $emise->facture->numero, '-HALLES-')) {
                return;
            }

            $passages[] = 'attendu';
            DB::afterCommit(function () use ($heureReelle, &$passages): void {
                $passages = [$this->passageDuPlanificateurA($heureReelle)];
            });
        });

        $this->semer();

        $this->assertSame([[]], $passages, 'Le planificateur ne lance aucune tâche pendant le semis.');
        $this->assertFalse(SemisEnCours::estEnCours());
        $this->assertContains('app:cloturer-caisses-automatiquement', $this->passageDuPlanificateurA($heureReelle), 'Les tâches repartent après le semis.');
        $this->assertSame(0, Artisan::call('caisse:verifier', ['--complet' => true]), Artisan::output());
    }

    public function test_le_semis_est_refuse_en_production_sans_rien_ecrire(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(DatabaseSeeder::MESSAGE_HORS_DU_DEVELOPPEMENT, $this->refusDuSemis(['--force' => true]));
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    /**
     * Chaque fournisseur externe avec son pilote réel, ou sans pilote
     * quand il n'en a pas encore d'autre que le factice (le courrier
     * recommandé et la plateforme agréée).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function fournisseursReels(): array
    {
        return [
            'répertoire Sirene de l\'INSEE' => ['services.sirene.pilote', 'insee'],
            'registre national des entreprises de l\'INPI' => ['services.rne.pilote', 'inpi'],
            'courrier recommandé sans pilote' => ['services.recommande.pilote', null],
            'annuaire de l\'Ordre tenu par l\'éditeur' => ['services.ordre.pilote', 'editeur'],
            'plateforme agréée sans pilote' => ['services.pdp.pilote', null],
            'antivirus ClamAV' => ['services.antivirus.pilote', 'clamav'],
        ];
    }

    #[DataProvider('fournisseursReels')]
    public function test_le_semis_est_refuse_avec_un_fournisseur_reel_sans_rien_ecrire(string $reglage, ?string $pilote): void
    {
        config([$reglage => $pilote]);

        $this->assertSame(DatabaseSeeder::MESSAGE_FOURNISSEURS_REELS, $this->refusDuSemis());
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    public function test_le_semis_est_refuse_dans_une_base_qui_a_deja_des_societes(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->assertSame(DatabaseSeeder::MESSAGE_BASE_DEJA_REMPLIE, $this->refusDuSemis());
        $this->assertSame([(int) $societe->getKey()], DB::table('societes')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function motsDePasseHorsDeLaRegle(): array
    {
        return [
            'trop court' => ['Fraise7', RegleDuMotDePasse::MESSAGE_TROP_COURT],
            'un seul caractère répété' => ['aaaaaaaa', RegleDuMotDePasse::MESSAGE_REPETE],
            'une suite de chiffres' => ['12345678', RegleDuMotDePasse::MESSAGE_SUITE],
        ];
    }

    #[DataProvider('motsDePasseHorsDeLaRegle')]
    public function test_un_mot_de_passe_commun_hors_de_la_regle_est_refuse_sans_rien_ecrire(string $motDePasse, string $reproche): void
    {
        config(['piliqi.demo_mot_de_passe' => $motDePasse]);

        $this->assertSame(sprintf(MotsDePasseDuSemis::MESSAGE_REFUSE, $reproche), $this->refusDuSemis());
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    public function test_le_mot_de_passe_commun_va_a_chaque_compte_sauf_s_il_contient_son_identifiant(): void
    {
        config(['piliqi.demo_mot_de_passe' => 'Clef-48151623']);
        $motsDePasse = MotsDePasseDuSemis::depuisLaConfiguration();

        $this->assertTrue($motsDePasse->sontCommuns());
        $this->assertSame('Clef-48151623', $motsDePasse->nouveau());
        $this->assertSame('Clef-48151623', $motsDePasse->pour('27182818'));

        try {
            $motsDePasse->pour('48151623');
            $this->fail('Un mot de passe commun qui contient l\'identifiant d\'un compte doit être refusé.');
        } catch (RuntimeException $refus) {
            $this->assertSame(sprintf(MotsDePasseDuSemis::MESSAGE_IDENTIFIANT, '48151623'), $refus->getMessage());
        }
    }

    public function test_sans_mot_de_passe_commun_chaque_compte_recoit_un_mot_de_passe_tire_au_hasard(): void
    {
        config(['piliqi.demo_mot_de_passe' => '']);
        Str::createRandomStringsUsingSequence(['K7QP', '2MZR', 'XA4D', 'B3NW', 'Q9TE', 'LM2C']);
        $motsDePasse = MotsDePasseDuSemis::depuisLaConfiguration();

        $this->assertFalse($motsDePasse->sontCommuns());
        $this->assertSame('k7qp-2mzr-xa4d', $motsDePasse->pour('48151623'));
        $this->assertSame('b3nw-q9te-lm2c', $motsDePasse->nouveau());
    }

    /**
     * Chaque compte semé par société, rôle et statut, et les magasins que
     * chacun ouvre : un membre n'ouvre que le magasin de son compte, une
     * même personne a un compte dans chacun de ses deux magasins, un
     * administrateur ouvre ceux de sa portée (la démonstration d'une société
     * validée n'est plus ouverte par personne, T115), un compte du
     * cabinet ceux des clients qui lui sont confiés, et aucun compte n'ouvre
     * un magasin d'une autre société, hors le cabinet chez ses clients liés.
     */
    private function assertLesComptesEtLeursMagasins(): void
    {
        $this->assertSame([
            ['000000109', 'administrateur', 'actif', 2],
            ['000000109', 'membre', 'actif', 6],
            ['000000109', 'membre', 'retire', 1],
            ['000000109', 'proprietaire', 'actif', 1],
            ['000000117', 'administrateur', 'actif', 2],
            ['000000117', 'expert_comptable', 'actif', 1],
            ['000000117', 'membre', 'actif', 3],
            ['000000117', 'proprietaire', 'actif', 1],
            ['000000125', 'administrateur', 'actif', 2],
            ['000000125', 'membre', 'actif', 3],
            ['000000125', 'proprietaire', 'actif', 1],
            ['000000133', 'membre', 'actif', 2],
            ['000000133', 'proprietaire', 'actif', 1],
            ['000000943', 'collaborateur', 'actif', 2],
            ['000000943', 'proprietaire', 'actif', 1],
        ], $this->lignes('select s.siren, u.role_societe, u.statut, count(*) from users u join societes s on s.id = u.societe_id group by 1, 2, 3 order by 1, 2, 3'));

        $magasins = User::query()->where('statut', StatutCompte::Actif)->with('societe')->orderBy('id')->get()
            ->map(fn (User $compte): string => $compte->name.' ('.$compte->societe?->siren.') : '.$compte->magasinsAccessibles()->map(fn (Magasin $magasin): string => $magasin->societe?->siren.'/'.$magasin->code)->sort()->implode(', '))
            ->sort()->values()->all();

        $this->assertSame([
            'Antoine Roux (000000117) : 000000117/PARIS',
            'Aurélie BLANC (000000133) : 000000133/DEMO',
            'Bastien Leroy (000000125) : 000000125/EPICERIE',
            'Bernard LEMOINE (000000125) : 000000125/EPICERIE',
            'Camille Laurent (000000109) : 000000109/HALLES',
            'Chloé Bernard (000000125) : 000000125/EPICERIE',
            'Claire Dubois (000000117) : 000000117/PARIS',
            'Emma Rousseau (000000943) : 000000125/EPICERIE',
            'Hugo Lefebvre (000000109) : 000000109/REPUB',
            'Hélène FERRAND (000000109) : 000000109/HALLES, 000000109/REPUB',
            'Inès Garcia (000000109) : 000000109/HALLES',
            'Isabelle MARTIN (000000943) : 000000109/HALLES, 000000109/REPUB, 000000125/EPICERIE',
            'Julie Mercier (000000117) : 000000117/PARIS',
            'Julien VASSEUR (000000117) : 000000117/PARIS',
            'Karim Haddad (000000133) : 000000133/DEMO',
            'Lucas Moreau (000000109) : 000000109/HALLES',
            'Lucas Moreau (000000109) : 000000109/REPUB',
            'Léa Fontaine (000000117) : 000000117/PARIS',
            'Marc Petit (000000117) : 000000117/PARIS',
            'Maxime Henry (000000117) : 000000117/PARIS',
            'Mehdi Brahimi (000000125) : 000000125/EPICERIE',
            'Nadia Benali (000000109) : 000000109/HALLES, 000000109/REPUB',
            'Nicolas Lemoine (000000125) : 000000125/EPICERIE',
            'Paul Lefèvre (000000943) : 000000109/HALLES, 000000109/REPUB',
            'Romane Gauthier (000000125) : 000000125/EPICERIE',
            'Sarah Petit (000000109) : 000000109/REPUB',
            'Thomas Girard (000000109) : 000000109/HALLES, 000000109/REPUB',
            'Zoé Marchand (000000133) : 000000133/DEMO',
        ], $magasins);
    }

    /**
     * Le cabinet vérifié est lié à deux sociétés, chacune confiée à l'un de
     * ses collaborateurs ; la société de services a déclaré son
     * expert-comptable par le SIREN d'un cabinet absent de PILIQI, et son
     * compte a choisi son mot de passe par le lien reçu.
     */
    private function assertLeCabinetEtLExpertComptableDeclare(): void
    {
        $this->assertSame([
            ['000000109', '000000943', 'actif', 'Paul Lefèvre'],
            ['000000117', null, 'declare', null],
            ['000000125', '000000943', 'actif', 'Emma Rousseau'],
        ], $this->lignes('select s.siren, c.siren as cabinet, l.etat, u.name from liens_cabinet l join societes s on s.id = l.societe_id left join societes c on c.id = l.cabinet_id left join clients_confies cc on cc.lien_id = l.id left join users u on u.id = cc.user_id order by s.siren'));
        $this->assertSame([['000000158', 'PETIT ET ASSOCIES']], $this->lignes("select siren_cabinet, denomination_cabinet from liens_cabinet where etat = 'declare'"));
        $this->assertSame([[false]], $this->lignes("select mot_de_passe_est_l_identifiant from users where role_societe = 'expert_comptable'"));
    }

    /**
     * Ce que l'éditeur veut voir dans les magasins : achats avec et sans la
     * pièce du fournisseur, inventaires d'ouverture, factures payées, en
     * partie et en retard, devis à chaque étape, bon d'avoir du comptoir,
     * crédit d'un client, avoir fournisseur, contre-passation, caisses
     * fermées par leur vendeur et journée clôturée par une responsable.
     */
    private function assertLesDocumentsDesMagasins(): void
    {
        $halles = $this->magasin('HALLES');
        $republique = $this->magasin('REPUB');

        $this->assertSame([
            ['GL-2026-118', 'validee', false],
            ['PC-5521', 'validee', true],
        ], $this->lignes('select numero_fournisseur, statut, piece_manquante from factures_achat where magasin_id = ? order by numero_fournisseur', [$halles]));
        $this->assertSame([['EPICERIE', 'valide'], ['REPUB', 'valide']], $this->lignes('select m.code, i.statut from inventaires_ouverture i join magasins m on m.id = i.magasin_id order by m.code'));
        $this->assertSame([
            ['Hôtel des Arts', 'en_retard', '2026-10-12', false],
            ['Agence Horizon', 'payee', '2026-10-14', false],
            ['Hôtel des Arts', 'partiellement_payee', '2026-10-15', false],
            ['Agence Horizon', 'partiellement_payee', '2026-10-19', true],
        ], array_map(
            fn (array $facture): array => [$facture[0], $facture[1], Calendrier::deParis()->jourDe(CarbonImmutable::parse((string) $facture[2])), $facture[3]],
            $this->lignes("select client_nom, statut, date_emission, devis_id is not null from factures where magasin_id = ? and type_client = 'professionnel' order by date_emission, id", [$halles]),
        ));
        $this->assertSame([
            ['Agence Horizon', 'brouillon'],
            ['Hôtel des Arts', 'envoye'],
            ['Agence Horizon', 'converti'],
        ], $this->lignes('select c.nom, d.statut from devis d join clients c on c.id = d.client_id where d.magasin_id = ? order by d.id', [$halles]));
        $this->assertSame([
            ['000000109/HALLES', 'Client comptoir', 2990],
            ['000000109/HALLES', 'Agence Horizon', 0],
            ['000000109/REPUB', 'Studio Nord', 2500],
        ], $this->lignes("select s.siren || '/' || m.code, a.client_nom, (round(a.total_ttc * 100) - coalesce((select round(sum(aa.montant_alloue) * 100) from avoir_allocations aa where aa.avoir_id = a.id), 0))::int from avoirs a join magasins m on m.id = a.magasin_id join societes s on s.id = m.societe_id order by a.id"));
        $this->assertSame([['AV-GL-2026-031']], $this->lignes('select numero_fournisseur from avoirs_fournisseur where magasin_id = ?', [$halles]));
        $this->assertSame([['cheque', '-30.00']], $this->lignes('select mode_paiement, montant from paiements where contre_passe_paiement_id is not null'));
        $this->assertSame([['Lucas Moreau'], ['Thomas Girard']], $this->lignes("select vendeur_nom from clotures_caisse_personnelles where mode_fermeture = 'vendeur' order by cloturee_a"));
        $this->assertSame([[$halles, '2026-10-24', 'Camille Laurent']], $this->lignes('select magasin_id, date_cloture, cree_par_nom from clotures_caisse where cree_par is not null'));
        $this->assertSame(
            [],
            $this->lignes("select f.numero from factures f join magasins m on m.id = f.magasin_id where f.numero is not null and f.numero not like '%-' || m.code || '-%'"),
            'Chaque document porte le code de son magasin.',
        );
        $this->assertSame([[$republique, 'Studio Nord']], $this->lignes("select a.magasin_id, c.nom from avoirs a join clients c on c.id = a.client_id where c.nom = 'Studio Nord'"));
    }

    /**
     * Le fichier des comptes donne, pour chaque compte actif, son
     * identifiant et son mot de passe, qui ouvre ce compte ; le vendeur qui
     * garde son identifiant pour mot de passe y est marqué, et le poste de
     * son magasin, d'où il vend, a été reconnu avant sa première vente.
     */
    private function assertLaListeDesComptesOuvreChaqueCompte(): void
    {
        Storage::disk('local')->assertExists(DatabaseSeeder::FICHIER_DES_COMPTES);
        $texte = (string) Storage::disk('local')->get(DatabaseSeeder::FICHIER_DES_COMPTES);
        $comptes = [];

        foreach (explode(PHP_EOL, $texte) as $ligne) {
            if (str_starts_with($ligne, '| ')) {
                $cellules = array_map(trim(...), explode(' | ', Str::between($ligne, '| ', ' |')));
                $comptes[$cellules[4]] = $cellules;
            }
        }

        unset($comptes['Identifiant']);
        $identifiants = DB::table('identifiants')->join('users', 'users.id', '=', 'identifiants.user_id')->where('users.statut', StatutCompte::Actif->value)->pluck('identifiants.identifiant', 'users.id')->map(fn (mixed $identifiant): string => (string) $identifiant);

        $this->assertEqualsCanonicalizing($identifiants->values()->all(), array_keys($comptes));

        foreach ($identifiants as $id => $identifiant) {
            $compte = User::query()->findOrFail($id);
            $motDePasse = $comptes[$identifiant][6] === ComptesSemes::SON_IDENTIFIANT ? $identifiant : $comptes[$identifiant][6];

            $this->assertTrue(Hash::check($motDePasse, (string) $compte->password), "Le mot de passe listé n'ouvre pas le compte de {$compte->name}.");
            $this->assertSame($compte->estProprietaire() ? (string) $compte->email : '', $comptes[$identifiant][5]);
        }

        $this->assertSame(['Inès Garcia'], array_values(array_map(fn (array $compte): string => $compte[3], array_filter($comptes, fn (array $compte): bool => $compte[6] === ComptesSemes::SON_IDENTIFIANT))));
        $this->assertSame(
            [['HALLES', 'Caisse des Halles', 'Camille Laurent', true]],
            $this->lignes("select m.code, p.nom, p.reconnu_par_nom, p.created_at < (select min(f.created_at) from factures f join users u on u.id = f.cree_par where u.name = 'Inès Garcia') from postes_reconnus p join magasins m on m.id = p.magasin_id where p.retire_le is null"),
            'Inès Garcia, qui garde son identifiant pour mot de passe, ne vend que depuis le poste que sa responsable a reconnu avant sa première vente.',
        );
        $this->assertStringContainsString('proprietaire@cabinet.exemple.test', $texte);
    }

    /**
     * Sème les données de démonstration, sur des disques simulés : la liste
     * des comptes, les courriers factices et la pièce du fournisseur de la
     * facture d'achat semée.
     */
    private function semer(): void
    {
        Storage::fake('local');
        Storage::fake(PiecesDuFournisseur::DISQUE);

        $this->artisan('db:seed')
            ->expectsOutputToContain('Données de démonstration semées.')
            ->expectsOutputToContain('proprietaire@commerce.exemple.test')
            ->assertSuccessful();
    }

    /**
     * Un passage du planificateur à cet instant, comme celui de php artisan
     * schedule:run qu'un autre processus lance à l'heure réelle : chaque
     * tâche due dont les conditions passent s'exécute. Rend les commandes
     * exécutées, et l'horloge à l'heure où elle était.
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

    private function magasin(string $code): int
    {
        return (int) DB::table('magasins')->where('code', $code)->where('est_demonstration', false)->value('id');
    }

    /**
     * Les lignes de cette requête, chacune en liste de ses valeurs.
     *
     * @param  list<int|string>  $liaisons
     * @return list<list<mixed>>
     */
    private function lignes(string $requete, array $liaisons = []): array
    {
        return array_map(fn (object $ligne): array => array_values((array) $ligne), DB::select($requete, $liaisons));
    }

    /**
     * Le message du refus du semis, lancé avec ces options.
     *
     * @param  array<string, mixed>  $options
     */
    private function refusDuSemis(array $options = []): ?string
    {
        try {
            $this->artisan('db:seed', $options)->run();
        } catch (RuntimeException $refus) {
            return $refus->getMessage();
        }

        return null;
    }
}
