<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedMagasinRolesActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un vendeur ne doit jamais pouvoir clôturer la journée du magasin
     * (document fiscal d'e-reporting) sans que le propriétaire le lui
     * accorde explicitement -- seule la fermeture de sa propre caisse
     * (ClotureCaissePersonnelle) lui reste toujours ouverte, sans
     * permission dédiée.
     */
    public function test_un_vendeur_na_pas_la_permission_de_cloturer_la_journee_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
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
}
