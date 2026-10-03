<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AvoirActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function emettreFacture(Client $client, Produit $produit, float $quantite = 1): Facture
    {
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_creer_un_avoir_total_reprend_toutes_les_lignes_et_totaux_de_la_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $avoir = app(CreerAvoirAction::class)->handle($facture, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 3],
        ], 'Produit défectueux');

        $this->assertSame(StatutAvoir::Brouillon, $avoir->statut);
        $this->assertSame($facture->id, $avoir->facture_id);
        $this->assertSame('Produit défectueux', $avoir->motif);
        $this->assertSame($facture->total_ht, $avoir->total_ht);
        $this->assertSame($facture->total_tva, $avoir->total_tva);
        $this->assertSame($facture->total_ttc, $avoir->total_ttc);

        $ligneAvoir = $avoir->lignes->firstOrFail();
        $this->assertSame($ligneFacture->id, $ligneAvoir->ligne_facture_id);
        $this->assertSame($ligneFacture->produit_id, $ligneAvoir->produit_id);
        $this->assertSame($ligneFacture->quantite, $ligneAvoir->quantite);
        $this->assertSame($ligneFacture->montant_ttc, $ligneAvoir->montant_ttc);
    }

    /**
     * Un avoir partiel est légal (voir le docblock de CreerAvoirAction) :
     * créditer 1 unité sur une ligne facturée pour 3 ne doit recalculer le
     * montant que pour cette seule unité, pas reprendre le montant total
     * de la ligne d'origine.
     */
    public function test_creer_un_avoir_partiel_ne_credite_que_la_quantite_demandee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $avoir = app(CreerAvoirAction::class)->handle($facture, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ], 'Retour partiel');

        $ligneAvoir = $avoir->lignes->firstOrFail();
        $this->assertEquals(1, $ligneAvoir->quantite);
        $this->assertSame('50.00', $ligneAvoir->montant_ht);
        $this->assertSame('50.00', $avoir->total_ht);

        // Tant que l'avoir n'est pas ÉMIS, il n'a aucune existence légale
        // et ne réduit pas encore la quantité restante créditable (voir
        // LigneFacture::quantiteDejaCreditee).
        $this->assertEquals(3, $ligneFacture->quantiteCreditableRestante());
        app(EmettreAvoirAction::class)->handle($avoir);
        $this->assertEquals(2, $ligneFacture->fresh()->quantiteCreditableRestante());
    }

    public function test_creer_un_avoir_ne_touche_pas_encore_au_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->assertSame('7.000', $produit->fresh()->stock_quantite);

        app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));

        $this->assertSame('7.000', $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_lui_attribue_un_numero_et_restaure_le_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertNotNull($avoir->date_emission);
        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    /**
     * Seule la quantité réellement créditée restaure du stock, pas
     * l'intégralité de la ligne d'origine.
     */
    public function test_emettre_un_avoir_partiel_ne_restaure_que_la_quantite_creditee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $avoir = app(CreerAvoirAction::class)->handle($facture, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame('8.000', $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_ne_touche_pas_au_stock_dun_produit_sans_suivi(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_en_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_annulee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);
        $facture = app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));
    }

    /**
     * La quantité cumulée créditée par des avoirs ÉMIS ne doit jamais
     * dépasser la quantité facturée, même répartie sur plusieurs avoirs
     * successifs.
     */
    public function test_impossible_de_crediter_plus_que_la_quantite_facturee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($facture, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 4],
        ]);
    }

    /**
     * Après un premier avoir partiel émis, un second avoir peut créditer le
     * reliquat restant de la même ligne -- un avoir n'est donc plus limité
     * à un seul par facture.
     */
    public function test_un_second_avoir_peut_crediter_le_reliquat_dune_ligne_deja_partiellement_creditee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $premierAvoir = app(CreerAvoirAction::class)->handle($facture, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($premierAvoir);

        $this->assertEquals(2, $ligneFacture->fresh()->quantiteCreditableRestante());

        // Tenter de créditer plus que le reliquat (2) échoue toujours.
        try {
            app(CreerAvoirAction::class)->handle($facture->fresh(), [
                ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 3],
            ]);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException) {
            // Comportement attendu.
        }

        $secondAvoir = app(CreerAvoirAction::class)->handle($facture->fresh(), [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 2],
        ]);
        app(EmettreAvoirAction::class)->handle($secondAvoir);

        $this->assertEquals(0, $ligneFacture->fresh()->quantiteCreditableRestante());
    }

    public function test_un_avoir_encore_en_brouillon_nempeche_pas_den_creer_un_nouveau(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        // Un brouillon jamais émis (numéro jamais attribué) n'a aucune
        // existence légale : il ne doit pas réduire la quantité restante
        // créditable, seul un avoir EMIS compte (voir
        // LigneFacture::quantiteDejaCreditee).
        app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));

        $second = app(CreerAvoirAction::class)->handle($facture->fresh(), $this->toutesLesLignes($facture->fresh()));
        $this->assertNotNull($second);
    }

    public function test_impossible_demettre_deux_fois_le_meme_avoir(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        $avoir = app(CreerAvoirAction::class)->handle($facture, $this->toutesLesLignes($facture));
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->expectException(LogicException::class);
        app(EmettreAvoirAction::class)->handle($avoir->fresh());
    }

    /**
     * @return list<array{ligne_facture_id: int, quantite: float}>
     */
    private function toutesLesLignes(Facture $facture): array
    {
        return $facture->lignes->map(fn ($ligne) => [
            'ligne_facture_id' => $ligne->id,
            'quantite' => (float) $ligne->quantite,
        ])->all();
    }
}
