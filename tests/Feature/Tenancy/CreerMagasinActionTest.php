<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreerMagasinActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_magasin_rattache_le_proprietaire_avec_tous_les_droits(): void
    {
        $proprietaire = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle([
            'nom' => 'Ma Boutique',
            'email' => 'contact@maboutique.fr',
        ], $proprietaire);

        $this->assertSame(StatutMagasin::Active, $magasin->statut);
        $this->assertTrue($proprietaire->appartientA($magasin));
        $this->assertSame([$proprietaire->id], $magasin->proprietaires()->get()->modelKeys());
    }

    /**
     * Pour une vente au comptoir (particulier anonyme), le vendeur doit
     * pouvoir encaisser sans saisir de coordonnées client : un client
     * générique réutilisable existe donc dès la création du magasin.
     */
    public function test_un_client_comptoir_particulier_est_cree_avec_le_magasin(): void
    {
        $proprietaire = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        app(TenantContext::class)->set($magasin);

        $comptoir = Client::query()->where('nom', 'Client comptoir')->first();
        $this->assertNotNull($comptoir);
        $this->assertSame(TypeClient::Particulier, $comptoir->type_client);
        $this->assertTrue($comptoir->estComptoir());
    }

    /**
     * Le rôle du propriétaire est le seul rôle Spatie du magasin : les
     * membres de l'équipe reçoivent leurs autorisations directement.
     */
    public function test_seul_le_role_du_proprietaire_est_cree_et_scope_a_ce_magasin(): void
    {
        $proprietaire = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $roles = Role::where('magasin_id', $magasin->id)->pluck('name')->sort()->values();

        $this->assertSame(['owner'], $roles->all());
    }

    public function test_le_proprietaire_a_la_permission_de_gerer_le_mandat_pdp_mais_pas_ladministrateur(): void
    {
        $proprietaire = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $admin = $this->membre($magasin, RoleMagasin::Administrateur);

        $contexte = app(TenantContext::class);
        $this->actingAs($proprietaire);
        $contexte->set($magasin);
        $this->assertTrue($proprietaire->can(PermissionRegistry::MANDAT_PDP_GERER));

        $this->actingAs($admin);
        $contexte->set($magasin);
        $this->assertFalse($admin->can(PermissionRegistry::MANDAT_PDP_GERER));
    }

    public function test_deux_magasins_ont_chacun_leurs_propres_roles_independants(): void
    {
        $proprietaireA = User::factory()->create();
        $proprietaireB = User::factory()->create();

        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertNotSame(
            Role::where('magasin_id', $magasinA->id)->where('name', 'owner')->first()->id,
            Role::where('magasin_id', $magasinB->id)->where('name', 'owner')->first()->id,
        );

        $this->actingAs($proprietaireA);
        app(TenantContext::class)->set($magasinB);
        $this->assertFalse($proprietaireA->can(PermissionRegistry::CLIENT_VIEW), 'Le propriétaire de A ne doit rien pouvoir faire dans B.');
    }
}
