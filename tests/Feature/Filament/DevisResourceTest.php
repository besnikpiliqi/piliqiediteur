<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
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

    /**
     * Besoin explicite : un magasin sans gestion de stock (ou une ligne de
     * type "main d'œuvre"/service) doit pouvoir créer un devis entièrement
     * à la main, sans jamais sélectionner de produit du catalogue.
     */
    public function test_creer_un_devis_avec_une_ligne_entierement_libre_sans_produit(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['description' => 'Main d\'œuvre - pose', 'quantite' => 3, 'prix_unitaire_ht' => 45, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->firstOrFail();
        $ligne = $devis->lignes->firstOrFail();

        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main d\'œuvre - pose', $ligne->description);
        $this->assertSame('135.00', $devis->total_ht);
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

    private function envoyerDevis(Client $client, Produit $produit): Devis
    {
        Livewire::test(CreateDevis::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $devis = Devis::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListDevis::class)->callTableAction('envoyer', $devis);

        return $devis->fresh();
    }

    public function test_accepter_un_devis_envoye_depuis_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    public function test_refuser_un_devis_envoye_depuis_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('refuser', $devis);

        $this->assertSame(StatutDevis::Refuse, $devis->fresh()->statut);
    }

    public function test_convertir_un_devis_accepte_en_facture_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        $facture = Facture::query()->where('devis_id', $devis->id)->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertSame($devis->total_ttc, $facture->total_ttc);
        $this->assertCount(1, $facture->lignes);
    }

    public function test_la_conversion_nest_plus_proposee_une_fois_le_devis_deja_converti(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);
        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }

    public function test_impossible_de_convertir_un_devis_pas_encore_accepte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }
}
