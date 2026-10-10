<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\NatureLigne;
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

    /**
     * Un produit est un bien, même si autre chose est envoyé ; un service du
     * magasin est un service. La description est celle du catalogue, jamais
     * celle envoyée avec la ligne.
     */
    public function test_chaque_ligne_sait_si_elle_vend_un_bien_ou_un_service(): void
    {
        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 13']);
        $mainDOeuvre = Service::factory()->create(['nom' => 'Pose écran']);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'nature' => 'service', 'description' => 'Autre chose', 'quantite' => 1],
            ['service_id' => $mainDOeuvre->id, 'description' => 'Autre chose', 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $lignes = $facture->lignes->sortBy('ordre')->values();

        $this->assertSame([NatureLigne::Bien, NatureLigne::Service], $lignes->pluck('nature')->all());
        $this->assertSame(['Écran iPhone 13', 'Pose écran'], $lignes->pluck('description')->all());
    }

    public function test_une_ligne_sans_produit_ni_service_est_refusee(): void
    {
        $client = Client::factory()->create();

        $this->expectExceptionObject(new InvalidArgumentException('Une ligne vend un produit ou un service du catalogue.'));

        try {
            app(CreerFactureAction::class)->handle($client, [
                ['description' => 'Coque', 'prix_unitaire_ttc' => 10, 'taux_tva' => 20, 'quantite' => 1],
            ]);
        } finally {
            $this->assertSame(0, Facture::query()->count());
        }
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

    /**
     * Le taux d'une ligne de produit est celui de sa fiche (C15) : un autre
     * taux envoyé avec la ligne est ignoré.
     */
    public function test_le_taux_d_une_ligne_de_produit_est_celui_de_sa_fiche(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'taux_tva' => 0, 'quantite' => 3],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame(['20.00', '10.00', '30.00', '6.00', '36.00'], [$ligne->taux_tva, $ligne->prix_unitaire_ht, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
    }

    /**
     * Le taux d'un service se saisit ; sans taux, c'est celui du
     * territoire du magasin (B2) : 8,5 % à la Martinique.
     */
    public function test_une_ligne_de_service_sans_taux_prend_celui_du_territoire_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code_postal' => '97200']));
        $client = Client::factory()->create();
        $service = Service::factory()->create();

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => '108.50', 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame(['8.50', '100.00', '8.50', '108.50'], [$ligne->taux_tva, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
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
     * Une vente part toujours de son PU TTC, quel que soit le client,
     * multiplié par la quantité ; son PU HT en est la conversion au
     * centime, article par article : 40 € TTC x 2 = 80,00 € TTC, soit 2 x
     * 33,33 € = 66,66 € HT ; 10,05 € TTC (8,375 € -> 8,38 € HT) x 3 =
     * 30,15 € TTC et 25,14 € HT.
     */
    public function test_une_vente_part_toujours_du_pu_ttc(): void
    {
        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['taux_tva' => 20]);
        $cable = Produit::factory()->create(['taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $coque->id, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20],
            ['produit_id' => $cable->id, 'quantite' => 3, 'prix_unitaire_ttc' => '10.05', 'taux_tva' => 20],
        ]);

        [$ligneCoque, $ligneCable] = $facture->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(['33.33', '40.00'], [$ligneCoque->prix_unitaire_ht, $ligneCoque->prix_unitaire_ttc]);
        $this->assertSame(['66.66', '13.34', '80.00'], [$ligneCoque->montant_ht, $ligneCoque->montant_tva, $ligneCoque->montant_ttc]);
        $this->assertSame(['8.38', '10.05'], [$ligneCable->prix_unitaire_ht, $ligneCable->prix_unitaire_ttc]);
        $this->assertSame(['25.14', '5.01', '30.15'], [$ligneCable->montant_ht, $ligneCable->montant_tva, $ligneCable->montant_ttc]);
        $this->assertSame(['91.80', '18.35', '110.15'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Sans prix saisi, la ligne reprend tels quels les prix de vente
     * enregistrés sur la fiche du produit -- arrondis une seule fois, à
     * leur enregistrement : rien ne se recalcule à la vente, que la fiche
     * ait été saisie en TTC ou en HT.
     */
    public function test_sans_prix_saisi_la_ligne_reprend_tels_quels_les_prix_du_produit(): void
    {
        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ht' => 10.01, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $coque->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 3],
        ]);

        [$ligneCoque, $ligneCable] = $facture->lignes->sortBy('ordre')->values()->all();

        foreach ([[$ligneCoque, $coque->fresh()], [$ligneCable, $cable->fresh()]] as [$ligne, $produit]) {
            $this->assertSame([$produit->prix_vente_ht, $produit->prix_vente_ttc], [$ligne->prix_unitaire_ht, $ligne->prix_unitaire_ttc]);
        }

        $this->assertSame(['33.33', '40.00'], [$ligneCoque->prix_unitaire_ht, $ligneCoque->prix_unitaire_ttc]);
        $this->assertSame(['10.01', '12.01'], [$ligneCable->prix_unitaire_ht, $ligneCable->prix_unitaire_ttc]);
        $this->assertSame(['96.69', '19.34', '116.03'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    public function test_une_ligne_de_service_reprend_le_nom_du_service_et_se_calcule_depuis_le_ttc_saisi(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Main-d\'œuvre']);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => '15.03', 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main-d\'œuvre', $ligne->description);
        $this->assertSame('12.53', $ligne->montant_ht);
        $this->assertSame('2.50', $ligne->montant_tva);
        $this->assertSame('15.03', $ligne->montant_ttc);
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

    public function test_un_produit_d_un_autre_magasin_est_introuvable(): void
    {
        $produitAutreMagasin = Produit::factory()->create();
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produitAutreMagasin->id, 'quantite' => 1],
        ]);
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
