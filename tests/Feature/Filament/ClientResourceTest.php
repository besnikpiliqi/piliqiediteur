<?php

namespace Tests\Feature\Filament;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\ClientResource\Pages\CreateClient;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Test d'intégration bout en bout : vrai panneau Filament, vraie tenancy
 * par URL, vraie policy -- pas seulement l'Action en isolation (déjà
 * couverte par CreerFactureActionTest et consorts).
 */
class ClientResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_proprietaire_peut_afficher_la_liste_des_clients_de_son_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire)
            ->get("/app/{$magasin->uuid}/clients")
            ->assertSuccessful();
    }

    public function test_un_proprietaire_peut_creer_un_client_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        // Livewire::test() ne passe pas par le pipeline HTTP/middleware :
        // IdentifyTenant (qui appelle Filament::setTenant() depuis l'URL)
        // ne s'exécute jamais. On simule donc manuellement ce que la
        // requête HTTP réelle aurait déjà résolu.
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom' => 'Jean Dupont',
                'type_client' => TypeClient::Particulier->value,
                'email' => 'jean@example.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Jean Dupont',
            'magasin_id' => $magasin->id,
        ]);
    }

    public function test_un_utilisateur_dun_autre_magasin_ne_peut_pas_acceder_a_la_liste(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);

        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        // 404, pas 403 : IdentifyTenant masque jusqu'à l'existence même du
        // magasin à un utilisateur qui n'y appartient pas (voir
        // vendor/filament/filament/src/Http/Middleware/IdentifyTenant.php).
        $this->actingAs($proprietaireB)
            ->get("/app/{$magasinA->uuid}/clients")
            ->assertNotFound();
    }
}
