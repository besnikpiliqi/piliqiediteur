<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Construction\ConstruireUnModele;
use App\Domain\Demonstration\Construction\NettoyageDeLaDemonstration;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Exceptions\ConstructionEnEchec;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DemonstrationTestCase;

/**
 * La construction des bases modèles du site de démonstration (lot
 * DEMO-COPIE, C.2, N16), avec le modèle de test (DemonstrationTestCase) :
 * la bascule n'a lieu qu'avec l'isolation à zéro, le journal d'audit
 * intègre et caisse:verifier au code 0 ; un problème garde l'ancien modèle en service et supprime la base de
 * travail ; l'ancien modèle reste « précédent » jusqu'à la construction
 * réussie suivante, qui le supprime. La construction et le nettoyage ne
 * sont planifiés que sur le site de démonstration, la nuit, sur un seul
 * serveur. Le nettoyage ne supprime que le motif exact des bases de la
 * démonstration, archive la caisse d'une base de copie orpheline avant de
 * la supprimer, refait une copie perdue (sans base), supprime les bases de
 * travail abandonnées, et refuse d'agir quand le registre est vide alors
 * que des copies existent ou que plus de 10 % des copies vivantes
 * seraient supprimées.
 */
class ConstructionDesModelesTest extends DemonstrationTestCase
{
    public function test_une_construction_reussie_met_un_modele_ferme_en_service_avec_son_empreinte_et_son_mot_de_passe(): void
    {
        $modele = $this->modeleDeTest(TypeDActivite::Services);

        $this->assertSame(EtatDUnModele::EnService, $modele->etat);
        $this->assertMatchesRegularExpression(BasesDeLaDemonstration::MOTIF_D_UNE_BASE_DE_TRAVAIL, $modele->nom_de_la_base);
        $this->assertSame(EmpreinteDuSchema::duCode(), $modele->empreinte_du_schema);
        $this->assertMatchesRegularExpression('/^Demo-[a-z0-9]{6}-[0-9]{2}$/', $modele->mot_de_passe);
        $base = DB::connection('demo_admin')->table('pg_database')->where('datname', $modele->nom_de_la_base)->first(['datistemplate', 'datallowconn']);
        $this->assertNotNull($base);
        $this->assertTrue((bool) $base->datistemplate, 'Le modèle se ferme en modèle (IS_TEMPLATE).');
        $this->assertFalse((bool) $base->datallowconn, 'Plus aucune connexion n\'ouvre le modèle.');
    }

    public function test_une_etape_qui_echoue_garde_l_ancien_modele_en_service_et_supprime_la_base_de_travail(): void
    {
        $ancien = $this->modeleDeTest(TypeDActivite::Services);
        $this->app->instance(SemisDUnModele::class, new class implements SemisDUnModele
        {
            public function semer(TypeDActivite $type, string $motDePasse): void
            {
                throw new RuntimeException('Le semis s\'est arrêté net.');
            }
        });
        $this->travelTo(self::aParis('2026-11-17 01:10:00')->addMinutes(self::jeton()));

        try {
            app(ConstruireUnModele::class)->construire(TypeDActivite::Services);
            $this->fail('La construction devait échouer.');
        } catch (ConstructionEnEchec $echec) {
            $this->assertStringContainsString('Le semis s\'est arrêté net.', $echec->getMessage());
        }

        $rate = Modele::on('demo_admin')->where('etat', EtatDUnModele::EnEchec)->sole();
        $this->assertStringContainsString('Le semis s\'est arrêté net.', (string) $rate->cause_de_l_echec);
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($rate->nom_de_la_base), 'La base de travail d\'une construction ratée est supprimée.');
        $this->assertSame($ancien->nom_de_la_base, Modele::on('demo_admin')->where('type', 'services')->where('etat', EtatDUnModele::EnService)->sole()->nom_de_la_base);
    }

    /**
     * Sans qpdf (constat de l'éditeur du 17/11/2026, sur son poste Windows
     * dont .env.demonstration n'avait pas QPDF_BINAIRE), la construction
     * s'arrête avant toute base, en nommant QPDF_BINAIRE, au lieu de laisser
     * le semis s'arrêter sur « réessayez dans quelques minutes » ; l'échec
     * s'écrit au registre et l'ancien modèle reste en service.
     */
    public function test_une_construction_sans_qpdf_s_arrete_avant_toute_base_en_nommant_qpdf_binaire(): void
    {
        $ancien = $this->modeleDeTest(TypeDActivite::Services);
        $semis = new class implements SemisDUnModele
        {
            public bool $aSeme = false;

            public function semer(TypeDActivite $type, string $motDePasse): void
            {
                $this->aSeme = true;
            }
        };
        $this->app->instance(SemisDUnModele::class, $semis);
        config(['piliqi.qpdf' => 'qpdf-introuvable-sur-ce-poste']);
        $this->travelTo(self::aParis('2026-11-17 01:10:00')->addMinutes(self::jeton()));

        try {
            app(ConstruireUnModele::class)->construire(TypeDActivite::Services);
            $this->fail('La construction devait échouer.');
        } catch (ConstructionEnEchec $echec) {
            $this->assertStringContainsString('QPDF_BINAIRE', $echec->getMessage());
            $this->assertStringContainsString('qpdf-introuvable-sur-ce-poste', $echec->getMessage());
            $this->assertStringNotContainsString('réessayez', $echec->getMessage());
        }

        $this->assertFalse($semis->aSeme, 'Le semis ne commence pas sans qpdf.');
        $rate = Modele::on('demo_admin')->where('etat', EtatDUnModele::EnEchec)->sole();
        $this->assertStringContainsString('QPDF_BINAIRE', (string) $rate->cause_de_l_echec);
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($rate->nom_de_la_base), 'Aucune base de travail n\'est créée sans qpdf.');
        $this->assertSame($ancien->nom_de_la_base, Modele::on('demo_admin')->where('type', 'services')->where('etat', EtatDUnModele::EnService)->sole()->nom_de_la_base);
    }

    /**
     * Une table sans règle d'isolation suffit à rendre la vérification non
     * nulle (REGLE_TABLES) : le modèle n'est jamais mis en service.
     */
    public function test_un_modele_dont_l_isolation_n_est_pas_a_zero_n_est_jamais_mis_en_service(): void
    {
        $this->app->instance(SemisDUnModele::class, new class implements SemisDUnModele
        {
            public function semer(TypeDActivite $type, string $motDePasse): void
            {
                DB::statement('create table registre_parallele (id bigint primary key, societe_id bigint)');
            }
        });
        $this->travelTo(self::aParis('2026-11-18 01:10:00')->addMinutes(self::jeton()));

        $this->expectException(ConstructionEnEchec::class);

        try {
            app(ConstruireUnModele::class)->construire(TypeDActivite::Services);
        } finally {
            $this->assertFalse(Modele::on('demo_admin')->where('etat', EtatDUnModele::EnService)->exists());
        }
    }

    /**
     * Une chaîne du journal d'audit cassée par le semis (sa dernière
     * entrée réécrite en contournant la base, ses gardes remises ensuite :
     * seule l'empreinte la trahit) : app:verifier-journal-audit,
     * que la construction passe avant la bascule (C.13), échoue, et
     * l'ancien modèle reste en service.
     */
    public function test_un_modele_dont_une_chaine_du_journal_d_audit_est_cassee_n_est_jamais_mis_en_service(): void
    {
        $ancien = $this->modeleDeTest(TypeDActivite::Services);
        $semer = function (TypeDActivite $type, string $motDePasse): void {
            $this->semerLeModeleDeTest($type, $motDePasse);
            $derniere = DB::table('journal_audit')->orderByDesc('id')->first(['id']);
            $this->assertNotNull($derniere, 'Le semis du modèle de test écrit au journal d\'audit.');
            DB::statement('alter table journal_audit disable trigger user');
            DB::table('journal_audit')->where('id', $derniere->id)->update(['auteur_libelle' => 'Auteur réécrit']);
            DB::statement('alter table journal_audit enable trigger user');
        };
        $this->app->instance(SemisDUnModele::class, new class($semer) implements SemisDUnModele
        {
            /**
             * @param  \Closure(TypeDActivite, string): void  $semer
             */
            public function __construct(private readonly \Closure $semer) {}

            public function semer(TypeDActivite $type, string $motDePasse): void
            {
                ($this->semer)($type, $motDePasse);
            }
        });
        $this->travelTo(self::aParis('2026-11-21 01:10:00')->addMinutes(self::jeton()));

        try {
            app(ConstruireUnModele::class)->construire(TypeDActivite::Services);
            $this->fail('La construction devait échouer.');
        } catch (ConstructionEnEchec $echec) {
            $this->assertStringContainsString('La vérification du journal d\'audit du modèle', $echec->getMessage());
            $this->assertStringContainsString('altérée ou hors de sa place dans la chaîne', $echec->getMessage());
        }

        $rate = Modele::on('demo_admin')->where('etat', EtatDUnModele::EnEchec)->sole();
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($rate->nom_de_la_base), 'La base de travail est supprimée.');
        $this->assertSame($ancien->nom_de_la_base, Modele::on('demo_admin')->where('type', 'services')->where('etat', EtatDUnModele::EnService)->sole()->nom_de_la_base, 'L\'ancien modèle reste en service.');
    }

    public function test_la_bascule_rend_l_ancien_modele_precedent_puis_supprime_celui_d_avant(): void
    {
        $premier = $this->modeleDeTest(TypeDActivite::Services);
        $this->app->instance(SemisDUnModele::class, new class implements SemisDUnModele
        {
            public function semer(TypeDActivite $type, string $motDePasse): void {}
        });
        $bases = app(BasesDeLaDemonstration::class);

        $this->travelTo(self::aParis('2026-11-19 01:10:00')->addMinutes(self::jeton()));
        $deuxieme = app(ConstruireUnModele::class)->construire(TypeDActivite::Services);
        $this->travelTo(self::aParis('2026-11-20 01:10:00')->addMinutes(self::jeton()));
        $troisieme = app(ConstruireUnModele::class)->construire(TypeDActivite::Services);

        $etats = Modele::on('demo_admin')->where('type', 'services')->pluck('etat', 'nom_de_la_base')->map(fn (EtatDUnModele $etat): string => $etat->value)->all();
        $this->assertSame([
            $premier->nom_de_la_base => 'supprime',
            $deuxieme->nom_de_la_base => 'precedent',
            $troisieme->nom_de_la_base => 'en_service',
        ], $etats);
        $this->assertTrue($bases->existe($deuxieme->nom_de_la_base), 'Le modèle précédent reste jusqu\'à la construction réussie suivante.');
        $this->assertTrue($bases->existe($troisieme->nom_de_la_base));
    }

    public function test_la_construction_et_le_nettoyage_ne_sont_planifies_que_sur_le_site_de_demonstration_la_nuit_sur_un_seul_serveur(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $taches = collect(app(Schedule::class)->events())->mapWithKeys(fn (Event $tache): array => [(string) preg_replace('/^.*artisan\S*\s+/', '', (string) $tache->command) => $tache]);

        foreach (['demonstration:construire-les-modeles' => '10 1 * * *', 'demonstration:nettoyer' => '10 3 * * *', 'demonstration:reprendre-les-copies' => '25 * * * *', 'demonstration:purger-les-archives' => '10 4 * * *'] as $commande => $horaire) {
            $this->assertArrayHasKey($commande, $taches->all());
            $this->assertSame($horaire, $taches[$commande]->expression);
            $this->assertTrue($taches[$commande]->onOneServer, $commande.' tourne sur un seul serveur.');
            $this->assertSame(Calendrier::deParis()->fuseau(), (string) $taches[$commande]->timezone);
        }

        $this->assertArrayNotHasKey('demonstration:lire-l-etat', $taches->all(), 'Les tâches du site réel ne tournent pas sur le site de démonstration.');
    }

    public function test_le_nettoyage_archive_puis_supprime_une_copie_orpheline_et_ne_touche_rien_hors_du_motif_exact(): void
    {
        $modele = $this->modeleDeTest(TypeDActivite::Commerce);
        $bases = app(BasesDeLaDemonstration::class);
        $this->copiesEnAttente(10);
        $orpheline = $bases->nomDUneCopie();
        $bases->copierUnModele($modele->nom_de_la_base, $orpheline);
        $horsDuMotif = 'demo_autre_test_'.self::jeton();
        DB::connection('demo_admin')->unprepared('create database "'.$horsDuMotif.'" template template0');

        try {
            $listees = $bases->lister();
            $this->assertContains($orpheline, $listees);
            $this->assertNotContains($horsDuMotif, $listees, 'Le nettoyage ne lit que les bases du motif exact de la démonstration.');

            $resultat = app(NettoyageDeLaDemonstration::class)->nettoyer([$modele->nom_de_la_base, $orpheline, $horsDuMotif]);

            $this->assertNull($resultat['refus']);
            $this->assertSame([$orpheline], $resultat['supprimees']);
            $this->assertFalse($bases->existe($orpheline));
            $this->assertTrue(DB::connection('demo_admin')->table('pg_database')->where('datname', $horsDuMotif)->exists(), 'Une base hors du motif exact n\'est jamais touchée.');
            $this->assertTrue($bases->existe($modele->nom_de_la_base), 'Le modèle en service reste.');
            $archive = Archive::on('demo_admin')->sole();
            $this->assertSame('nettoyage', $archive->motif);
            $this->assertFileExists($archive->chemin);
        } finally {
            DB::connection('demo_admin')->unprepared('drop database if exists "'.$horsDuMotif.'"');
        }
    }

    public function test_le_nettoyage_refuse_d_agir_quand_le_registre_est_vide_alors_que_des_copies_existent(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $base = (string) $copie->nom_de_la_base;
        DB::connection('demo_admin')->unprepared('truncate temoins, liens, copies restart identity cascade');

        try {
            $this->assertSame(sprintf(NettoyageDeLaDemonstration::MESSAGE_REGISTRE_VIDE, 1), app(NettoyageDeLaDemonstration::class)->nettoyer([$base])['refus']);
            $this->assertTrue(app(BasesDeLaDemonstration::class)->existe($base), 'Un registre vide ne fait rien disparaître.');
            $this->assertContains(sprintf(NettoyageDeLaDemonstration::MESSAGE_REGISTRE_VIDE, 1), app(EtatDuSite::class)->construire()['problemes']);
        } finally {
            app(BasesDeLaDemonstration::class)->supprimer($base);
        }
    }

    public function test_le_nettoyage_refuse_quand_plus_de_dix_pour_cent_des_copies_vivantes_seraient_supprimees(): void
    {
        $modele = $this->modeleDeTest(TypeDActivite::Services);
        $bases = app(BasesDeLaDemonstration::class);
        $this->copiesEnAttente(9);
        $orpheline = $bases->nomDUneCopie();
        $bases->copierUnModele($modele->nom_de_la_base, $orpheline);

        try {
            $resultat = app(NettoyageDeLaDemonstration::class)->nettoyer([$modele->nom_de_la_base, $orpheline]);

            $this->assertSame(sprintf(NettoyageDeLaDemonstration::MESSAGE_TROP_D_ORPHELINES, 1, 10, 9), $resultat['refus']);
            $this->assertTrue($bases->existe($orpheline));
        } finally {
            $bases->supprimer($orpheline);
        }
    }

    public function test_le_nettoyage_refait_une_copie_perdue_et_supprime_les_bases_de_travail_abandonnees(): void
    {
        $this->travelTo(self::aParis('2026-11-18 12:00:00'));
        $modele = $this->modeleDeTest(TypeDActivite::Services);
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $perdue = (string) $copie->nom_de_la_base;
        Temoin::on('demo_admin')->create(['empreinte' => hash('sha256', 'perdue'), 'copie_id' => $copie->id, 'generation_du_lien' => $copie->generation_du_lien, 'entree' => 'lien', 'cree_le' => CarbonImmutable::now(), 'fin' => $copie->fin, 'compte_choisi' => 7]);
        $bases = app(BasesDeLaDemonstration::class);
        $bases->supprimer($perdue);
        $abandonnee = $bases->nomDUneBaseDeTravail(TypeDActivite::Services, self::aParis('2026-11-18 05:00:00')->addMinutes(self::jeton()));
        $recente = $bases->nomDUneBaseDeTravail(TypeDActivite::Services, self::aParis('2026-11-18 07:00:00')->addMinutes(self::jeton()));
        $bases->supprimer($abandonnee);
        $bases->supprimer($recente);
        $bases->creerUneBaseVide($abandonnee);
        $bases->creerUneBaseVide($recente);

        try {
            $resultat = app(NettoyageDeLaDemonstration::class)->nettoyer([$modele->nom_de_la_base, $abandonnee, $recente]);

            $this->assertSame([$copie->reference], $resultat['perdues']);
            $refaite = Copie::on('demo_admin')->whereKey($copie->id)->sole();
            $this->assertSame(EtatDUneCopie::Prete, $refaite->etat, 'Une copie perdue est refaite aussitôt depuis le modèle (E.7).');
            $this->assertNotSame($perdue, $refaite->nom_de_la_base);
            $this->assertTrue($bases->existe((string) $refaite->nom_de_la_base));
            $this->assertSame($copie->fin->getTimestamp(), $refaite->fin->getTimestamp(), 'Même fin.');
            $this->assertSame(FinDesCopies::MOTIF_PERDUE, $refaite->motif_du_message);
            $this->assertSame('Votre démonstration a dû être refaite le 18/11/2026 ; votre lien reste le même.', $refaite->message);
            $this->assertNull(Temoin::on('demo_admin')->where('copie_id', $copie->id)->sole()->compte_choisi, 'Le compte choisi en dernier désigne un compte de la base perdue : il est effacé.');
            $this->assertFalse($bases->existe($abandonnee), 'Une base de travail de plus de 6 heures est supprimée.');
            $this->assertTrue($bases->existe($recente), 'Une base de travail de moins de 6 heures reste : sa construction peut tourner encore.');
            $this->assertTrue($bases->existe($modele->nom_de_la_base), 'Un modèle en service reste, quel que soit son âge.');
        } finally {
            $bases->supprimer($abandonnee);
            $bases->supprimer($recente);
        }
    }

    /**
     * Des copies vivantes au registre, qui attendent une place, sans base.
     */
    private function copiesEnAttente(int $nombre): void
    {
        for ($indice = 0; $indice < $nombre; $indice++) {
            Copie::on('demo_admin')->create([
                'reference' => bin2hex(random_bytes(16)),
                'repere' => 'K7M2QD',
                'type' => TypeDActivite::Services,
                'validee' => true,
                'creee_le' => CarbonImmutable::now(),
                'fin' => CarbonImmutable::now()->addSeconds(30 * 86400),
                'generation_du_lien' => 1,
                'etat' => EtatDUneCopie::EnAttenteDUnePlace,
            ]);
        }
    }
}
