<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
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

        $proprietaire->refresh();

        $this->assertSame(StatutMagasin::Active, $magasin->statut);
        $this->assertTrue($proprietaire->appartientA($magasin));
        $this->assertTrue($proprietaire->estProprietaireDe($magasin));
        $this->assertSame($magasin->id, $proprietaire->current_magasin_id);
    }

    public function test_les_cinq_roles_par_defaut_sont_crees_et_scopes_a_ce_magasin(): void
    {
        $proprietaire = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $roles = Role::where('magasin_id', $magasin->id)->pluck('name')->sort()->values();

        $this->assertSame(['accountant', 'admin', 'employee', 'owner', 'viewer'], $roles->all());
    }

    public function test_le_proprietaire_a_la_permission_de_gerer_le_mandat_pdp_mais_pas_ladministrateur(): void
    {
        $proprietaire = User::factory()->create();
        $admin = User::factory()->create();

        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $contexte = app(TenantContext::class);
        $contexte->set($magasin);
        $admin->assignRole('admin');

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
