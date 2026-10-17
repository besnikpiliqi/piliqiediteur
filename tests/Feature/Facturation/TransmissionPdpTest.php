<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Couvre la décision "doit-on transmettre cette facture au PDP ?" -- voir
 * DeclencherTransmissionPdpApresEmissionFacture -- puis l'exécution réelle
 * du job contre la FakePdpGateway.
 */
class TransmissionPdpTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function emettreFacturePour(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_une_facture_a_un_client_professionnel_avec_mandat_actif_declenche_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();

        $facture = $this->emettreFacturePour($client);

        Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $facture->id);
    }

    public function test_une_facture_a_un_client_particulier_ne_declenche_jamais_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->create(); // particulier par défaut

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_sans_mandat_actif_rien_nest_transmis_meme_pour_un_client_professionnel(): void
    {
        Queue::fake();
        $client = Client::factory()->professionnel()->create();
        // Aucun mandat créé pour ce magasin.

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_un_mandat_revoque_bloque_aussi_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->revoque()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_le_job_execute_reellement_cree_une_transmission_acceptee_via_la_fake_gateway(): void
    {
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($client);

        // Le listener a déjà dispatché le job (queue sync par défaut en
        // test, voir phpunit.xml) : la transmission doit déjà exister.
        $facture->refresh();
        $transmission = $facture->transmissionsPdp()->first();

        $this->assertNotNull($transmission);
        $this->assertSame(StatutTransmissionPdp::Acceptee, $transmission->statut);
        $this->assertNotNull($transmission->transmission_externe_id);
        $this->assertSame(1, $transmission->evenements()->count());
    }

    public function test_le_job_est_idempotent_si_rejoue_sur_une_facture_deja_transmise(): void
    {
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($client);

        $this->assertSame(1, $facture->transmissionsPdp()->count());

        // Rejouer manuellement le job ne doit pas créer une deuxième transmission.
        (new SubmitFactureToPdpJob($this->magasin->id, $facture->id))->handle(app(PdpGatewayInterface::class));

        $this->assertSame(1, $facture->transmissionsPdp()->count());
    }
}
