<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\FournisseurResource\Pages\CreateFournisseur;
use App\Filament\App\Resources\ProduitResource\Pages\CreateProduit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le mécanisme tenancy/policy de bout en bout est déjà couvert en détail
 * par ClientResourceTest ; ces tests se concentrent sur ce qui est
 * spécifique à chaque Resource (création, champs propres).
 */
class FournisseurProduitResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_proprietaire_peut_afficher_fournisseurs_et_produits(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        $this->get("/app/{$magasin->uuid}/fournisseurs")->assertSuccessful();
        $this->get("/app/{$magasin->uuid}/produits")->assertSuccessful();
    }

    public function test_un_proprietaire_peut_creer_un_fournisseur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste Textile', 'siret' => '12345678900012'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fournisseurs', ['nom' => 'Grossiste Textile', 'magasin_id' => $magasin->id]);
    }

    public function test_un_proprietaire_peut_creer_un_produit(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateProduit::class)
            ->fillForm([
                'reference' => 'REF-001',
                'nom' => 'T-shirt blanc',
                'cmup_ht' => 5,
                'prix_vente_ttc' => 18,
                'taux_tva' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('produits', ['reference' => 'REF-001', 'magasin_id' => $magasin->id, 'prix_vente_ttc' => 18, 'prix_vente_ht' => 15]);
    }
}
