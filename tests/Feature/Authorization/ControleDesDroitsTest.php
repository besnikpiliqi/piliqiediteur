<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\GardesDesDroits;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le seul rappel exécuté avant les policies (voir ControleDesDroits) : les
 * droits implicites du propriétaire de la société, les permissions du
 * membre dans le magasin actif, et, dans un magasin en lecture seule, le
 * refus de tout ce qui n'y reste pas permis ; tant que le magasin ne peut
 * pas vendre, le refus des opérations commerciales.
 */
class ControleDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque autorisation du propriétaire, et si elle lui reste dans un
     * magasin en lecture seule : consulter, clôturer la journée et gérer
     * l'équipe seulement.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function autorisationsEnLectureSeule(): array
    {
        $permises = [
            PermissionRegistry::CLIENT_VIEW,
            PermissionRegistry::FOURNISSEUR_VIEW,
            PermissionRegistry::PRODUIT_VIEW,
            PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK,
            PermissionRegistry::DEVIS_VIEW,
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_VIEW_TOUTES,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::AVOIR_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
            PermissionRegistry::CLOTURE_CAISSE_GERER,
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::AVOIR_FOURNISSEUR_VIEW,
            PermissionRegistry::CAISSE_SECURISEE_CONSULTER,
        ];
        $refusees = [
            PermissionRegistry::CLIENT_CREATE,
            PermissionRegistry::CLIENT_UPDATE,
            PermissionRegistry::CLIENT_ARCHIVER,
            PermissionRegistry::FOURNISSEUR_CREATE,
            PermissionRegistry::FOURNISSEUR_UPDATE,
            PermissionRegistry::FOURNISSEUR_ARCHIVER,
            PermissionRegistry::PRODUIT_CREATE,
            PermissionRegistry::PRODUIT_UPDATE,
            PermissionRegistry::PRODUIT_ARCHIVER,
            PermissionRegistry::PRODUIT_AJUSTER_STOCK,
            PermissionRegistry::PRIX_VENTE_REGLER,
            PermissionRegistry::DEVIS_CREATE,
            PermissionRegistry::DEVIS_UPDATE,
            PermissionRegistry::DEVIS_ENVOYER,
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::FACTURE_EMETTRE,
            PermissionRegistry::FACTURE_ENVOYER,
            PermissionRegistry::RELANCES_REGLER,
            PermissionRegistry::PARAMETRES_GERER,
            PermissionRegistry::PAIEMENT_CREER,
            PermissionRegistry::PAIEMENT_CONTRE_PASSER,
            PermissionRegistry::AVOIR_CREER,
            PermissionRegistry::AVOIR_ENVOYER,
            PermissionRegistry::FACTURE_ACHAT_CREER,
            PermissionRegistry::FACTURE_ACHAT_VALIDER,
            PermissionRegistry::AVOIR_FOURNISSEUR_CREER,
        ];

        return [
            ...array_combine($permises, array_map(static fn (string $permission): array => [$permission, true], $permises)),
            ...array_combine($refusees, array_map(static fn (string $permission): array => [$permission, false], $refusees)),
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function autorisations(): array
    {
        return array_map(static fn (array $regle): array => [$regle[0]], self::autorisationsEnLectureSeule());
    }

    /**
     * La liste ci-dessus couvre toutes les autorisations : une nouvelle
     * autorisation se range parmi les permises ou les refusées.
     */
    public function test_chaque_autorisation_a_sa_regle_en_lecture_seule(): void
    {
        $couvertes = array_keys(self::autorisationsEnLectureSeule());

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $couvertes);
    }

    #[DataProvider('autorisations')]
    public function test_le_proprietaire_d_un_magasin_actif_garde_chaque_autorisation(string $permission): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->assertTrue($proprietaire->can($permission));
    }

    #[DataProvider('autorisationsEnLectureSeule')]
    public function test_le_proprietaire_d_un_magasin_suspendu_ne_garde_que_les_autorisations_permises(string $permission, bool $permise): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->suspendre($magasin);

        $this->assertSame($permise, $proprietaire->can($permission));
    }

    /**
     * Permise ne veut pas dire accordée : un vendeur sans accès au registre
     * fiscal n'y accède pas plus en lecture seule.
     */
    public function test_une_autorisation_permise_en_lecture_seule_reste_a_accorder(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->fermer($magasin);

        $this->assertFalse($vendeur->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
    }

    public function test_en_lecture_seule_consulter_cloturer_et_gerer_l_equipe_restent_permis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $sonAffectation = Affectation::query()->where('magasin_id', $magasin->id)->where('user_id', $vendeur->id)->sole();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 1]]);
        $this->suspendre($magasin);

        $this->assertTrue($proprietaire->can('viewAny', Facture::class));
        $this->assertTrue($proprietaire->can('view', $brouillon));
        $this->assertTrue($proprietaire->can('cloturer', ClotureCaisse::class));
        $this->assertTrue($proprietaire->can('fermer', ClotureCaissePersonnelle::class));
        $this->assertTrue($proprietaire->can('fermerLaCaisseDe', [ClotureCaissePersonnelle::class, $vendeur]));
        $this->assertTrue($proprietaire->can('create', Affectation::class));
        $this->assertTrue($proprietaire->can('update', $sonAffectation));
        $this->assertTrue($proprietaire->can('delete', $sonAffectation));
    }

    public function test_en_lecture_seule_tout_autre_geste_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->fermer($magasin);

        $this->assertFalse($proprietaire->can('create', Facture::class));
        $this->assertFalse($proprietaire->can('update', $brouillon));
        $this->assertFalse($proprietaire->can('delete', $brouillon));
        $this->assertFalse($proprietaire->can('emettre', $brouillon));
        $this->assertFalse($proprietaire->can('update', $produit));
    }

    /**
     * Le propriétaire de la société n'est rattaché à aucun magasin : il a
     * toutes les autorisations dans chacun de ses magasins, sans case.
     */
    public function test_le_proprietaire_a_tous_les_droits_dans_chaque_magasin_sans_affectation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin);

        $this->assertSame(0, Affectation::query()->where('user_id', $proprietaire->id)->count());

        foreach ([$magasin, $autreMagasin] as $chaqueMagasin) {
            app(TenantContext::class)->set($chaqueMagasin);

            foreach (PermissionRegistry::toutes() as $permission) {
                $this->assertTrue($proprietaire->can($permission), "{$permission} dans le magasin {$chaqueMagasin->nom}");
            }
        }
    }

    /**
     * Ses droits implicites ne valent que dans sa société : dans le magasin
     * d'une autre, rien ne lui est accordé, pas même une consultation.
     */
    public function test_le_proprietaire_n_a_aucun_droit_dans_le_magasin_d_une_autre_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 1]]);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);

        $this->assertSame($autreSociete->id, app(TenantContext::class)->id());
        $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_VIEW));
        $this->assertFalse($proprietaire->can('viewAny', Facture::class));
        $this->assertFalse($proprietaire->can('view', $brouillon));
    }

    /**
     * @return array<string, array{StatutSociete}>
     */
    public static function statutsDeSocieteEnLectureSeule(): array
    {
        return [
            'suspendue' => [StatutSociete::Suspendue],
            'résiliée' => [StatutSociete::Resiliee],
            'révoquée' => [StatutSociete::Revoquee],
        ];
    }

    /**
     * Une société qui n'est plus active met tous ses magasins en lecture
     * seule : consulter reste permis, plus aucune vente.
     */
    #[DataProvider('statutsDeSocieteEnLectureSeule')]
    public function test_une_societe_qui_n_est_plus_active_ne_garde_que_les_autorisations_permises(StatutSociete $statut): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $magasin->societe->forceFill(['statut' => $statut])->save();

        $this->assertTrue($proprietaire->can(PermissionRegistry::FACTURE_VIEW));
        $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_EMETTRE));
        $this->assertFalse($proprietaire->can(PermissionRegistry::PRODUIT_CREATE));
    }

    /**
     * Un compte qui n'est pas actif n'a aucun droit, propriétaire compris,
     * pas même une consultation.
     */
    public function test_un_compte_suspendu_n_a_aucun_droit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        foreach ([$proprietaire, $vendeur] as $compte) {
            $compte->forceFill(['statut' => StatutCompte::Suspendu])->save();

            $this->assertFalse($compte->can(PermissionRegistry::FACTURE_VIEW));
            $this->assertFalse($compte->can('viewAny', Facture::class));
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function magasinsQuiNePeuventPasVendre(): array
    {
        return [
            'société en cours de vérification' => [['a_verifier' => true]],
            'établissement pas encore confirmé par Sirene' => [['etablissement_a_verifier' => true]],
        ];
    }

    /**
     * Tant que le magasin ne peut pas vendre, les opérations commerciales
     * sont refusées à tous, propriétaire compris ; le reste de la
     * configuration (catalogue, achats, paramètres) reste permis.
     *
     * @param  array<string, mixed>  $situation
     */
    #[DataProvider('magasinsQuiNePeuventPasVendre')]
    public function test_les_operations_commerciales_sont_refusees_tant_que_le_magasin_ne_peut_pas_vendre(array $situation): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $situation['a_verifier'] ?? false]);

        if ($situation['etablissement_a_verifier'] ?? false) {
            $magasin->forceFill(['etablissement_verifie_le' => null])->save();
        }

        $proprietaire = $this->proprietaireDe($magasin);

        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertSame(
                ! in_array($permission, PermissionRegistry::reserveesAuxOperationsCommerciales(), true),
                $proprietaire->can($permission),
                $permission,
            );
        }
    }

    /**
     * Sans magasin actif ni société posée (une commande, un job sans
     * contexte), aucune policy d'un modèle de magasin n'accorde rien, ni
     * aucune autorisation de magasin, pas même au propriétaire.
     */
    public function test_sans_contexte_aucune_policy_de_magasin_n_accorde_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        app(TenantContext::class)->clear();

        foreach ([$proprietaire, $responsable] as $compte) {
            foreach ([Client::class, Fournisseur::class, Produit::class, Service::class, Devis::class, Facture::class, Paiement::class, Avoir::class, ClotureCaisse::class, ClotureCaissePersonnelle::class, FactureAchat::class, AvoirFournisseur::class, EnregistrementFiscal::class, Affectation::class] as $modele) {
                $this->assertFalse($compte->can('viewAny', $modele), "viewAny {$modele}");
                $this->assertFalse($compte->can('create', $modele), "create {$modele}");
            }

            foreach (PermissionRegistry::toutes() as $permission) {
                $this->assertFalse($compte->can($permission), $permission);
            }
        }
    }

    /**
     * Le propriétaire passe les policies sans aucune case, mais les règles
     * qui valent pour tous s'appliquent à lui : un magasin fermé ne vend
     * plus, une facture émise ne se supprime jamais, une journée clôturée
     * refuse toute vente, et une société qui n'est pas validée n'émet rien.
     */
    public function test_le_proprietaire_n_a_besoin_d_aucune_case_mais_reste_soumis_aux_regles_de_tous(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->actingAs($proprietaire);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $emise = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $this->assertSame(0, Affectation::query()->where('user_id', $proprietaire->id)->count());
        $this->assertTrue($proprietaire->can('emettre', $brouillon));
        $this->assertFalse($proprietaire->can('delete', $emise));
        $this->assertFalse($proprietaire->can('update', $emise));

        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon), LogicException::class);

        $this->fermer($magasin);
        $this->assertFalse($proprietaire->can('emettre', $brouillon));
        $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_CREATE));

        $aValider = $this->ouvrirUneSociete(['nom' => 'Boutique à valider', 'a_verifier' => true]);
        $proprietaireAValider = $this->proprietaireDe($aValider);
        $this->assertFalse($proprietaireAValider->can(PermissionRegistry::FACTURE_EMETTRE));
        $this->assertTrue($proprietaireAValider->can(PermissionRegistry::PRODUIT_CREATE));
    }

    /**
     * Un responsable a toutes les autorisations de son magasin, sans case,
     * et aucune dans un autre magasin de la société.
     */
    public function test_un_responsable_a_toutes_les_autorisations_de_son_magasin_et_aucune_ailleurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);

        $this->assertSame(0, DB::table('autorisations_affectations')->join('affectations', 'affectations.id', '=', 'autorisations_affectations.affectation_id')->where('affectations.user_id', $responsable->id)->count());

        app(TenantContext::class)->set($magasin);
        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertTrue($responsable->can($permission), "{$permission} dans son magasin");
        }

        app(TenantContext::class)->set($autre);
        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertFalse($responsable->can($permission), "{$permission} dans l'autre magasin");
        }
        $this->assertFalse($responsable->can('viewAny', Facture::class));
    }

    /**
     * Une autorisation de société ne suffit jamais seule : l'administrateur
     * d'une sélection, même autorisé à gérer les magasins et à nommer les
     * responsables, ne gère ni ne nomme rien dans un magasin hors de sa
     * sélection, et n'y a aucune autorisation de magasin.
     */
    public function test_une_autorisation_de_societe_ne_vaut_jamais_seule_pour_un_magasin_hors_de_la_portee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER]);

        $this->assertTrue($administrateur->can(AutorisationsSociete::RESPONSABLES_NOMMER));
        $this->assertTrue($administrateur->can('nommerUnResponsable', $magasin));
        $this->assertTrue($administrateur->can('gererLesMagasins', $magasin->societe));
        $this->assertTrue($administrateur->can('view', $magasin));
        $this->assertFalse($administrateur->can('nommerUnResponsable', $horsPortee));
        $this->assertFalse($administrateur->can('view', $horsPortee));
        $this->assertFalse($administrateur->administreLeMagasin($horsPortee));
        $this->assertFalse($administrateur->can('creerUnMagasin', $magasin->societe));

        app(TenantContext::class)->set($horsPortee);
        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertFalse($administrateur->can($permission), $permission);
        }
    }

    /**
     * L'expert-comptable n'a que les consultations, quelles que soient les
     * lignes que garderait la base : une affectation de responsable, des
     * cases et des autorisations de société glissées sans les gardes ne lui
     * donnent aucune écriture ni aucune autorisation de société.
     */
    public function test_l_expert_comptable_n_a_que_les_consultations_quelles_que_soient_les_lignes_en_base(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $expert = $this->expertComptable($magasin);
        $this->retirerLaGardeDesDroits(GardesDesDroits::AFFECTATIONS);
        $this->retirerLaGardeDesDroits(GardesDesDroits::CASES);
        $this->retirerLaGardeDesDroits(GardesDesDroits::AUTORISATIONS_SOCIETE);
        $affectation = DB::table('affectations')->insertGetId(['societe_id' => $magasin->societe_id, 'user_id' => $expert->id, 'magasin_id' => $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('affectations')->insert(['societe_id' => $magasin->societe_id, 'user_id' => $expert->id, 'magasin_id' => null, 'role' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('autorisations_affectations')->insert(array_map(fn (string $autorisation): array => ['affectation_id' => $affectation, 'autorisation' => $autorisation], PermissionRegistry::toutes()));
        DB::table('autorisations_societe')->insert(array_map(fn (string $autorisation): array => ['societe_id' => $magasin->societe_id, 'user_id' => $expert->id, 'autorisation' => $autorisation], AutorisationsSociete::toutes()));
        $expert = $expert->refresh();

        app(TenantContext::class)->set($magasin);
        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertSame(in_array($permission, PermissionRegistry::consultations(), true), $expert->can($permission), $permission);
        }
        foreach (AutorisationsSociete::toutes() as $autorisation) {
            $this->assertFalse($expert->can($autorisation), $autorisation);
        }
        $this->assertTrue($expert->can('viewAny', Facture::class));
        $this->assertFalse($expert->can('create', Facture::class));
        $this->assertFalse($expert->can('create', Client::class));
        $this->assertFalse($expert->can('cloturer', ClotureCaisse::class));
        $this->assertSame([], $expert->autorisationsDeSociete());
    }

    private function suspendre(Magasin $magasin): void
    {
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->assertSame($magasin, app(TenantContext::class)->magasin());
    }

    /**
     * Le magasin actif (voir TenantContext) est bien celui dont le statut
     * change : les droits vérifiés ensuite lisent son nouveau statut.
     */
    private function fermer(Magasin $magasin): void
    {
        $magasin->forceFill(['statut' => StatutMagasin::Ferme])->save();
        $this->assertSame($magasin, app(TenantContext::class)->magasin());
    }
}
