<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvoirPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un avoir rectifie une facture : il en reprend les mentions
     * obligatoires.
     */
    public function test_lavoir_porte_la_mention_de_loption_pour_les_debits_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['option_debits_tva' => true]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $html = view('avoirs.pdf', [
            'avoir' => $avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'),
        ])->render();

        $this->assertStringContainsString("Option pour le paiement de la taxe d'après les débits", $html);
    }
}
