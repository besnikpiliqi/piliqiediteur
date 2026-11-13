<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Tenancy\Actions\ChangerFinExerciceAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\OrganeDeLaDecision;
use App\Domain\Tenancy\Models\ChangementFinExercice;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ExercicesDeLaSociete;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Le changement légal de la date de clôture de l'exercice d'une société,
 * sur procès-verbal, par la procédure de l'éditeur (voir
 * ChangerFinExerciceAction) : son mois de fin d'exercice, verrouillé dès sa
 * première vente, change par elle seule, avec la fin de l'exercice en cours
 * que fixe le procès-verbal ; il s'inscrit au journal des événements de
 * chaque magasin, dans changements_fin_exercice et au journal d'audit.
 * Jamais un exercice clos ne se modifie, ni une clôture ne se repousse :
 * seul un exercice raccourci, terminé le dernier jour d'un mois, est
 * accepté ; l'organe qui a décidé est obligatoire, le nom d'un
 * expert-comptable consulté facultatif (T28.1, T28.2) ; un changement
 * n'est jamais rétroactif, et les exercices de
 * tout jour passé se reconstituent par l'historique des changements. Le
 * temps est figé au 2 novembre 2026, à Paris : l'exercice en cours, de
 * décembre, va du 1er janvier au 31 décembre 2026.
 */
class ChangerFinExerciceTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Procès-verbal de l\'assemblée générale extraordinaire reçu';

    private const PIECE = 'PV-AGE-2026-10-15';

    private const EXPERT_COMPTABLE = 'Claire Martin, Cabinet Martin et associés';

    private const DEPOT_AU_REGISTRE = 'Dépôt au RCS de Lyon n° 2026-A-04512';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    /**
     * Par la commande, le changement à l'exercice suivant s'inscrit au
     * journal des événements de chaque magasin réel (le mois, de 12 à 6),
     * dans changements_fin_exercice avec sa pièce, sa décision, son
     * organe, la référence de son dépôt au registre, l'expert-comptable
     * consulté et ses opérateurs, et au journal d'audit, origine
     * « éditeur » ; le résumé montre l'exercice transitoire de six mois,
     * l'organe et l'expert-comptable consulté.
     */
    public function test_le_changement_legal_s_inscrit_dans_chaque_magasin_avec_sa_piece(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->vendre($lyon);
        $societe = $lyon->societe;
        $signaturesDeLyon = $this->signaturesDuRegistre($lyon);
        $signaturesDeVilleurbanne = $this->signaturesDuRegistre($villeurbanne);

        $this->artisan('editeur:societe:changer-fin-exercice', [
            'societe' => (string) $societe->id,
            '--mois' => '6',
            '--fin-exercice-en-cours' => '2026-12-31',
            '--decision-du' => '2026-10-15',
            '--organe' => 'assemblee_generale_extraordinaire',
            '--depot-au-registre' => self::DEPOT_AU_REGISTRE,
            '--duree-validee-par' => self::EXPERT_COMPTABLE,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
        ])
            ->expectsOutputToContain('Exercice en cours : du 01/01/2026 au 31/12/2026 (12 mois ; fin d\'origine le 31/12/2026).')
            ->expectsOutputToContain('Exercice suivant : du 01/01/2027 au 30/06/2027 (6 mois).')
            ->expectsOutputToContain('Organe de la décision : Assemblée générale extraordinaire.')
            ->expectsOutputToContain('Expert-comptable consulté : Claire Martin, Cabinet Martin et associés.')
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice')
            ->expectsOutputToContain('Date de clôture de l\'exercice changée : fin d\'exercice en juin, l\'exercice en cours finissant le 31/12/2026.')
            ->assertSuccessful();

        $this->assertSame(6, $societe->fresh()->mois_fin_exercice);
        foreach ([$lyon, $villeurbanne] as $magasin) {
            $this->assertContains(['parametre' => 'mois_fin_exercice', 'ancienne_valeur' => 12, 'nouvelle_valeur' => 6], $this->parametresJournalises($magasin));
        }

        $changement = app(TenantContext::class)->runAsSociete($societe, fn (): ChangementFinExercice => ChangementFinExercice::query()->sole());
        $this->assertSame(
            [12, 6, '2026-01-01', '2026-12-31', '2026-12-31', '2026-10-15', OrganeDeLaDecision::AssembleeGeneraleExtraordinaire, self::PIECE, self::DEPOT_AU_REGISTRE, self::EXPERT_COMPTABLE, self::JUSTIFICATION, ['alice']],
            [$changement->ancien_mois, $changement->nouveau_mois, $changement->debut_exercice_en_cours, $changement->fin_exercice_en_cours_avant, $changement->fin_exercice_en_cours_apres, $changement->decision_du, $changement->organe_de_la_decision, $changement->piece_reference, $changement->depot_au_registre, $changement->duree_validee_par, $changement->justification, $changement->operateur['operateurs']],
        );
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::FinExerciceChangee);
        $this->assertSame(['editeur', ['fin_exercice_en_cours' => '2026-12-31', 'mois_fin_exercice' => 12]], [$entree->origine, $entree->anciennes_valeurs]);
        $this->assertEquals([
            'mois_fin_exercice' => 6,
            'fin_exercice_en_cours' => '2026-12-31',
            'decision_du' => '2026-10-15',
            'organe_de_la_decision' => 'assemblee_generale_extraordinaire',
            'duree_validee_par' => self::EXPERT_COMPTABLE,
        ], $entree->nouvelles_valeurs);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('changements_fin_exercice')->update(['nouveau_mois' => 3])), QueryException::class, 'changements_fin_exercice : table en ajout seul, modification refusée');
        foreach ([[$lyon, $signaturesDeLyon], [$villeurbanne, $signaturesDeVilleurbanne]] as [$magasin, $signatures]) {
            $this->assertSame($signatures, array_intersect_key($this->signaturesDuRegistre($magasin), $signatures));
        }
        $this->assertUnSeulProprietaireActif($societe);
    }

    /**
     * Inchangée, la fin de l'exercice en cours reste celle d'origine : le
     * nouveau mois vaut à partir de l'exercice suivant, transitoire, du 1er
     * janvier au 30 juin 2027 ; aucune fin avancée ne s'inscrit au journal
     * des événements.
     */
    public function test_l_effet_au_prochain_exercice_garde_la_fin_de_l_exercice_en_cours(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);

        $this->changer($magasin->societe, 6, '2026-12-31', '2026-10-15');

        $this->assertSame(['2026-01-01', '2026-12-31'], $this->exerciceEnCours($magasin->societe, '2026-11-02'));
        $this->assertSame(['2027-01-01', '2027-06-30'], $this->exerciceEnCours($magasin->societe, '2027-01-15'));
        $this->assertSame(['2027-07-01', '2028-06-30'], $this->exerciceEnCours($magasin->societe, '2027-07-01'));
        $this->assertNotContains('fin_exercice_en_cours', array_column($this->parametresJournalises($magasin), 'parametre'));
    }

    /**
     * Avancée par le procès-verbal, la fin de l'exercice en cours en prend
     * la date, le 30 novembre 2026, et s'inscrit au journal des événements ;
     * l'exercice suivant, transitoire, finit au nouveau mois, le 30 juin
     * 2027.
     */
    public function test_un_changement_de_l_exercice_en_cours_prend_la_date_du_proces_verbal(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);

        $this->changer($magasin->societe, 6, '2026-11-30', '2026-10-15');

        $this->assertSame(['2026-01-01', '2026-11-30'], $this->exerciceEnCours($magasin->societe, '2026-11-02'));
        $this->assertSame(['2026-12-01', '2027-06-30'], $this->exerciceEnCours($magasin->societe, '2026-12-15'));
        $this->assertContains(['parametre' => 'fin_exercice_en_cours', 'ancienne_valeur' => '2026-12-31', 'nouvelle_valeur' => '2026-11-30'], $this->parametresJournalises($magasin));
    }

    /**
     * Une fin qui tombe avant le début de l'exercice en cours, dans un
     * exercice déjà clos, est refusée : jamais un exercice clos modifié ;
     * rien ne s'écrit.
     */
    public function test_une_fin_avant_la_derniere_cloture_d_exercice_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);

        $this->assertThrows(fn () => $this->changer($magasin->societe, 6, '2025-12-31', '2026-10-15'), LogicException::class, sprintf(ChangerFinExerciceAction::MESSAGE_EXERCICE_CLOS, '01/01/2026'));

        $this->assertNothingChanged($magasin->societe);
    }

    /**
     * Une fin qui repousserait la clôture de l'exercice en cours au-delà de
     * sa fin d'origine est refusée : jamais une clôture obligatoire
     * repoussée ; rien ne s'écrit.
     */
    public function test_une_fin_qui_repousserait_la_cloture_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);

        $this->assertThrows(fn () => $this->changer($magasin->societe, 6, '2027-03-31', '2026-10-15'), LogicException::class, 'Cette fin est postérieure à celle de l\'exercice en cours (31/12/2026) : elle repousserait une clôture obligatoire. PILIQI n\'accepte qu\'un exercice raccourci, terminé le dernier jour d\'un mois.');

        $this->assertNothingChanged($magasin->societe);
    }

    /**
     * Jamais rétroactif : un procès-verbal du 15 octobre 2026 qui fixe la
     * fin de l'exercice en cours au 30 septembre 2026, deux semaines avant
     * lui, est refusé, comme une décision postérieure à aujourd'hui ; rien
     * ne s'écrit. La même fin, décidée le 15 septembre, avant elle, passe.
     */
    public function test_une_decision_posterieure_a_la_fin_qu_elle_fixe_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);

        $this->assertThrows(fn () => $this->changer($magasin->societe, 6, '2026-09-30', '2026-10-15'), LogicException::class, sprintf(ChangerFinExerciceAction::MESSAGE_DECISION_APRES_LA_FIN, '30/09/2026'));
        $this->assertThrows(fn () => $this->changer($magasin->societe, 6, '2026-12-31', '2026-11-20'), LogicException::class, ChangerFinExerciceAction::MESSAGE_DECISION_FUTURE);
        $this->assertNothingChanged($magasin->societe);

        $this->changer($magasin->societe, 6, '2026-09-30', '2026-09-15');

        $this->assertSame(['2026-10-01', '2027-06-30'], $this->exerciceEnCours($magasin->societe, '2026-11-02'));
    }

    /**
     * Les exercices d'un jour passé se reconstituent par l'historique des
     * changements : avant le premier, au mois qu'il a quitté (décembre) ;
     * entre deux changements, au mois que le second a quitté (juin) ;
     * après le dernier, au mois de la société (septembre), le premier
     * exercice après lui, du 1er juillet au 30 septembre 2027, étant
     * transitoire.
     */
    public function test_les_exercices_d_un_jour_passe_se_reconstituent_par_l_historique_des_changements(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);
        $this->changer($magasin->societe, 6, '2026-12-31', '2026-10-15');
        $this->travelTo(self::aParis('2027-02-01 10:00:00'));
        $this->changer($magasin->societe, 9, '2027-06-30', '2027-01-20');

        $this->assertSame(['2025-01-01', '2025-12-31'], $this->exerciceEnCours($magasin->societe, '2025-06-15'));
        $this->assertSame(['2026-01-01', '2026-12-31'], $this->exerciceEnCours($magasin->societe, '2026-05-01'));
        $this->assertSame(['2027-01-01', '2027-06-30'], $this->exerciceEnCours($magasin->societe, '2027-03-01'));
        $this->assertSame(['2027-07-01', '2027-09-30'], $this->exerciceEnCours($magasin->societe, '2027-08-01'));
        $this->assertSame(['2027-10-01', '2028-09-30'], $this->exerciceEnCours($magasin->societe, '2027-10-01'));
    }

    /**
     * Par la commande, sans la confirmation retapée, en mode non
     * interactif, avec un opérateur inconnu ou un secret faux, ou quand la
     * procédure refuse le changement (une décision rétroactive), rien ne
     * change ; la société garde son seul propriétaire actif, et le registre
     * de son magasin, ses signatures.
     */
    public function test_la_commande_refuse_sans_confirmation_ni_operateur_authentifie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);
        $societe = $magasin->societe;
        $signatures = $this->signaturesDuRegistre($magasin);
        $parametres = [
            '--mois' => '6',
            '--fin-exercice-en-cours' => '2026-12-31',
            '--decision-du' => '2026-10-15',
            '--organe' => 'associe_unique',
            '--duree-validee-par' => self::EXPERT_COMPTABLE,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
        ];

        $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, [...$parametres, '--confirmer' => '000000018'], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, [...$parametres, '--confirmer' => null, '--no-interaction' => true], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, [...$parametres, '--no-interaction' => true], [])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();
        foreach ([['mallory', 'secret-d-alice'], ['alice', 'faux-secret']] as $operateur) {
            $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, $parametres, [$operateur])
                ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
                ->assertFailed();
        }
        $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, [...$parametres, '--fin-exercice-en-cours' => '2026-09-30'], [['alice', 'secret-d-alice']])
            ->expectsOutputToContain(sprintf(ChangerFinExerciceAction::MESSAGE_DECISION_APRES_LA_FIN, '30/09/2026'))
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:societe:changer-fin-exercice', $societe, [...$parametres, '--organe' => null], [['alice', 'secret-d-alice']])
            ->expectsOutputToContain('Organe de la décision : inconnu, la procédure le refusera.')
            ->expectsOutputToContain(ChangerFinExerciceAction::MESSAGE_ORGANE)
            ->assertFailed();

        $this->assertNothingChanged($societe);
        $this->assertSame($signatures, $this->signaturesDuRegistre($magasin));
        $this->assertUnSeulProprietaireActif($societe);
    }

    /**
     * Sans expert-comptable consulté, le changement est accepté : la
     * colonne reste nulle et le journal d'audit n'en nomme aucun (T28.1).
     */
    public function test_un_changement_sans_expert_comptable_consulte_est_accepte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);
        app(TenantContext::class)->clear();

        $changement = app(ChangerFinExerciceAction::class)->handle($magasin->societe->fresh(), 6, '2026-12-31', '2026-10-15', OrganeDeLaDecision::DecisionDesAssocies, '  ', null, $this->editeur());

        $this->assertSame([6, OrganeDeLaDecision::DecisionDesAssocies, null, null], [$magasin->societe->fresh()->mois_fin_exercice, $changement->organe_de_la_decision, $changement->duree_validee_par, $changement->depot_au_registre]);
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::FinExerciceChangee);
        $this->assertEquals([
            'mois_fin_exercice' => 6,
            'fin_exercice_en_cours' => '2026-12-31',
            'decision_du' => '2026-10-15',
            'organe_de_la_decision' => 'decision_des_associes',
            'duree_validee_par' => null,
        ], $entree->nouvelles_valeurs);
    }

    /**
     * Sans l'organe qui a décidé, ou avec un nom d'expert-comptable ou une
     * référence de dépôt trop longs, le changement est refusé, avec son
     * message exact, et rien ne s'écrit.
     */
    public function test_un_changement_sans_organe_de_la_decision_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);

        $this->assertThrows(
            fn () => app(ChangerFinExerciceAction::class)->handle($magasin->societe, 6, '2026-12-31', '2026-10-15', null, self::EXPERT_COMPTABLE, null, $this->editeur()),
            LogicException::class,
            'Indiquez l\'organe qui a décidé le changement, tel que le nomme le procès-verbal : assemblée générale extraordinaire, décision des associés, organe prévu par les statuts, associé unique ou entrepreneur individuel.',
        );
        $this->assertThrows(
            fn () => app(ChangerFinExerciceAction::class)->handle($magasin->societe, 6, '2026-12-31', '2026-10-15', OrganeDeLaDecision::AssocieUnique, str_repeat('a', 151), null, $this->editeur()),
            LogicException::class,
            'Le nom de l\'expert-comptable consulté compte 150 caractères au plus.',
        );
        $this->assertThrows(
            fn () => app(ChangerFinExerciceAction::class)->handle($magasin->societe, 6, '2026-12-31', '2026-10-15', OrganeDeLaDecision::AssocieUnique, null, str_repeat('a', 101), $this->editeur()),
            LogicException::class,
            'La référence du dépôt au registre compte 100 caractères au plus.',
        );

        $this->assertNothingChanged($magasin->societe);
    }

    /**
     * Un mois inchangé, ou une fin qui n'est pas le dernier jour d'un mois,
     * est refusé.
     */
    public function test_un_mois_inchange_ou_une_fin_qui_n_est_pas_une_fin_de_mois_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);

        $this->assertThrows(fn () => $this->changer($magasin->societe, 12, '2026-12-31', '2026-10-15'), LogicException::class, ChangerFinExerciceAction::MESSAGE_MOIS);
        $this->assertThrows(fn () => $this->changer($magasin->societe, 6, '2026-11-15', '2026-10-15'), LogicException::class, 'La fin d\'un exercice est le dernier jour d\'un mois. PILIQI n\'accepte qu\'un exercice raccourci, terminé le dernier jour d\'un mois.');

        $this->assertNothingChanged($magasin->societe);
    }

    /**
     * Hors de la procédure, le mois de fin d'exercice reste verrouillé
     * après la première vente, même après un changement légal.
     */
    public function test_hors_procedure_le_mois_reste_verrouille_apres_la_premiere_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->vendre($magasin);
        $this->changer($magasin->societe, 6, '2026-12-31', '2026-10-15');
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe->fresh(), ['mois_fin_exercice' => 3]),
            LogicException::class,
            'Le mois de fin d\'exercice est verrouillé depuis la première vente',
        );
        $this->assertSame(6, $magasin->societe->fresh()->mois_fin_exercice);
    }

    private function changer(Societe $societe, int $mois, string $fin, string $decision): ChangementFinExercice
    {
        app(TenantContext::class)->clear();

        return app(ChangerFinExerciceAction::class)->handle($societe->fresh(), $mois, $fin, $decision, OrganeDeLaDecision::AssembleeGeneraleExtraordinaire, self::EXPERT_COMPTABLE, null, $this->editeur());
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice']);
    }

    /**
     * Le début et la fin de l'exercice en cours de la société ce jour-là.
     *
     * @return array{0: string, 1: string}
     */
    private function exerciceEnCours(Societe $societe, string $jour): array
    {
        return app(TenantContext::class)->runAsSociete($societe->fresh(), fn (): array => [
            app(ExercicesDeLaSociete::class)->debutDeLExerciceEnCours($societe->fresh(), $jour),
            app(ExercicesDeLaSociete::class)->finDeLExerciceEnCours($societe->fresh(), $jour),
        ]);
    }

    /**
     * Aucun changement : le mois de la société, aucune ligne de
     * changement, aucune entrée d'audit.
     */
    private function assertNothingChanged(Societe $societe): void
    {
        $this->assertSame(12, $societe->fresh()->mois_fin_exercice);
        $this->assertSame(0, DB::table('changements_fin_exercice')->count());
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::FinExerciceChangee));
    }

    /**
     * Un ticket de 12,00 € TTC au comptoir du magasin : la première vente
     * de la société, qui verrouille son mois de fin d'exercice.
     */
    private function vendre(Magasin $magasin): void
    {
        app(TenantContext::class)->set($magasin->fresh());
        self::vendreAuComptant(
            self::responsableDeTest($magasin),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }

    /**
     * Les paramètres de conformité inscrits au journal des événements du
     * magasin (JET 270), dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function parametresJournalises(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => array_intersect_key($evenement->contenuSigne()['donnees']['details'], array_flip(['parametre', 'ancienne_valeur', 'nouvelle_valeur'])))
            ->all());
    }
}
