<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AvoirActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function emettreFacture(Client $client, Produit $produit, float $quantite = 1): Facture
    {
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_creer_un_avoir_est_un_miroir_exact_des_lignes_et_totaux_de_la_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($facture, 'Produit défectueux');

        $this->assertSame(StatutAvoir::Brouillon, $avoir->statut);
        $this->assertSame($facture->id, $avoir->facture_id);
        $this->assertSame('Produit défectueux', $avoir->motif);
        $this->assertSame($facture->total_ht, $avoir->total_ht);
        $this->assertSame($facture->total_tva, $avoir->total_tva);
        $this->assertSame($facture->total_ttc, $avoir->total_ttc);

        $ligneAvoir = $avoir->lignes->firstOrFail();
        $ligneFacture = $facture->lignes->firstOrFail();
        $this->assertSame($ligneFacture->id, $ligneAvoir->ligne_facture_id);
        $this->assertSame($ligneFacture->produit_id, $ligneAvoir->produit_id);
        $this->assertSame($ligneFacture->quantite, $ligneAvoir->quantite);
        $this->assertSame($ligneFacture->montant_ttc, $ligneAvoir->montant_ttc);
    }

    public function test_creer_un_avoir_ne_touche_pas_encore_au_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->assertSame('7.000', $produit->fresh()->stock_quantite);

        app(CreerAvoirAction::class)->handle($facture);

        $this->assertSame('7.000', $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_lui_attribue_un_numero_et_restaure_le_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($facture);
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertNotNull($avoir->date_emission);
        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_ne_touche_pas_au_stock_dun_produit_sans_suivi(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($facture);
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_en_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture);
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_annulee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);
        $facture = app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture);
    }

    public function test_impossible_de_creer_un_second_avoir_sur_la_meme_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        $premierAvoir = app(CreerAvoirAction::class)->handle($facture);
        app(EmettreAvoirAction::class)->handle($premierAvoir);

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture->fresh());
    }

    public function test_un_avoir_encore_en_brouillon_nempeche_pas_den_creer_un_nouveau(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        // Un brouillon jamais émis (numéro jamais attribué) n'a aucune
        // existence légale : il ne doit pas bloquer la création d'un
        // nouvel avoir, seul un avoir EMIS compte (voir
        // Facture::possedeUnAvoirEmis).
        app(CreerAvoirAction::class)->handle($facture);

        $second = app(CreerAvoirAction::class)->handle($facture->fresh());
        $this->assertNotNull($second);
    }

    public function test_impossible_demettre_deux_fois_le_meme_avoir(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        $avoir = app(CreerAvoirAction::class)->handle($facture);
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->expectException(LogicException::class);
        app(EmettreAvoirAction::class)->handle($avoir->fresh());
    }
}
