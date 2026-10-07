<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreerFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_facture_est_creee_en_brouillon_avec_ses_lignes_et_ses_totaux(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertNull($facture->numero);
        $this->assertCount(1, $facture->lignes);
        $this->assertSame('200.00', $facture->total_ht);
        $this->assertSame('40.00', $facture->total_tva);
        $this->assertSame('240.00', $facture->total_ttc);
    }

    public function test_une_ligne_sans_produit_requiert_une_description_et_un_prix_explicites(): void
    {
        $client = Client::factory()->create();

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['description' => 'Prestation sur mesure', 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $this->assertSame('Prestation sur mesure', $facture->lignes->first()->description);
        $this->assertNull($facture->lignes->first()->produit_id);
    }

    public function test_une_ligne_sans_produit_ni_description_leve_une_exception(): void
    {
        $client = Client::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle($client, [
            ['prix_unitaire_ht' => 50, 'taux_tva' => 20, 'quantite' => 1],
        ]);
    }

    /**
     * Une quantité est toujours un nombre entier d'articles : 1,5 est
     * refusé, jamais tronqué en 1 (ce qui fausserait la facture en
     * silence).
     */
    public function test_une_quantite_non_entiere_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité doit être un nombre entier d'articles, au moins 1.");

        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1.5],
        ]);
    }

    public function test_une_quantite_nulle_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 0],
        ]);
    }

    public function test_une_facture_sans_lignes_leve_une_exception(): void
    {
        $client = Client::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle($client, []);
    }

    public function test_une_remise_reduit_le_montant_ht_de_la_ligne(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 10],
        ]);

        $ligne = $facture->lignes->first();
        $this->assertSame('90.00', $ligne->montant_ht);
        $this->assertSame('18.00', $ligne->montant_tva);
    }

    public function test_le_snapshot_de_la_ligne_ne_change_pas_si_le_produit_change_ensuite(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $produit->update(['prix_vente_ht' => 500, 'taux_tva' => 5]);

        $ligne = $facture->lignes->first()->fresh();
        $this->assertSame('100.00', $ligne->prix_unitaire_ht);
        $this->assertSame('20.00', $ligne->taux_tva);
    }

    /**
     * Le prix réellement saisi sur chaque ligne est gardé tel quel, quel que
     * soit le client, et chaque ligne multiplie séparément son HT et son TTC
     * par la quantité : 8,33 € HT x 3 = 24,99 € HT ; 40 € TTC x 2 = 80,00 €
     * TTC -- même au sein d'un même document.
     */
    public function test_chaque_ligne_est_calculee_depuis_le_prix_reellement_saisi(): void
    {
        $client = Client::factory()->create();

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['description' => 'Prestation pro', 'quantite' => 3, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20],
            ['description' => 'Article vitrine', 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20],
        ]);

        [$ligneHt, $ligneTtc] = $facture->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(PrixReference::Ht, $ligneHt->prix_reference);
        $this->assertSame('24.99', $ligneHt->montant_ht);
        $this->assertSame('5.01', $ligneHt->montant_tva);
        $this->assertSame('30.00', $ligneHt->montant_ttc);
        $this->assertSame('10.00', $ligneHt->prix_unitaire_ttc);

        $this->assertSame(PrixReference::Ttc, $ligneTtc->prix_reference);
        $this->assertSame('80.00', $ligneTtc->montant_ttc);
        $this->assertSame('66.66', $ligneTtc->montant_ht);
        $this->assertSame('33.33', $ligneTtc->prix_unitaire_ht);

        $this->assertSame('91.65', $facture->total_ht);
        $this->assertSame('18.35', $facture->total_tva);
        $this->assertSame('110.00', $facture->total_ttc);
    }

    /**
     * Sans prix saisi, la ligne reprend le prix de vente TTC du produit
     * catalogue (celui affiché en magasin).
     */
    public function test_sans_prix_saisi_la_ligne_reprend_le_prix_ttc_du_produit(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $ligne = $facture->lignes->firstOrFail();
        $this->assertSame(PrixReference::Ttc, $ligne->prix_reference);
        $this->assertSame('80.00', $facture->total_ttc);
    }

    public function test_une_ligne_de_service_reprend_le_nom_du_service_et_se_calcule_depuis_le_ttc_saisi(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Main-d\'œuvre']);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main-d\'œuvre', $ligne->description);
        $this->assertSame('12.50', $ligne->montant_ht);
        $this->assertSame('2.50', $ligne->montant_tva);
        $this->assertSame('15.00', $ligne->montant_ttc);
    }

    public function test_une_ligne_de_service_sans_prix_saisi_est_refusee(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle($client, [
            ['service_id' => $service->id, 'taux_tva' => 20, 'quantite' => 1],
        ]);
    }

    public function test_une_ligne_a_la_fois_produit_et_service_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $service = Service::factory()->create();

        try {
            app(CreerFactureAction::class)->handle($client, [
                ['produit_id' => $produit->id, 'service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
            ]);
            $this->fail('La ligne aurait dû être refusée.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Une ligne est un produit ou un service, jamais les deux.', $exception->getMessage());
        }

        $this->assertSame(0, Facture::query()->count());
    }

    public function test_un_service_d_un_autre_magasin_est_introuvable(): void
    {
        $serviceAutreMagasin = Service::factory()->create();
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(CreerFactureAction::class)->handle($client, [
            ['service_id' => $serviceAutreMagasin->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);
    }
}
