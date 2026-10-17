<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les rôles ne sont que des modèles : aucun rôle n'est enregistré en base.
 * Le propriétaire de la société a toutes les autorisations sans case, un
 * responsable toutes celles de son magasin sans case (voir
 * DroitsDuResponsableTest) ; un vendeur ou un observateur reçoit d'office
 * le modèle de son rôle (voir RoleMagasin::permissionsParDefaut()), que
 * l'on coche ou décoche ensuite ; un nouvel administrateur, le modèle de
 * PermissionRegistry::modeleDeLAdministrateur(). Il n'existe plus de rôle
 * « comptable » de magasin : l'expert-comptable de la société le remplace.
 */
class AutorisationsDesRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_aucun_role_n_existe_et_le_proprietaire_est_implicite(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertSame(0, Affectation::query()->where('user_id', $proprietaire->id)->count());
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $proprietaire->autorisationsDans($magasin));
    }

    public function test_le_proprietaire_a_implicitement_toutes_les_autorisations(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        foreach (PermissionRegistry::toutes() as $permission) {
            $this->assertTrue($proprietaire->can($permission), $permission);
        }
    }

    /**
     * Le modèle d'un nouvel administrateur coche toutes les autorisations
     * de magasin, sauf la vente sans stock et les paramètres du magasin,
     * que le propriétaire coche pour qui il veut.
     */
    public function test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $administrateur = $this->administrateur($magasin);

        $this->assertSame([PermissionRegistry::STOCK_VENDRE_SANS_STOCK, PermissionRegistry::PARAMETRES_GERER], array_values(array_diff(PermissionRegistry::toutes(), $administrateur->autorisationsDans($magasin))));
        $this->assertEqualsCanonicalizing(PermissionRegistry::modeleDeLAdministrateur(), $administrateur->autorisationsDans($magasin));
    }

    public function test_le_modele_de_l_administrateur_n_a_pas_les_parametres_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $administrateur = $this->administrateur($magasin);

        $this->assertTrue($proprietaire->can(PermissionRegistry::PARAMETRES_GERER));
        $this->assertFalse($administrateur->can(PermissionRegistry::PARAMETRES_GERER));
        $this->assertNotContains(PermissionRegistry::PARAMETRES_GERER, PermissionRegistry::modeleDeLAdministrateur());
    }

    public function test_il_n_existe_plus_de_role_comptable_de_magasin(): void
    {
        $this->assertSame([RoleMagasin::Responsable, RoleMagasin::Vendeur, RoleMagasin::Observateur], RoleMagasin::cases());
        $this->assertNull(RoleMagasin::tryFrom('comptable'));
        $this->assertNull(RoleMagasin::tryFrom('administrateur'));
        $this->assertNull(RoleMagasin::tryFrom('proprietaire'));
    }

    /**
     * Un vendeur ne clôture pas la journée du magasin (document fiscal
     * d'e-reporting) sans qu'on la lui coche ; il ferme toujours sa propre
     * caisse, sans autorisation dédiée.
     */
    public function test_un_vendeur_na_pas_la_permission_de_cloturer_la_journee_par_defaut(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Vendeur', 'email' => 'paul-vendeur@example.test']);

        $this->actingAs($vendeur);
        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_GERER));
        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_VIEW));
    }

    public function test_le_proprietaire_a_toujours_la_permission_de_cloturer_la_journee(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        $this->assertTrue($proprietaire->can(PermissionRegistry::CLOTURE_CAISSE_GERER));
    }

    /**
     * La caisse sécurisée (registre fiscal et journal des événements) se
     * consulte d'office par le propriétaire, un responsable, un
     * administrateur et l'expert-comptable, qui la présentent à
     * l'administration ; ni par le vendeur ni par l'observateur.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function consultationDeLaCaisseSecurisee(): array
    {
        return [
            'responsable' => ['responsable', true],
            'administrateur' => ['administrateur', true],
            'expert-comptable' => ['expert-comptable', true],
            'vendeur' => ['vendeur', false],
            'observateur' => ['observateur', false],
        ];
    }

    #[DataProvider('consultationDeLaCaisseSecurisee')]
    public function test_la_caisse_securisee_se_consulte_selon_le_role(string $role, bool $consulte): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $compte = match ($role) {
            'responsable' => $this->responsable($magasin),
            'administrateur' => $this->administrateur($magasin),
            'expert-comptable' => $this->expertComptable($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'observateur' => $this->membre($magasin, RoleMagasin::Observateur),
        };

        $this->assertSame($consulte, $compte->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
        $this->assertTrue($proprietaire->fresh()->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
    }

    /**
     * Ce que chaque modèle de rôle coche d'office : le vendeur vend,
     * envoie ses documents et encaisse, sans voir les factures de
     * l'équipe, créer d'avoir ni clôturer la journée ; l'observateur
     * consulte sans rien écrire.
     *
     * @return array<string, array{RoleMagasin, string, bool}>
     */
    public static function autorisationsCocheesDOffice(): array
    {
        return [
            'le vendeur envoie ses factures' => [RoleMagasin::Vendeur, PermissionRegistry::FACTURE_ENVOYER, true],
            'le vendeur encaisse' => [RoleMagasin::Vendeur, PermissionRegistry::PAIEMENT_CREER, true],
            'le vendeur envoie ses devis' => [RoleMagasin::Vendeur, PermissionRegistry::DEVIS_ENVOYER, true],
            'le vendeur ne voit pas les factures de l\'équipe' => [RoleMagasin::Vendeur, PermissionRegistry::FACTURE_VIEW_TOUTES, false],
            'le vendeur ne crée pas d\'avoir' => [RoleMagasin::Vendeur, PermissionRegistry::AVOIR_CREER, false],
            'le vendeur ne clôture pas la journée' => [RoleMagasin::Vendeur, PermissionRegistry::CLOTURE_CAISSE_GERER, false],
            'l\'observateur voit les factures' => [RoleMagasin::Observateur, PermissionRegistry::FACTURE_VIEW, true],
            'l\'observateur n\'émet pas de facture' => [RoleMagasin::Observateur, PermissionRegistry::FACTURE_EMETTRE, false],
            'l\'observateur n\'encaisse pas' => [RoleMagasin::Observateur, PermissionRegistry::PAIEMENT_CREER, false],
        ];
    }

    #[DataProvider('autorisationsCocheesDOffice')]
    public function test_un_membre_recoit_d_office_le_modele_de_son_role(RoleMagasin $role, string $autorisation, bool $accordee): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $membre = $this->membre($magasin, $role);

        $this->assertSame($accordee, $membre->can($autorisation));
        $this->assertEqualsCanonicalizing($role->permissionsParDefaut(), Affectation::query()->where('user_id', $membre->id)->sole()->autorisations());
    }
}
