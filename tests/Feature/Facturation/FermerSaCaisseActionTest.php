<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FermerSaCaisseAction (relevé personnel) est délibérément distincte de
 * CloturerCaisseAction (clôture officielle de la journée du magasin, voir
 * CloturerCaisseActionTest) : aucune condition de blocage, pas de FK sur
 * les factures, pas de restriction aux clients particuliers.
 */
class FermerSaCaisseActionTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function emettreFacturePour(User $vendeur, Client $client, float $prixUnitaireHt = 100): void
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_agrege_toutes_les_ventes_du_vendeur_depuis_le_debut_de_la_journee(): void
    {
        $vendeur = User::factory()->create();
        $client = Client::factory()->professionnel()->create(); // même un pro compte ici, contrairement à ClotureCaisse
        $this->emettreFacturePour($vendeur, $client, 100); // 120 TTC

        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur);

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame('120.00', $cloture->total_ttc);
        $this->assertSame($vendeur->id, $cloture->cree_par);
    }

    public function test_une_seconde_fermeture_ne_reprend_que_les_ventes_depuis_la_premiere(): void
    {
        $vendeur = User::factory()->create();
        $client = Client::factory()->create();

        $this->emettreFacturePour($vendeur, $client, 100);
        $premiere = app(FermerSaCaisseAction::class)->handle($vendeur);
        $this->assertSame(1, $premiere->nombre_ventes);

        // Aucune nouvelle vente : la seconde fermeture doit être à zéro,
        // pas reprendre la première vente une deuxième fois.
        $seconde = app(FermerSaCaisseAction::class)->handle($vendeur);
        $this->assertSame(0, $seconde->nombre_ventes);
        $this->assertSame('0.00', $seconde->total_ttc);

        $this->emettreFacturePour($vendeur, $client, 50);
        $troisieme = app(FermerSaCaisseAction::class)->handle($vendeur);
        $this->assertSame(1, $troisieme->nombre_ventes);
        $this->assertSame('60.00', $troisieme->total_ttc);
    }

    public function test_ne_touche_pas_aux_ventes_dun_autre_vendeur(): void
    {
        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $client = Client::factory()->create();

        $this->emettreFacturePour($vendeurA, $client, 100);
        $this->emettreFacturePour($vendeurB, $client, 200);

        $clotureA = app(FermerSaCaisseAction::class)->handle($vendeurA);

        $this->assertSame(1, $clotureA->nombre_ventes);
        $this->assertSame('120.00', $clotureA->total_ttc);
    }

    public function test_naffecte_jamais_les_factures_elles_memes(): void
    {
        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $this->emettreFacturePour($vendeur, $client, 100);

        app(FermerSaCaisseAction::class)->handle($vendeur);

        $facture = Facture::query()->firstOrFail();
        $this->assertNull($facture->cloture_caisse_id, 'Un relevé personnel ne doit jamais marquer une facture comme déjà agrégée pour la clôture officielle.');
    }
}
