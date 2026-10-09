<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ModifierDevisActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_un_devis_brouillon_est_modifiable(): void
    {
        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $devis = app(ModifierDevisAction::class)->handle($devis, $autreClient, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        $this->assertSame($autreClient->id, $devis->client_id);
        $this->assertSame('150.00', $devis->total_ht);
        $this->assertSame(1, $devis->lignes()->count());
    }

    /**
     * Exigence explicite : un devis reste modifiable même envoyé/accepté/
     * refusé, tant qu'aucune facture n'en a été créée.
     */
    public function test_un_devis_accepte_reste_modifiable(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $devis = app(EmettreDevisAction::class)->handle($devis);
        $devis = app(AccepterDevisAction::class)->handle($devis);

        $devis = app(ModifierDevisAction::class)->handle($devis, $client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $this->assertSame('100.00', $devis->total_ht);
    }

    public function test_un_devis_refuse_reste_modifiable(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $devis = app(EmettreDevisAction::class)->handle($devis);
        $devis = app(RefuserDevisAction::class)->handle($devis);

        $devis = app(ModifierDevisAction::class)->handle($devis, $client, [
            ['produit_id' => $produit->id, 'quantite' => 4],
        ]);

        $this->assertSame('200.00', $devis->total_ht);
    }

    public function test_un_devis_deja_converti_en_facture_nest_plus_modifiable(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $devis = app(EmettreDevisAction::class)->handle($devis);
        $devis = app(AccepterDevisAction::class)->handle($devis);
        app(CreerFactureDepuisDevisAction::class)->handle($devis);

        $this->assertFalse($devis->fresh()->estModifiable());

        $this->expectException(LogicException::class);
        app(ModifierDevisAction::class)->handle($devis->fresh(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 9],
        ]);
    }
}
