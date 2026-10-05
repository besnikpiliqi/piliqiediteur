<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DevisConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function envoyerDevis(Client $client, Produit $produit): Devis
    {
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        return app(EmettreDevisAction::class)->handle($devis);
    }

    public function test_accepter_un_devis_envoye_change_son_statut(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        $devis = app(AccepterDevisAction::class)->handle($devis);

        $this->assertSame(StatutDevis::Accepte, $devis->statut);
    }

    public function test_refuser_un_devis_envoye_change_son_statut(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        $devis = app(RefuserDevisAction::class)->handle($devis);

        $this->assertSame(StatutDevis::Refuse, $devis->statut);
    }

    public function test_impossible_daccepter_un_devis_encore_en_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->expectException(LogicException::class);
        app(AccepterDevisAction::class)->handle($devis);
    }

    public function test_convertir_un_devis_accepte_copie_ses_lignes_dans_une_facture_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        $devis = app(AccepterDevisAction::class)->handle($devis);

        $facture = app(CreerFactureDepuisDevisAction::class)->handle($devis);

        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertSame($devis->id, $facture->devis_id);
        $this->assertSame($devis->total_ht, $facture->total_ht);
        $this->assertSame($devis->total_tva, $facture->total_tva);
        $this->assertSame($devis->total_ttc, $facture->total_ttc);

        $ligneFacture = $facture->lignes->firstOrFail();
        $ligneDevis = $devis->lignes->firstOrFail();
        $this->assertSame($ligneDevis->produit_id, $ligneFacture->produit_id);
        $this->assertSame($ligneDevis->quantite, $ligneFacture->quantite);
        $this->assertSame($ligneDevis->montant_ttc, $ligneFacture->montant_ttc);
        $this->assertSame($ligneDevis->prix_unitaire_ttc, $ligneFacture->prix_unitaire_ttc);
        $this->assertSame('60.00', $ligneFacture->prix_unitaire_ttc);
    }

    public function test_impossible_de_convertir_un_devis_pas_encore_accepte(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        $this->expectException(LogicException::class);
        app(CreerFactureDepuisDevisAction::class)->handle($devis);
    }

    public function test_impossible_de_convertir_deux_fois_le_meme_devis(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        $devis = app(AccepterDevisAction::class)->handle($devis);

        app(CreerFactureDepuisDevisAction::class)->handle($devis);

        $this->expectException(LogicException::class);
        app(CreerFactureDepuisDevisAction::class)->handle($devis->fresh());
    }
}
