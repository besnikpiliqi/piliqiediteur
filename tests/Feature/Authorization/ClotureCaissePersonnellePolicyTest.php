<?php

namespace Tests\Feature\Authorization;

use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le relevé « Fermer ma caisse » d'un vendeur n'est visible que par lui
 * et par qui a cloture_caisse.view_toutes (voir
 * ClotureCaissePersonnellePolicy::view()).
 */
class ClotureCaissePersonnellePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_ne_voit_que_ses_propres_releves(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->membre($magasin, 'paul@example.test', RoleMagasin::Vendeur);
        $vendeurB = $this->membre($magasin, 'jean@example.test', RoleMagasin::Vendeur);
        $this->actingAs($vendeurA);
        $releveDeA = ClotureCaissePersonnelle::factory()->create();
        $this->actingAs($vendeurB);
        $releveDeB = ClotureCaissePersonnelle::factory()->create();

        $this->assertTrue($vendeurB->can('view', $releveDeB));
        $this->assertFalse($vendeurB->can('view', $releveDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_releves_de_toute_l_equipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, 'paul@example.test', RoleMagasin::Vendeur);
        $comptable = $this->membre($magasin, 'comptable@example.test', RoleMagasin::Comptable);
        $this->actingAs($vendeur);
        $releveDuVendeur = ClotureCaissePersonnelle::factory()->create();

        $this->assertTrue($comptable->can('view', $releveDuVendeur));
    }

    private function membre(Magasin $magasin, string $email, RoleMagasin $role): User
    {
        return app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => $email, 'email' => $email, 'password' => 'mot-de-passe-sur'], $role);
    }
}
