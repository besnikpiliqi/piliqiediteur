<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\MembreEquipeResource\Pages\CreateMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\EditMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\ListMembreEquipes;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MembreEquipeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_vendeur_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)
            ->fillForm([
                'name' => 'Paul Vendeur',
                'email' => 'paul@example.test',
                'password' => 'mot-de-passe-sur',
                'role' => RoleMagasin::Vendeur->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendeur = User::where('email', 'paul@example.test')->firstOrFail();
        $this->assertNotNull($vendeur->email_verified_at);
        $this->assertTrue($vendeur->appartientA($magasin));
    }

    public function test_un_vendeur_sans_permission_ne_peut_pas_acceder_a_la_gestion_dequipe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Simple', 'email' => 'simple@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');

        $vendeur = User::where('email', 'simple@example.test')->firstOrFail();

        $this->actingAs($vendeur);
        $this->assertFalse($vendeur->can(PermissionRegistry::EQUIPE_VIEW));

        Filament::setTenant($magasin);
        $this->get("/app/{$magasin->uuid}/membre-equipes")->assertForbidden();
    }

    public function test_le_proprietaire_napparait_pas_comme_modifiable_dans_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $membreProprietaire = $magasin->membres()->where('user_id', $proprietaire->id)->firstOrFail();

        Livewire::test(ListMembreEquipes::class)
            ->assertTableActionHidden('reinitialiser_mot_de_passe', $membreProprietaire)
            ->assertTableActionHidden('retirer', $membreProprietaire);
    }

    public function test_le_proprietaire_accorde_une_permission_supplementaire_a_un_vendeur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Spécial', 'email' => 'special@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');
        $vendeur = User::where('email', 'special@example.test')->firstOrFail();
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        app(TenantContext::class)->set($magasin);
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_VIEW), 'Un vendeur ne voit pas les avoirs par défaut.');

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->fillForm([
                'role' => RoleMagasin::Vendeur->value,
                'statut' => 'active',
                'permissions_supplementaires' => [PermissionRegistry::AVOIR_VIEW],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        app(TenantContext::class)->set($magasin);
        $vendeur->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertTrue($vendeur->can(PermissionRegistry::AVOIR_VIEW), 'La permission supplémentaire doit maintenant être accordée.');
        // Le rôle lui-même ne doit pas être affecté par cette permission
        // directe : un vendeur sans la permission dédiée reste incapable
        // de créer un avoir.
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_CREER));
    }

    public function test_un_administrateur_ne_peut_pas_modifier_les_permissions_supplementaires(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Admin Un', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Administrateur->value,
        ])->call('create');
        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Cible', 'email' => 'cible@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');
        $vendeur = User::where('email', 'cible@example.test')->firstOrFail();
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        $this->actingAs($admin);
        Filament::setTenant($magasin);

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->assertFormFieldIsHidden('permissions_supplementaires');

        app(TenantContext::class)->set($magasin);
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_VIEW));
    }
}
