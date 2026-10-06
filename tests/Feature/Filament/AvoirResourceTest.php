<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\AvoirResource\Pages\CreateAvoir;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(7, $facture->lignes->first()->produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);

        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertSame($facture->total_ttc, $avoir->total_ttc);
        $this->assertSame(10, $facture->lignes->first()->produit->fresh()->stock_quantite);
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
        $this->assertSame(8, $facture->lignes->first()->produit->fresh()->stock_quantite);
    }

    /**
     * CreerAvoirAction refuse de créditer une ligne d'une facture encore en
     * brouillon (voir son docblock) : avant la correction, cette
     * LogicException remontait telle quelle jusqu'à une page d'erreur brute
     * au lieu d'une notification -- inacceptable pour un magasin en
     * production (voir CreateAvoir::handleRecordCreation()).
     */
    public function test_creer_un_avoir_sur_une_ligne_dune_facture_brouillon_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $ligneFacture = $facture->lignes->firstOrFail();

        // lignesCreditablesPourClient() exclut déjà les brouillons du champ
        // par défaut : cette ligne ne pourrait normalement jamais être
        // sélectionnée depuis l'UI -- on simule ici une soumission avec un
        // identifiant resté en mémoire sur une page restée ouverte.
        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['ligne_facture_id' => $ligneFacture->id, 'facture_numero' => $facture->numero, 'description' => $ligneFacture->description, 'quantite' => 1],
                ],
                'motif' => 'Tentative sur brouillon',
            ])
            ->call('create')
            ->assertNotified("Impossible de créditer une ligne d'une facture au statut [brouillon].");

        $this->assertSame(0, Avoir::query()->count());
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
        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();

        Livewire::test(ListAvoirs::class)
            ->callTableAction('telecharger_pdf', $avoir)
            ->assertSuccessful();
    }

    /**
     * Exigence explicite : un avoir doit pouvoir regrouper des lignes de
     * plusieurs factures DIFFÉRENTES du même client, pas seulement d'une
     * seule facture -- voir le docblock de CreerAvoirAction.
     */
    public function test_creer_un_avoir_couvrant_deux_factures_differentes_du_meme_client(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client); // 180 TTC (3 x 50 HT, 20%)
        $factureB = $this->emettreFactureDepuisLePanneau($client); // 180 TTC

        $ligneA = $factureA->lignes->firstOrFail();
        $ligneB = $factureB->lignes->firstOrFail();

        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['ligne_facture_id' => $ligneA->id, 'quantite' => 1],
                    ['ligne_facture_id' => $ligneB->id, 'quantite' => 2],
                ],
                'motif' => 'Retour de deux achats différents',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertSame(2, $avoir->lignes()->count());
        $this->assertSame('150.00', $avoir->total_ht); // 1x50 + 2x50
        $this->assertCount(2, $avoir->facturesConcernees());
    }

    /**
     * Défense en profondeur : même si l'UI ne propose normalement que les
     * lignes du client sélectionné (voir AvoirResource::lignesCreditablesPourClient()),
     * l'action elle-même doit aussi refuser toute incohérence -- voir
     * AvoirActionsTest pour le test équivalent au niveau de l'action.
     */
    public function test_le_formulaire_ne_propose_que_les_lignes_du_client_selectionne(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $factureDeA = $this->emettreFactureDepuisLePanneau($clientA);
        $this->emettreFactureDepuisLePanneau($clientB);

        $livewire = Livewire::test(CreateAvoir::class)
            ->fillForm(['client_id' => $clientA->id]);

        $lignes = $livewire->instance()->form->getRawState()['lignes'];
        $this->assertCount(1, $lignes, 'Seule la ligne du client sélectionné doit être proposée.');
        $this->assertSame($factureDeA->lignes->firstOrFail()->id, $lignes[0]['ligne_facture_id']);
    }

    /**
     * Ancienne ligne de 1,5 article (enregistrée avant le passage aux
     * quantités entières) : l'action pré-remplit le reliquat exact et
     * l'enregistre tel quel -- toute la ligne est créditée, et l'action
     * disparaît ensuite.
     */
    public function test_laction_avoir_credite_le_reliquat_exact_dune_ancienne_ligne_a_virgule(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        DB::table('lignes_facture')->where('facture_id', $facture->id)->update(['quantite' => 1.5, 'montant_ht' => 75, 'montant_tva' => 15, 'montant_ttc' => 90]);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture, data: ['motif' => 'Retour client'])
            ->assertHasNoTableActionErrors();

        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(1.5, $avoir->lignes->firstOrFail()->quantite);
        $this->assertSame('90.00', $avoir->total_ttc);

        Livewire::test(ListFactures::class)->assertTableActionHidden('avoir', $facture->fresh());
    }
}
