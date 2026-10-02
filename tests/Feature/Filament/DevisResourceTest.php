<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DevisResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_devis_avec_des_lignes_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->firstOrFail();
        $this->assertSame(StatutDevis::Brouillon, $devis->statut);
        $this->assertSame('100.00', $devis->total_ht);
        $this->assertCount(1, $devis->lignes);
    }

    public function test_envoyer_un_devis_brouillon_depuis_la_liste_lui_attribue_un_numero(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create');

        $devis = Devis::query()->firstOrFail();

        Livewire::test(ListDevis::class)
            ->callTableAction('envoyer', $devis);

        $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut);
        $this->assertNotNull($devis->fresh()->numero);
    }
}
