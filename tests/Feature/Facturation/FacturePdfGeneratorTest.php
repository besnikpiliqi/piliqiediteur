<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_pdf_non_vide_pour_une_facture_emise(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 42, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $pdf = app(FacturePdfGenerator::class)->generer($facture);
        $contenu = $pdf->output();

        $this->assertStringStartsWith('%PDF', $contenu);
        $this->assertGreaterThan(500, strlen($contenu));
    }

    public function test_le_nom_de_fichier_utilise_le_numero_ou_brouillon(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertStringContainsString('brouillon-', $generateur->nomFichier($facture));

        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->assertSame("facture-{$facture->numero}.pdf", $generateur->nomFichier($facture));
    }
}
