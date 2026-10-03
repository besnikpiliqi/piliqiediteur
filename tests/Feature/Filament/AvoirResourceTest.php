<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AvoirResourceTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureDepuisLePanneau(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_laction_avoir_depuis_la_liste_des_factures_cree_et_emet_un_avoir_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $this->assertSame('7.000', $facture->lignes->first()->produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);

        $avoir = Avoir::query()->where('facture_id', $facture->id)->firstOrFail();
        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertSame($facture->total_ttc, $avoir->total_ttc);
        $this->assertSame('10.000', $facture->lignes->first()->produit->fresh()->stock_quantite);
    }

    public function test_laction_avoir_nest_plus_visible_une_fois_toutes_les_lignes_integralement_creditees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);

        Livewire::test(ListFactures::class)
            ->assertTableActionHidden('avoir', $facture->fresh());
    }

    /**
     * Un avoir partiel (voir CreerAvoirAction) laisse un reliquat
     * créditable : l'action doit donc rester visible pour permettre un
     * second avoir sur la quantité restante.
     */
    public function test_laction_avoir_reste_visible_apres_un_avoir_partiel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $ligneFacture = $facture->lignes->firstOrFail();

        // Un remplissage complet du formulaire de l'action (plutôt qu'un
        // "data" partiel fusionné sur l'état par défaut du Repeater, voir
        // callTableAction) est nécessaire ici : ce dernier ajouterait un
        // second item à côté de l'item pré-rempli par défaut au lieu de le
        // remplacer, et créditerait donc une quantité involontairement
        // cumulée.
        Livewire::test(ListFactures::class)
            ->mountTableAction('avoir', $facture)
            ->fillForm([
                'lignes' => [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1]],
                'motif' => 'Retour partiel',
            ], 'mountedTableActionForm')
            ->callMountedTableAction();

        Livewire::test(ListFactures::class)
            ->assertTableActionVisible('avoir', $facture->fresh());

        $this->assertEquals(2, $ligneFacture->fresh()->quantiteCreditableRestante());
        $this->assertSame('8.000', $facture->lignes->first()->produit->fresh()->stock_quantite);
    }

    public function test_telecharger_le_pdf_dun_avoir_emis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);
        $avoir = Avoir::query()->where('facture_id', $facture->id)->firstOrFail();

        Livewire::test(ListAvoirs::class)
            ->callTableAction('telecharger_pdf', $avoir)
            ->assertSuccessful();
    }
}
