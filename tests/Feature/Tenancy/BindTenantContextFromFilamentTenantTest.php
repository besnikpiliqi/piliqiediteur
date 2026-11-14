<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Contacts\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une requête du panneau lit les données du magasin de son URL. Chaque
 * requête de test repart d'un TenantContext neuf (voir TestCase::call()) :
 * seul le middleware y positionne le magasin, et sans lui la requête
 * échouerait faute de magasin actif.
 */
class BindTenantContextFromFilamentTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_requete_lit_les_donnees_du_magasin_de_son_url(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A'], $proprietaireA);
        Client::factory()->create(['nom' => 'Client de la boutique A']);
        $this->ouvrirUneSociete(['nom' => 'Boutique B']);
        Client::factory()->create(['nom' => 'Client de la boutique B']);

        $reponse = $this->actingAs($proprietaireA)->get("/app/{$magasinA->uuid}/clients");

        $reponse->assertSee('Client de la boutique A')
            ->assertDontSee('Client de la boutique B');
    }
}
