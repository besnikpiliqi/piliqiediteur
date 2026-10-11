<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\SeedMagasinRolesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chaque autorisation de l'application existe une fois, et aucun rôle
 * Spatie : le propriétaire de la société a toutes les autorisations sans
 * case (voir ControleDesDroitsTest), et un membre reçoit d'office le
 * modèle de son rôle (voir RoleMagasin::permissionsParDefaut()), que le
 * propriétaire coche ou décoche ensuite.
 */
class SeedMagasinRolesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_autorisation_existe_une_fois_et_aucun_role_n_est_cree(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        app(SeedMagasinRolesAction::class)->handle();

        $toutes = PermissionRegistry::toutes();
        sort($toutes);
        $this->assertSame($toutes, DB::table('permissions')->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, Role::query()->count());
    }

    /**
     * Deux magasins qui se créent au même instant : l'autre création a
     * créé les autorisations après que celle-ci a lu le cache des
     * autorisations, qui les croit encore absentes. Elles ne sont pas
     * recréées (l'index unique (name, guard_name) les refuserait), et un
     * membre reçoit ensuite toutes celles de son rôle.
     */
    public function test_des_autorisations_creees_entre_temps_par_une_autre_inscription_sont_reprises(): void
    {
        app(PermissionRegistrar::class)->getPermissions();
        DB::table('permissions')->insert(array_map(static fn (string $permission): array => [
            'name' => $permission,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ], PermissionRegistry::toutes()));

        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $comptable = $this->membre($magasin, RoleMagasin::Comptable);

        $modele = RoleMagasin::Comptable->permissionsParDefaut();
        sort($modele);
        $this->assertSame(count(PermissionRegistry::toutes()), DB::table('permissions')->count());
        $this->assertSame($modele, $comptable->autorisationsCocheesDans($magasin));
    }

    /**
     * Un vendeur ne doit jamais pouvoir clôturer la journée du magasin
     * (document fiscal d'e-reporting) sans que le propriétaire le lui
     * coche explicitement -- seule la fermeture de sa propre caisse
     * (ClotureCaissePersonnelle) lui reste toujours ouverte, sans
     * autorisation dédiée.
     */
    public function test_un_vendeur_na_pas_la_permission_de_cloturer_la_journee_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul Vendeur',
            'email' => 'paul-vendeur@example.test',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->actingAs($vendeur);
        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_GERER));
        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_VIEW));
    }

    public function test_le_proprietaire_a_toujours_la_permission_de_cloturer_la_journee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        $this->assertTrue($proprietaire->can(PermissionRegistry::CLOTURE_CAISSE_GERER));
    }

    /**
     * La caisse sécurisée (registre fiscal et journal des événements) se
     * consulte par défaut par le propriétaire, l'administrateur et le
     * comptable, qui la présentent à l'administration ; ni le vendeur ni
     * l'observateur.
     *
     * @return array<string, array{0: RoleMagasin, 1: bool}>
     */
    public static function consultationDeLaCaisseSecurisee(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, true],
            'vendeur' => [RoleMagasin::Vendeur, false],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    #[DataProvider('consultationDeLaCaisseSecurisee')]
    public function test_la_caisse_securisee_se_consulte_selon_le_role(RoleMagasin $role, bool $consulte): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Camille',
            'email' => 'camille@example.test',
            'password' => 'mot-de-passe-sur',
        ], $role);

        $this->assertSame($consulte, $membre->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
        $this->assertTrue($proprietaire->fresh()->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
    }

    /**
     * Ce que chaque modèle de rôle coche d'office : le vendeur vend,
     * envoie ses documents et encaisse, sans voir les factures de
     * l'équipe, créer d'avoir ni clôturer la journée ; le comptable suit
     * toutes les factures et les impayés.
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
            'le comptable voit les factures de l\'équipe' => [RoleMagasin::Comptable, PermissionRegistry::FACTURE_VIEW_TOUTES, true],
            'le comptable envoie les factures' => [RoleMagasin::Comptable, PermissionRegistry::FACTURE_ENVOYER, true],
            'le comptable envoie les avoirs' => [RoleMagasin::Comptable, PermissionRegistry::AVOIR_ENVOYER, true],
        ];
    }

    #[DataProvider('autorisationsCocheesDOffice')]
    public function test_un_membre_recoit_d_office_le_modele_de_son_role(RoleMagasin $role, string $autorisation, bool $accordee): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $membre = $this->membre($magasin, $role);

        $this->assertSame($accordee, $membre->can($autorisation));
    }

    /**
     * L'administrateur reçoit d'office toutes les autorisations, sauf les
     * paramètres du magasin (échéance et mentions des factures).
     */
    public function test_l_administrateur_recoit_d_office_tout_sauf_les_parametres_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);

        $manquantes = array_values(array_diff(PermissionRegistry::toutes(), $administrateur->getDirectPermissions()->pluck('name')->all()));
        $this->assertSame(['parametres.gerer'], $manquantes);
    }
}
