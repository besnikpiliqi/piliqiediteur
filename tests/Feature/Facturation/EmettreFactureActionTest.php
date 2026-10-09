<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class EmettreFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
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

    /**
     * Un produit supprimé après avoir été mis dans un brouillon sort quand
     * même du stock à l'émission, avec son mouvement (voir
     * LigneFacture::produit()).
     */
    public function test_emettre_decremente_le_stock_d_un_produit_supprime_depuis_le_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 5, 'suivi_stock' => true]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $produit->delete();

        app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame(3, $produit->fresh()->stock_quantite);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $produit->id, 'type' => 'vente', 'quantite' => 2]);
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

    /**
     * Le premier segment du numéro dit pour toujours dans quelle série la
     * vente a été numérotée, quel que soit le code du magasin qui suit.
     */
    public function test_la_serie_se_lit_dans_le_premier_segment_du_numero(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $creerEtEmettre = fn (Client $client) => app(EmettreFactureAction::class)->handle(
            app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]])
        );

        $ticket = $creerEtEmettre(Client::factory()->create());
        $facture = $creerEtEmettre(Client::factory()->create(['type_client' => TypeClient::Professionnel]));

        $this->assertSame(['TK-LYON1-2026-000001', TypeDocument::TicketCaisse], [$ticket->numero, $ticket->serie()]);
        $this->assertSame(['FAC-LYON1-2026-000001', TypeDocument::Facture], [$facture->numero, $facture->serie()]);
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
        $this->assertSame("TK-LYON1-{$annee}-000001", $ticket1->numero);
        $this->assertSame("FAC-LYON1-{$annee}-000001", $facture1->numero);
        $this->assertSame("TK-LYON1-{$annee}-000002", $ticket2->numero);
    }

    /**
     * 23 h 30 UTC le 31 décembre, c'est déjà 0 h 30 le 1er janvier à Paris
     * (heure d'hiver) : la vente appartient à la nouvelle année.
     */
    public function test_une_vente_a_0_h_30_le_1er_janvier_a_paris_est_datee_et_numerotee_sur_la_nouvelle_annee(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame('TK-LYON1-2027-000001', $facture->numero);
        $this->assertSame('2027-01-01 00:30', $facture->fresh()->date_emission->format('Y-m-d H:i'));
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
