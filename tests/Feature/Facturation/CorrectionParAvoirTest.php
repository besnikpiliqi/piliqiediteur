<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Art. 286 du CGI : un ticket ou une facture émis ne revient jamais en
 * arrière et ne disparaît jamais. Il se corrige par un avoir, document à
 * part qui le reprend en négatif ; seul un brouillon, qui n'a encore ni
 * numéro ni existence légale, se supprime. Aucun statut ne ramène une
 * facture émise en brouillon (voir StatutFactureTest).
 */
class CorrectionParAvoirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Le ticket corrigé garde son numéro et sa date : l'avoir total le
     * solde (plus rien n'est dû) et rend au stock ce que la vente en avait
     * sorti.
     */
    public function test_un_avoir_total_corrige_un_ticket_impaye_sans_le_faire_disparaitre(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]));
        $ligne = $ticket->lignes->firstOrFail();

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligne->id, 'quantite' => 3],
        ], 'Erreur de saisie');

        $this->assertSame(StatutAvoir::Emise, $avoir->fresh()->statut);
        $this->assertSame('180.00', $avoir->fresh()->total_ttc);
        $ticketCorrige = $ticket->fresh();
        $this->assertSame(StatutFacture::Payee, $ticketCorrige->statut);
        $this->assertSame($ticket->numero, $ticketCorrige->numero);
        $this->assertMemeInstant($ticket->date_emission, $ticketCorrige->date_emission);
        $this->assertSame('180.00', $ticketCorrige->total_ttc);
        $this->assertSame(0.0, $ticketCorrige->montantRestant());
        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    /**
     * Un brouillon n'a jamais touché au stock : le supprimer, sa seule
     * correction possible, n'y touche pas non plus.
     */
    public function test_supprimer_un_brouillon_ne_touche_pas_au_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 4],
        ]);

        $brouillon->delete();

        $this->assertModelMissing($brouillon);
        $this->assertSame(10, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $produit->mouvementsStock()->count());
    }
}
