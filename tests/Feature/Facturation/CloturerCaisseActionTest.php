<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CloturerCaisseActionTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function emettreFacturePour(Client $client, float $prixUnitaireHt = 100): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_agrege_uniquement_les_ventes_a_des_clients_particuliers(): void
    {
        $particulier = Client::factory()->create();
        $professionnel = Client::factory()->professionnel()->create();

        $factureParticulier = $this->emettreFacturePour($particulier, 100); // 120 TTC
        $this->emettreFacturePour($professionnel, 50); // ne doit jamais être agrégée

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame('120.00', $cloture->total_ttc);
        $this->assertSame($cloture->id, $factureParticulier->fresh()->cloture_caisse_id);
    }

    /**
     * Une vente corrigée le jour même par un avoir total reste une vente
     * de la journée, et l'avoir s'en déduit : la journée ne garde rien de
     * cette vente, sans qu'aucun document disparaisse.
     */
    public function test_exclut_les_brouillons_et_deduit_une_vente_corrigee_par_un_avoir_total(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Brouillon jamais émis : pas de date_emission, jamais inclus.
        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $venteCorrigee = $this->emettreFacturePour($client);
        app(EnregistrerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteCorrigee->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame('0.00', $cloture->total_ttc);
    }

    /**
     * Un retour se compte le jour où il a lieu : l'avoir émis aujourd'hui
     * sur une vente d'hier, déjà clôturée, se déduit des ventes
     * d'aujourd'hui -- jamais en rouvrant la clôture d'hier.
     */
    public function test_un_avoir_se_deduit_des_ventes_du_jour_de_son_emission(): void
    {
        $client = Client::factory()->create();
        $this->travelTo(now()->subDay());
        $venteDHier = $this->emettreFacturePour($client, 50); // 60 TTC
        app(CloturerCaisseAction::class)->handle($this->magasin, now());
        $this->travelBack();
        $this->emettreFacturePour($client, 100); // 120 TTC
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteDHier->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame(['50.00', '10.00', '60.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 50.0, 'montant_tva' => 10.0, 'montant_ttc' => 60.0],
        ], $cloture->ventilation_tva);
        $this->assertSame($cloture->id, $avoir->fresh()->cloture_caisse_id);
    }

    /**
     * Une journée de caisse va de minuit à minuit à Paris, alors que la
     * date d'émission s'enregistre en UTC : 0 h 30 le 15 juillet à Paris,
     * c'est 22 h 30 UTC le 14. La vente de 23 h 30 se compte le 14 ; la
     * vente et l'avoir émis après minuit, le 15.
     */
    public function test_une_journee_de_caisse_va_de_minuit_a_minuit_a_paris(): void
    {
        $client = Client::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-07-14 21:30:00', 'UTC'));
        $venteDu14 = $this->emettreFacturePour($client, 50);
        $this->travelTo(CarbonImmutable::parse('2026-07-14 22:30:00', 'UTC'));
        $venteDu15 = $this->emettreFacturePour($client, 100);
        $avoirDu15 = app(EnregistrerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteDu14->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);

        $clotureDu14 = app(CloturerCaisseAction::class)->handle($this->magasin, '2026-07-14');
        $clotureDu15 = app(CloturerCaisseAction::class)->handle($this->magasin, '2026-07-15');

        $this->assertSame([1, 0, '60.00'], [$clotureDu14->nombre_ventes, $clotureDu14->nombre_avoirs, $clotureDu14->total_ttc]);
        $this->assertSame([1, 1, '60.00'], [$clotureDu15->nombre_ventes, $clotureDu15->nombre_avoirs, $clotureDu15->total_ttc]);
        $this->assertSame(
            [$clotureDu14->id, $clotureDu15->id, $clotureDu15->id],
            [$venteDu14->fresh()->cloture_caisse_id, $venteDu15->fresh()->cloture_caisse_id, $avoirDu15->fresh()->cloture_caisse_id],
        );
    }

    /**
     * La déclaration des ventes aux particuliers sépare les biens des
     * services : un écran à 60 € et sa pose à 30 €, puis l'écran rendu.
     */
    public function test_la_cloture_separe_les_biens_et_les_services(): void
    {
        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $pose = Service::factory()->create();
        $vente = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['service_id' => $pose->id, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'quantite' => 1],
        ]));
        app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $vente->lignes->firstWhere('produit_id', $ecran->id)->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame([
            ['nature' => NatureLigne::Bien, 'taux_tva' => '20.00', 'montant_ht' => 50.0, 'montant_tva' => 10.0, 'montant_ttc' => 60.0],
            ['nature' => NatureLigne::Service, 'taux_tva' => '20.00', 'montant_ht' => 25.0, 'montant_tva' => 5.0, 'montant_ttc' => 30.0],
        ], $cloture->ventilation_par_nature);
    }

    /**
     * Un client archivé le jour même garde ses ventes et ses retours dans
     * la clôture (voir Facture::client() et Avoir::client()).
     */
    public function test_les_ventes_et_les_retours_d_un_client_archive_restent_dans_la_cloture(): void
    {
        $client = Client::factory()->create();
        $this->emettreFacturePour($client, 100); // 120 TTC
        $venteRendue = $this->emettreFacturePour($client, 50); // 60 TTC
        app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteRendue->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $client->delete();

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(2, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame('120.00', $cloture->total_ttc);
    }

    public function test_un_avoir_a_un_professionnel_nest_jamais_deduit_de_la_cloture(): void
    {
        $professionnel = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($professionnel, 50);
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($professionnel, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(0, $cloture->nombre_avoirs);
        $this->assertSame('0.00', $cloture->total_ttc);
        $this->assertNull($avoir->fresh()->cloture_caisse_id);
    }

    public function test_numerote_la_cloture(): void
    {
        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertNotNull($cloture->numero);
        $this->assertNotNull($cloture->cloturee_a);
    }

    public function test_une_journee_deja_cloturee_ne_peut_pas_letre_deux_fois(): void
    {
        app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->expectException(LogicException::class);
        app(CloturerCaisseAction::class)->handle($this->magasin, now());
    }

    public function test_deux_journees_differentes_peuvent_chacune_etre_cloturees(): void
    {
        $hier = now()->subDay();
        $aujourdhui = now();

        $clotureHier = app(CloturerCaisseAction::class)->handle($this->magasin, $hier);
        $clotureAujourdhui = app(CloturerCaisseAction::class)->handle($this->magasin, $aujourdhui);

        $this->assertNotSame($clotureHier->id, $clotureAujourdhui->id);
    }
}
