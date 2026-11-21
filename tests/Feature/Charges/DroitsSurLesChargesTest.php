<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\GererLesTypesDeChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\EcritureDUneCharge;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Services\TypesDeCharge;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Qui voit, ajoute et valide les charges de la société (décisions de
 * l'éditeur du 14/11/2026, lot CHARGES ; voir DroitsSurLesCharges) : le
 * propriétaire, qui ne valide que sans comptable ; l'administrateur et le
 * responsable à qui il coche « Charges : ajouter », sans jamais valider,
 * dans leur portée ; le cabinet lié (son propriétaire et le collaborateur
 * confié) et l'expert-comptable déclaré, à qui le client accorde « Charges :
 * voir, ajouter, saisir et valider », par défaut ; jamais un vendeur, ni un
 * cabinet d'expertise comptable pour ses propres charges.
 */
class DroitsSurLesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $paris;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->paris = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Paris']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        app(TenantContext::class)->clear();
    }

    public function test_le_proprietaire_sans_comptable_voit_tout_ajoute_et_valide_lui_meme(): void
    {
        $acces = app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe);

        $this->assertNotNull($acces);
        $this->assertTrue($acces->chargesDeLaSociete);
        $this->assertNull($acces->magasins);
        $this->assertSame(ValideePar::Proprietaire, $acces->valideComme);

        $charge = $this->deposer($this->proprietaire, $this->societe);
        $this->assertSame(EtatDeLaCharge::AValider, $charge->etat);
        $this->assertSame(OrigineDeLaCharge::Client, $charge->origine);

        $validee = app(ValiderUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid);
        $this->assertSame(EtatDeLaCharge::Validee, $validee->etat);
        $this->assertSame(ValideePar::Proprietaire, $validee->validee_par);
        $this->assertSame('Validée par le propriétaire', $validee->validee_par->getLabel());
    }

    public function test_le_proprietaire_dont_le_cabinet_est_lie_ne_valide_pas(): void
    {
        $this->collaborateurDuCabinetLie($this->lyon);
        app(TenantContext::class)->clear();
        $charge = $this->deposer($this->proprietaire, $this->societe);

        $this->assertNull(app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe)?->valideComme);
        $this->assertThrows(fn () => app(ValiderUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid), AuthorizationException::class, ValiderUneChargeAction::REFUS_DROIT);
        $this->assertSame(EtatDeLaCharge::AValider, $charge->fresh()?->etat);
    }

    public function test_le_proprietaire_valide_lui_meme_quand_son_cabinet_n_a_plus_les_charges(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->firstOrFail();
        app(TenantContext::class)->clear();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien, PermissionRegistry::casesDuCabinet(array_values(array_diff(PermissionRegistry::parDefautDuCabinet($this->societe->type_activite), [PermissionRegistry::CHARGES_VALIDER]))));
        app(TenantContext::class)->clear();

        $this->assertNull(app(DroitsSurLesCharges::class)->pour($collaborateur->fresh() ?? $collaborateur, $this->societe));
        $this->assertSame(ValideePar::Proprietaire, app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe)?->valideComme);
        $this->assertThrows(fn () => $this->deposer($collaborateur, $this->societe), AuthorizationException::class, GestesSurLesCharges::REFUS);
    }

    public function test_un_administrateur_coche_voit_la_societe_et_ses_magasins_coches_sans_jamais_valider(): void
    {
        $administrateur = $this->administrateur($this->lyon, [CleDuModele::de($this->lyon)], autorisationsMagasin: [PermissionRegistry::CHARGES_AJOUTER]);
        app(TenantContext::class)->clear();
        $deLaSociete = $this->deposer($this->proprietaire, $this->societe);
        $deLyon = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['magasin' => $this->lyon->uuid, 'numero_piece' => 'L-1']));
        $deParis = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['magasin' => $this->paris->uuid, 'numero_piece' => 'P-1']));

        $acces = app(DroitsSurLesCharges::class)->pour($administrateur, $this->societe);
        $this->assertNotNull($acces);
        $this->assertFalse($acces->valide());
        $this->assertEqualsCanonicalizing([$deLaSociete->getKey(), $deLyon->getKey()], app(ChargesDeLaSociete::class)->visibles($acces)->pluck('id')->all());
        $this->assertFalse($acces->voit($deParis));

        $ajoutee = $this->deposer($administrateur, $this->societe, $this->saisie(champs: ['magasin' => $this->lyon->uuid, 'numero_piece' => 'L-2']));
        $this->assertSame(EtatDeLaCharge::AValider, $ajoutee->etat);
        $this->assertThrows(fn () => $this->deposer($administrateur, $this->societe, $this->saisie(champs: ['magasin' => $this->paris->uuid, 'numero_piece' => 'P-2'])), InvalidArgumentException::class, EcritureDUneCharge::REFUS_MAGASIN);
        $this->assertThrows(fn () => app(ValiderUneChargeAction::class)->handle($administrateur, $this->societe, $ajoutee->uuid), AuthorizationException::class, ValiderUneChargeAction::REFUS_DROIT);
    }

    public function test_un_administrateur_sans_la_case_n_a_aucun_acces(): void
    {
        $administrateur = $this->administrateur($this->lyon);
        app(TenantContext::class)->clear();

        $this->assertNotContains(PermissionRegistry::CHARGES_AJOUTER, PermissionRegistry::modeleDeLAdministrateur($this->societe->type_activite));
        $this->assertNull(app(DroitsSurLesCharges::class)->pour($administrateur, $this->societe));
        $this->assertThrows(fn () => $this->deposer($administrateur, $this->societe), AuthorizationException::class, GestesSurLesCharges::REFUS);
    }

    public function test_un_responsable_coche_ne_voit_que_les_charges_de_son_magasin(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]);
        app(TenantContext::class)->clear();
        $this->deposer($this->proprietaire, $this->societe);
        $deParis = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['magasin' => $this->paris->uuid, 'numero_piece' => 'P-1']));

        $ajoutee = $this->deposer($responsable, $this->societe, $this->saisie(champs: ['numero_piece' => 'L-1']));
        $this->assertSame($this->lyon->getKey(), $ajoutee->magasin_id);

        $acces = app(DroitsSurLesCharges::class)->pour($responsable, $this->societe);
        $this->assertNotNull($acces);
        $this->assertFalse($acces->chargesDeLaSociete);
        $this->assertSame([$ajoutee->getKey()], app(ChargesDeLaSociete::class)->visibles($acces)->pluck('id')->all());
        $this->assertNull(app(ChargesDeLaSociete::class)->trouver($acces, $deParis->uuid));
        $this->assertThrows(fn () => $this->deposer($responsable, $this->societe, $this->saisie(champs: ['magasin' => $this->paris->uuid, 'numero_piece' => 'P-2'])), InvalidArgumentException::class, EcritureDUneCharge::REFUS_MAGASIN);
        $this->assertThrows(fn () => app(ValiderUneChargeAction::class)->handle($responsable, $this->societe, $ajoutee->uuid), AuthorizationException::class, ValiderUneChargeAction::REFUS_DROIT);
    }

    public function test_un_responsable_sans_la_case_n_a_aucun_acces(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable);
        app(TenantContext::class)->clear();

        $this->assertNotContains(PermissionRegistry::CHARGES_AJOUTER, $responsable->autorisationsDans($this->lyon));
        $this->assertNull(app(DroitsSurLesCharges::class)->pour($responsable, $this->societe));
    }

    public function test_un_vendeur_n_a_jamais_les_charges(): void
    {
        $vendeur = $this->membre($this->lyon, RoleMagasin::Vendeur);
        app(TenantContext::class)->clear();

        $this->assertNull(app(DroitsSurLesCharges::class)->pour($vendeur, $this->societe));
        $this->assertThrows(fn () => $this->membre($this->lyon, RoleMagasin::Vendeur, [PermissionRegistry::CHARGES_AJOUTER]), LogicException::class, AffectationsDesMembres::MESSAGE_REFUSEES_AUX_VENDEURS);
        $this->assertThrows(fn () => $this->membre($this->lyon, RoleMagasin::Observateur, [PermissionRegistry::CHARGES_AJOUTER]), LogicException::class, AffectationsDesMembres::MESSAGE_REFUSEES_AUX_VENDEURS);
    }

    public function test_la_validation_des_charges_ne_se_coche_jamais_a_un_compte_de_la_societe(): void
    {
        $this->assertNotContains(PermissionRegistry::CHARGES_VALIDER, PermissionRegistry::pourLeType($this->societe->type_activite));
        $this->assertNotContains(PermissionRegistry::CHARGES_VALIDER, PermissionRegistry::duProprietaire($this->societe->type_activite));
        $this->assertContains(PermissionRegistry::CHARGES_VALIDER, PermissionRegistry::parDefautDuCabinet($this->societe->type_activite));
        $this->assertThrows(fn () => $this->administrateur($this->lyon, autorisationsMagasin: [PermissionRegistry::CHARGES_VALIDER]), InvalidArgumentException::class);

        $administrateur = $this->administrateur($this->lyon, autorisationsMagasin: [PermissionRegistry::CHARGES_AJOUTER]);
        $affectation = DB::table('affectations')->where('user_id', $administrateur->getKey())->value('id');
        $this->assertThrows(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation, 'autorisation' => PermissionRegistry::CHARGES_VALIDER]), QueryException::class, 'la validation des charges ne s\'accorde qu\'au cabinet lié');
    }

    public function test_le_collaborateur_confie_ajoute_et_valide_et_celui_qui_ne_l_est_pas_rien(): void
    {
        $confie = $this->collaborateurDuCabinetLie($this->lyon);
        $cabinet = $confie->societe()->firstOrFail();
        $nonConfie = $this->collaborateur($cabinet, 'Inès Moreau');
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);

        $acces = app(DroitsSurLesCharges::class)->pour($confie, $this->societe);
        $this->assertNotNull($acces);
        $this->assertSame(ValideePar::Cabinet, $acces->valideComme);
        $this->assertTrue($acces->estComptable());
        $this->assertNotNull(app(DroitsSurLesCharges::class)->pour($proprietaireDuCabinet, $this->societe));
        $this->assertNull(app(DroitsSurLesCharges::class)->pour($nonConfie, $this->societe));

        $ajoutee = $this->deposer($confie, $this->societe);
        $this->assertSame(EtatDeLaCharge::Validee, $ajoutee->etat);
        $this->assertSame(OrigineDeLaCharge::Comptable, $ajoutee->origine);
        $this->assertSame(ValideePar::Cabinet, $ajoutee->validee_par);
        $this->assertStringContainsString($cabinet->getFilamentName(), (string) $ajoutee->validee_par_nom);
        $this->assertSame($this->societe->getKey(), $ajoutee->societe_id);

        $this->assertThrows(fn () => $this->deposer($confie, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2']), avecLaPiece: false), InvalidArgumentException::class, DeposerUneChargeAction::REFUS_COMPTABLE_SANS_PIECE);
        $this->assertThrows(fn () => $this->deposer($nonConfie, $this->societe), AuthorizationException::class, GestesSurLesCharges::REFUS);
    }

    public function test_l_expert_comptable_declare_ajoute_et_valide(): void
    {
        $expert = $this->expertComptable($this->lyon);
        $charge = $this->deposer($this->proprietaire, $this->societe);

        $acces = app(DroitsSurLesCharges::class)->pour($expert, $this->societe);
        $this->assertNotNull($acces);
        $this->assertSame(ValideePar::ExpertComptable, $acces->valideComme);
        $this->assertNull(app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe)?->valideComme);

        $validee = app(ValiderUneChargeAction::class)->handle($expert, $this->societe, $charge->uuid);
        $this->assertSame(ValideePar::ExpertComptable, $validee->validee_par);
        $this->assertSame(1, Charge::query()->withoutGlobalScopes()->where('etat', EtatDeLaCharge::Validee->value)->count());
    }

    public function test_un_cabinet_d_expertise_comptable_n_a_pas_de_charges(): void
    {
        $cabinet = $this->ouvrirUnCabinet();

        $this->assertNull(app(DroitsSurLesCharges::class)->pour($this->proprietaireDuCabinet($cabinet), $cabinet));
        $this->assertNull(app(DroitsSurLesCharges::class)->pour($this->proprietaire, $cabinet));
    }

    /**
     * Les types de la société et ses types habituels sont des réglages de
     * la société (décision de Claude, D76) : le cabinet lié et
     * l'expert-comptable déclaré n'y touchent jamais ; le propriétaire et
     * l'administrateur coché les gèrent.
     */
    public function test_ni_le_cabinet_ni_l_expert_comptable_ne_touchent_aux_types_de_la_societe(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $type = app(GererLesTypesDeChargeAction::class)->ajouter($this->proprietaire, $this->societe, 'Ménage', NatureDeCharge::Services);
        app(TenantContext::class)->clear();

        $this->assertFalse(app(DroitsSurLesCharges::class)->pour($collaborateur, $this->societe)?->gereLesTypes);
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->choisirLesHabituels($collaborateur, $this->societe, [TypeDeCharge::Gaz]), AuthorizationException::class, GererLesTypesDeChargeAction::REFUS_DROIT);
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->ajouter($collaborateur, $this->societe, 'Jardinage', NatureDeCharge::Services), AuthorizationException::class, GererLesTypesDeChargeAction::REFUS_DROIT);
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->archiver($collaborateur, $this->societe, (string) $type->uuid, true), AuthorizationException::class, GererLesTypesDeChargeAction::REFUS_DROIT);
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->supprimer($collaborateur, $this->societe, (string) $type->uuid), AuthorizationException::class, GererLesTypesDeChargeAction::REFUS_DROIT);
        $this->assertSame([TypeDeCharge::Loyer, TypeDeCharge::Electricite], app(TypesDeCharge::class)->habituels($this->societe));

        $autre = $this->ouvrirUneSociete(['nom' => 'Déclarante']);
        $expert = $this->expertComptable($autre);
        app(TenantContext::class)->clear();
        $this->assertFalse(app(DroitsSurLesCharges::class)->pour($expert, $autre->societe)?->gereLesTypes);
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->choisirLesHabituels($expert, $autre->societe, [TypeDeCharge::Gaz]), AuthorizationException::class, GererLesTypesDeChargeAction::REFUS_DROIT);

        $administrateur = $this->administrateur($this->lyon, [CleDuModele::de($this->lyon)], autorisationsMagasin: [PermissionRegistry::CHARGES_AJOUTER]);
        app(TenantContext::class)->clear();
        app(GererLesTypesDeChargeAction::class)->choisirLesHabituels($administrateur, $this->societe, [TypeDeCharge::Gaz]);
        $this->assertSame([TypeDeCharge::Gaz], app(TypesDeCharge::class)->habituels($this->societe));
    }

    /**
     * Un type de la société qu'aucune charge ne porte se supprime, inscrit
     * au journal d'audit ; un type porté par une charge ne se supprime pas :
     * il s'archive.
     */
    public function test_un_type_de_la_societe_jamais_utilise_se_supprime_et_un_type_utilise_s_archive(): void
    {
        $erreur = app(GererLesTypesDeChargeAction::class)->ajouter($this->proprietaire, $this->societe, 'Ménnage', NatureDeCharge::Services);
        $utilise = app(GererLesTypesDeChargeAction::class)->ajouter($this->proprietaire, $this->societe, 'Ménage', NatureDeCharge::Services);
        app(TenantContext::class)->clear();
        $this->deposer($this->proprietaire, $this->societe, $this->saisie('societe:'.$utilise->uuid, champs: ['fournisseur' => 'Nettoyage Pro', 'numero_piece' => 'N-1']));

        app(GererLesTypesDeChargeAction::class)->supprimer($this->proprietaire, $this->societe, (string) $erreur->uuid);
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(GererLesTypesDeChargeAction::class)->supprimer($this->proprietaire, $this->societe, (string) $utilise->uuid), LogicException::class, GererLesTypesDeChargeAction::REFUS_UTILISE);

        $this->assertSame(['Ménage'], app(TypesDeCharge::class)->tousDeLaSociete($this->societe)->pluck('nom')->all());
        [$entree] = $this->entreesDAudit($this->societe, ActionAuditee::TypeDeChargeSupprime);
        $this->assertSame('Ménnage', $entree->anciennes_valeurs['type'] ?? null);
    }
}
