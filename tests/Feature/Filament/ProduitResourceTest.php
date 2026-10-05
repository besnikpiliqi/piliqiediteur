<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\ProduitResource\Pages\CreateProduit;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le prix de vente d'un produit se saisit en TTC (voir
 * ProduitResource::prixVenteTtcSchema()), pas en HT : c'est le prix
 * affiché au client qui compte pour un vendeur qui fixe un tarif. Seul
 * prix_vente_ht est réellement enregistré, dérivé du TTC saisi.
 */
class ProduitResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_prix_de_vente_ttc_est_converti_et_enregistre_en_ht_a_la_creation(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateProduit::class)
            ->fillForm([
                'nom' => 'Ecran Iphone 17 Pro',
                'prix_achat_ht' => 80,
                'taux_tva' => 20,
            ]);

        // 180 € TTC à 20 % de TVA = 150 € HT : seul ce prix HT est
        // réellement enregistré (voir Hidden::make('prix_vente_ht')).
        $component->set('data.prix_vente_ttc', '180');
        $this->assertSame(150.0, (float) $component->get('data.prix_vente_ht'));

        $component->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('produits', [
            'nom' => 'Ecran Iphone 17 Pro',
            'prix_achat_ht' => 80,
            'prix_vente_ht' => 150,
        ]);
    }

    public function test_modifier_le_prix_de_vente_ttc_recalcule_et_enregistre_le_nouveau_ht(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create([
            'prix_vente_ht' => 100,
            'taux_tva' => 20,
        ]);

        // À l'ouverture du formulaire, le TTC affiché doit refléter le HT
        // déjà enregistré (120 € TTC pour 100 € HT à 20 % de TVA).
        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormSet(['prix_vente_ttc' => 120.0]);

        $component = Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()]);
        $component->set('data.prix_vente_ttc', '144');
        $this->assertSame(120.0, (float) $component->get('data.prix_vente_ht'));

        $component->call('save')->assertHasNoFormErrors();

        $this->assertSame('120.0000', $produit->fresh()->prix_vente_ht);
    }

    /**
     * Reproduit le cas signalé par un magasin : un produit visé à 40 € TTC
     * (via le vrai formulaire "Prix de vente TTC", pas une valeur HT
     * précalculée directement en base) doit totaliser exactement 80,00 €
     * TTC à quantité 2 -- sans la précision à 4 décimales de prix_vente_ht,
     * ce total dérivait à 79,99 € (voir le docblock de CalculateurLigne).
     */
    public function test_un_produit_cree_via_le_prix_ttc_totalise_exactement_a_quantite_2(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateProduit::class)
            ->fillForm([
                'nom' => 'Produit à 40 € TTC',
                'prix_achat_ht' => 10,
                'taux_tva' => 20,
                'suivi_stock' => false,
            ]);
        $component->set('data.prix_vente_ttc', '40');
        $component->call('create')->assertHasNoFormErrors();

        $produit = Produit::query()->where('nom', 'Produit à 40 € TTC')->firstOrFail();

        $client = Client::factory()->create();

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => (float) $produit->prix_vente_ht, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('80.00', $facture->total_ttc);
    }
}
