<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LigneFacture;
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

    /**
     * La facture tirée du devis prend le type de client du devis, d'après
     * lequel il a été établi : si le client est devenu professionnel
     * depuis, l'émission demande de la réenregistrer.
     */
    public function test_la_facture_tiree_du_devis_prend_son_type_de_client_et_son_emission_signale_le_changement(): void
    {
        $client = Client::factory()->create();
        $devis = app(AccepterDevisAction::class)->handle($this->envoyerDevis($client, Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20])));
        $client->update(['type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $facture = app(CreerFactureDepuisDevisAction::class)->handle($devis);

        $this->assertSame([TypeClient::Particulier, TypeClient::Particulier], [$devis->type_client, $facture->type_client]);
        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle($facture),
            LogicException::class,
            "Ce client est devenu professionnel depuis l'enregistrement du brouillon : ouvrez-le et enregistrez-le de nouveau.",
        );
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

    /**
     * Un devis à un professionnel se calcule comme sa facture, depuis le HT
     * de chaque ligne (E1) : 2 x 49,90 € à 20 % font 83,17 € HT + 16,63 €,
     * 3 x 4,20 € à 5,5 % 11,94 € + 0,66 €. La facture qui en est tirée
     * reprend ces montants et son type de client, et s'émet telle quelle.
     */
    public function test_un_devis_professionnel_converti_garde_ses_montants_et_s_emet(): void
    {
        $client = Client::factory()->professionnel()->create();
        $sacoche = Produit::factory()->create(['prix_vente_ttc' => '49.90', 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['prix_vente_ttc' => '4.20', 'taux_tva' => 5.5, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $sacoche->id, 'quantite' => 2],
            ['produit_id' => $livre->id, 'quantite' => 3],
        ]);
        $devis = app(AccepterDevisAction::class)->handle(app(EmettreDevisAction::class)->handle($devis));

        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureDepuisDevisAction::class)->handle($devis));

        $this->assertSame(['95.11', '17.29', '112.40'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertSame(TypeClient::Professionnel, $facture->type_client);
        $this->assertStringStartsWith('FAC-', $facture->numero);
        $this->assertSame([['83.17', '16.63', '99.80'], ['11.94', '0.66', '12.60']], $facture->lignes->map(fn (LigneFacture $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all());
        $this->assertSame(['95.11', '17.29', '112.40'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    public function test_convertir_un_devis_garde_le_service_de_chaque_ligne_de_service(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);
        $devis = app(AccepterDevisAction::class)->handle(app(EmettreDevisAction::class)->handle($devis));

        $facture = app(CreerFactureDepuisDevisAction::class)->handle($devis);

        $ligne = $facture->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertNull($ligne->produit_id);
        $this->assertSame('Remplacement écran', $ligne->description);
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
