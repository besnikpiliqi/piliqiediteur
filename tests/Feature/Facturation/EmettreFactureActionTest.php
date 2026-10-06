<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EmettreFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_emettre_une_facture_lui_attribue_un_numero_et_change_son_statut(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertNotNull($facture->numero);
        $this->assertNotNull($facture->date_emission);
    }

    public function test_emettre_decremente_le_stock_des_produits_suivis(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_emettre_ne_touche_pas_au_stock_dun_produit_sans_suivi(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    /**
     * Exigence explicite : un ticket de caisse (vente à un particulier) et
     * une vraie facture professionnelle ne doivent jamais partager la même
     * suite numérique, même s'ils restent le même enregistrement Facture
     * en base -- voir le docblock de EmettreFactureAction.
     */
    public function test_une_vente_a_un_particulier_recoit_un_numero_de_ticket_de_caisse(): void
    {
        $client = Client::factory()->create(); // particulier par défaut
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle($facture);

        $this->assertStringStartsWith('TK-', $facture->numero);
    }

    public function test_une_vente_a_un_professionnel_recoit_un_numero_de_facture(): void
    {
        $client = Client::factory()->create(['type_client' => TypeClient::Professionnel]);
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle($facture);

        $this->assertStringStartsWith('FAC-', $facture->numero);
    }

    public function test_les_tickets_et_les_factures_ont_des_numerotations_independantes(): void
    {
        $clientParticulier = Client::factory()->create();
        $clientPro = Client::factory()->create(['type_client' => TypeClient::Professionnel]);
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        $creerEtEmettre = fn (Client $client) => app(EmettreFactureAction::class)->handle(
            app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]])
        );

        $ticket1 = $creerEtEmettre($clientParticulier);
        $facture1 = $creerEtEmettre($clientPro);
        $ticket2 = $creerEtEmettre($clientParticulier);

        $annee = now()->format('Y');
        $this->assertSame("TK-{$annee}-000001", $ticket1->numero);
        $this->assertSame("FAC-{$annee}-000001", $facture1->numero);
        $this->assertSame("TK-{$annee}-000002", $ticket2->numero);
    }

    public function test_impossible_demettre_une_facture_sans_lignes(): void
    {
        $client = Client::factory()->create();
        $facture = Facture::create(['client_id' => $client->id]);

        $this->expectException(LogicException::class);
        app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_impossible_demettre_deux_fois_la_meme_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        app(EmettreFactureAction::class)->handle($facture);

        $this->expectException(LogicException::class);
        app(EmettreFactureAction::class)->handle($facture->fresh());
    }
}
