<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Widgets\FacturesApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FacturesApercuWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_widget_additionne_le_chiffre_daffaires_facture_de_la_periode(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);

        foreach (range(1, 2) as $_) {
            Livewire::test(CreateFacture::class)->fillForm([
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])->call('create');
        }

        foreach (Facture::query()->where('client_id', $client->id)->get() as $facture) {
            Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        }

        Livewire::test(FacturesApercu::class)
            ->assertSuccessful()
            ->assertSee('240,00 €')
            ->assertSee('2 factures émises');
    }

    public function test_un_brouillon_ne_compte_pas_dans_le_chiffre_daffaires(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);
        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        Livewire::test(FacturesApercu::class)
            ->assertSuccessful()
            ->assertSee('0,00 €')
            ->assertSee('0 facture émise');
    }

    public function test_une_facture_annulee_ne_compte_pas_dans_le_chiffre_daffaires(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);
        app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        Livewire::test(FacturesApercu::class)
            ->assertSuccessful()
            ->assertSee('0,00 €')
            ->assertSee('0 facture émise');
    }
}
