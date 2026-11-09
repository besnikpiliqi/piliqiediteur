<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Les bords des droits : un compte suspendu, propriétaire compris, ne
 * voit plus rien de son équipe ; l'identifiant d'un compte ne se montre
 * jamais hors de sa société ; on ne nomme, ne gère, ne réintègre et
 * n'annule une nomination que dans les limites de sa société et de son
 * rôle ; une autorisation de société ne vaut que dans sa propre société,
 * pour un compte d'une société comme pour un compte d'un cabinet
 * d'expertise comptable ; le contexte du magasin et de la société actifs
 * ne mélange jamais deux sociétés.
 */
class LimitesDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_compte_suspendu_ne_voit_plus_ni_les_comptes_de_l_equipe_ni_son_propre_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::ACCES_REDONNER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $portee = app(PorteeDesDroits::class);

        $this->assertTrue($portee->comptesVisibles($administrateur)->whereKey($vendeur->getKey())->exists());
        $this->assertTrue($portee->voitLIdentifiant($vendeur, $vendeur));

        foreach ([$administrateur, $vendeur] as $compte) {
            app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $compte);
            $compte->refresh();
        }

        $this->assertSame(0, $portee->comptesVisibles($administrateur)->count());
        $this->assertFalse($portee->voitLIdentifiant($vendeur, $vendeur));

        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $this->assertSame(0, $portee->comptesVisibles($proprietaire)->count());
    }

    public function test_le_proprietaire_ne_voit_jamais_l_identifiant_d_un_compte_d_une_autre_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $autreMagasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeurDAilleurs = $this->membre($autreMagasin, RoleMagasin::Vendeur);
        $portee = app(PorteeDesDroits::class);

        $this->assertTrue($portee->voitLIdentifiant($proprietaire, $this->membre($magasin, RoleMagasin::Vendeur)));
        $this->assertFalse($portee->voitLIdentifiant($proprietaire, $vendeurDAilleurs));
        $this->assertFalse($portee->comptesVisibles($proprietaire)->whereKey($vendeurDAilleurs->getKey())->exists());
    }

    /**
     * On ne nomme un responsable que dans un magasin de sa société ; le
     * propriétaire gère le compte entier d'un membre, responsable compris,
     * jamais celui d'un administrateur.
     */
    public function test_on_ne_nomme_et_ne_gere_que_les_membres_de_sa_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $autreMagasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $portee = app(PorteeDesDroits::class);

        $this->assertSame([true, false], [$portee->peutNommerUnResponsable($proprietaire, $magasin), $portee->peutNommerUnResponsable($proprietaire, $autreMagasin)]);
        $this->assertSame([true, true, false], [
            $portee->gereLeCompte($proprietaire, $this->membre($magasin, RoleMagasin::Vendeur)),
            $portee->gereLeCompte($proprietaire, $this->responsable($magasin)),
            $portee->gereLeCompte($proprietaire, $this->administrateur($magasin)),
        ]);
    }

    /**
     * Seul un compte retiré se réintègre : un compte actif, non.
     */
    public function test_seul_un_compte_retire_se_reintegre(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $portee = app(PorteeDesDroits::class);

        $this->assertFalse($portee->peutReintegrer($proprietaire, $vendeur, RoleMagasin::Vendeur));

        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $vendeur);

        $this->assertTrue($portee->peutReintegrer($proprietaire, $vendeur->fresh(), RoleMagasin::Vendeur));
    }

    /**
     * Une nomination ne s'annule que si un administrateur l'a faite : le
     * responsable que le propriétaire a nommé lui-même le reste.
     */
    public function test_une_nomination_ne_s_annule_que_si_un_administrateur_l_a_faite(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $nomme = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ModifierMembreAction::class)->handle($this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]), $nomme, RoleMagasin::Responsable);
        $nommeParLeProprietaire = $this->responsable($magasin);
        $affectationDe = fn (User $compte): Affectation => Affectation::query()->where('user_id', $compte->id)->sole();
        $portee = app(PorteeDesDroits::class);

        $this->assertSame([true, false], [
            $portee->peutAnnulerLaNomination($proprietaire, $affectationDe($nomme)),
            $portee->peutAnnulerLaNomination($proprietaire, $affectationDe($nommeParLeProprietaire)),
        ]);
    }

    public function test_une_autorisation_de_societe_est_refusee_dans_le_contexte_d_une_autre_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $autreSociete = $this->ouvrirUneSociete()->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $contexte = app(TenantContext::class);
        $contexte->clear();

        $contexte->poserSociete($magasin->societe);
        $this->assertTrue($proprietaire->can(AutorisationsSociete::MAGASINS_GERER));

        $contexte->poserSociete($autreSociete);
        foreach (AutorisationsSociete::toutes() as $autorisation) {
            $this->assertFalse($proprietaire->can($autorisation), $autorisation);
        }
    }

    public function test_le_proprietaire_d_un_cabinet_n_a_ses_autorisations_de_societe_que_dans_l_espace_de_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $client = $this->ouvrirUneSociete();
        $this->lierLeCabinet($cabinet, $client);
        $contexte = app(TenantContext::class);
        $contexte->clear();

        $contexte->poserSociete($cabinet);
        $this->assertTrue($proprietaire->can(AutorisationsSociete::PARAMETRES_GERER));

        $contexte->poserSociete($client->societe);
        $this->assertFalse($proprietaire->can(AutorisationsSociete::PARAMETRES_GERER));

        $contexte->clear();
        $contexte->set($client);
        $this->assertFalse($proprietaire->can(AutorisationsSociete::PARAMETRES_GERER));

        $contexte->clear();
        $this->assertFalse($proprietaire->can(AutorisationsSociete::PARAMETRES_GERER));
    }

    public function test_une_societe_ne_se_pose_pas_tant_que_le_magasin_d_une_autre_societe_est_actif(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $autreSociete = $this->ouvrirUneSociete()->societe;
        $contexte = app(TenantContext::class);
        $contexte->clear();
        $contexte->set($magasin);

        $this->assertThrows(fn () => $contexte->poserSociete($autreSociete), LogicException::class, 'Une société ne se pose pas tant que le magasin d\'une autre société est actif.');
        $this->assertTrue($contexte->societe()->is($magasin->societe));

        $contexte->poserSociete($magasin->societe);
        $this->assertTrue($contexte->magasin()->is($magasin));
    }

    public function test_sans_magasin_actif_le_contexte_ne_rend_aucun_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $contexte = app(TenantContext::class);
        $contexte->clear();
        $contexte->poserSociete($magasin->societe);

        $this->assertFalse($contexte->has());
        $this->assertThrows(fn () => $contexte->magasin(), TenantContextMissingException::class);
    }

    /**
     * Avant la validation de sa société, un magasin réel ne fait aucune
     * opération commerciale réelle : son responsable, qui y a tous les
     * droits, n'y a aucune des autorisations qu'elles demandent, et garde
     * les autres ; le cabinet que la société a désigné n'en a aucune non
     * plus, et garde ses consultations.
     */
    public function test_un_magasin_qui_ne_peut_pas_vendre_refuse_les_autorisations_commerciales_meme_a_son_responsable(): void
    {
        $magasin = $this->creerLeMagasinDuSiege($this->inscrireUneSociete());
        $responsable = $this->responsable($magasin);
        $contexte = app(TenantContext::class);
        $contexte->clear();
        $contexte->set($magasin);

        $this->assertFalse($magasin->peutVendre());
        foreach (PermissionRegistry::reserveesAuxOperationsCommerciales() as $autorisation) {
            $this->assertFalse($responsable->can($autorisation), $autorisation);
        }
        $this->assertTrue($responsable->can(PermissionRegistry::CLIENT_CREATE));

        $cabinet = $this->ouvrirUnCabinet();
        $this->lierLeCabinet($cabinet, $magasin);
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        $contexte->clear();
        $contexte->set($magasin);

        foreach (PermissionRegistry::reserveesAuxOperationsCommerciales() as $autorisation) {
            $this->assertFalse($proprietaireDuCabinet->can($autorisation), $autorisation);
        }
        $this->assertTrue($proprietaireDuCabinet->can(PermissionRegistry::CLIENT_VIEW));
    }

    public function test_du_cote_du_client_seul_son_proprietaire_voit_le_lien_avec_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete();
        $lien = $this->lierLeCabinet($cabinet, $client);
        $autreMagasin = $this->ouvrirUneSociete();

        $administrateur = $this->administrateur($client, null, AutorisationsSociete::toutes());
        $responsable = $this->responsable($client);
        $voit = function (User $compte) use ($lien): array {
            app(TenantContext::class)->clear();
            app(TenantContext::class)->poserSociete($compte->societe);

            return [$compte->can('viewAny', LienCabinet::class), $compte->can('view', $lien)];
        };

        $this->assertSame([true, true], $voit($this->proprietaireDe($client)));
        $this->assertSame([false, false], $voit($administrateur));
        $this->assertSame([false, false], $voit($responsable));
        $this->assertSame([true, false], $voit($this->proprietaireDe($autreMagasin)));
    }
}
