<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\SeedMagasinRolesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le seul rôle Spatie d'un magasin est celui du propriétaire, qui porte
 * toutes les autorisations ; un membre reçoit d'office le modèle de son
 * rôle (voir RoleMagasin::permissionsParDefaut()), que le propriétaire
 * coche ou décoche ensuite.
 */
class SeedMagasinRolesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seul_le_role_du_proprietaire_existe_et_il_porte_toutes_les_autorisations(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        app(SeedMagasinRolesAction::class)->handle($magasin);

        $role = Role::query()->where('magasin_id', $magasin->id)->sole();
        $autorisations = $role->permissions->pluck('name')->sort()->values()->all();
        $toutes = PermissionRegistry::toutes();
        sort($toutes);
        $this->assertSame('owner', $role->name);
        $this->assertSame($toutes, $autorisations);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $membre = $this->membre($magasin, $role);

        $this->assertSame($accordee, $membre->can($autorisation));
    }

    /**
     * L'administrateur reçoit d'office toutes les autorisations, sauf les
     * paramètres du magasin (identité et TVA).
     */
    public function test_l_administrateur_recoit_d_office_tout_sauf_les_parametres_du_magasin(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);

        $manquantes = array_values(array_diff(PermissionRegistry::toutes(), $administrateur->getDirectPermissions()->pluck('name')->all()));
        $this->assertSame(['parametres.gerer'], $manquantes);
    }
}
