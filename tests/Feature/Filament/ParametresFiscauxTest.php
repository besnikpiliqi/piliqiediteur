<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\ParametresFiscaux;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ParametresFiscauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_configure_le_regime_de_tva_du_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelNormalTrimestriel->value,
                'option_debits_tva' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $magasin->regime_tva);
        $this->assertTrue($magasin->option_debits_tva);
    }

    public function test_un_vendeur_sans_la_permission_ne_peut_pas_acceder_a_la_page(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo(PermissionRegistry::FACTURE_VIEW);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ParametresFiscaux::canAccess());
    }
}
