<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AnnulerFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_annuler_une_facture_emise_restaure_le_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 4],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->assertSame('6.000', $produit->fresh()->stock_quantite);

        $facture = app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        $this->assertSame(StatutFacture::Annulee, $facture->statut);
        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    public function test_annuler_un_brouillon_ne_touche_pas_au_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 4],
        ]);

        app(AnnulerFactureAction::class)->handle($facture);

        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    public function test_impossible_dannuler_une_facture_deja_annulee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(AnnulerFactureAction::class)->handle($facture);

        $this->expectException(LogicException::class);
        app(AnnulerFactureAction::class)->handle($facture->fresh());
    }
}
