<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le magasin actif d'une requête du panneau est celui de son URL, posé par
 * le middleware : jamais un magasin resté actif avant la requête.
 */
class BindTenantContextFromFilamentTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_requete_lit_les_donnees_du_magasin_de_son_url_et_non_du_magasin_deja_actif(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        Client::factory()->create(['nom' => 'Client de la boutique A']);
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        Client::factory()->create(['nom' => 'Client de la boutique B']);
        app(TenantContext::class)->set($magasinB);

        $reponse = $this->actingAs($proprietaireA)->get("/app/{$magasinA->uuid}/clients");

        $reponse->assertSee('Client de la boutique A')
            ->assertDontSee('Client de la boutique B');
    }
}
