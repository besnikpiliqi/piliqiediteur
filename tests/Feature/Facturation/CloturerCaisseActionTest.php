<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
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

    public function test_exclut_les_brouillons_et_les_factures_annulees(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Brouillon jamais émis : pas de date_emission, jamais inclus.
        app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $factureAnnulee = $this->emettreFacturePour($client);
        app(AnnulerFactureAction::class)->handle($factureAnnulee, 'Erreur de saisie');

        $cloture = app(CloturerCaisseAction::class)->handle($this->magasin, now());

        $this->assertSame(0, $cloture->nombre_ventes);
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
