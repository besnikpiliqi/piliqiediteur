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
use InvalidArgumentException;
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

    private function emettreFacture(Client $client, Produit $produit, int $quantite = 1): Facture
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

        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 3],
        ], 'Produit défectueux');

        $this->assertSame(StatutAvoir::Brouillon, $avoir->statut);
        $this->assertSame($client->id, $avoir->client_id);
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
     * Exigence explicite : un avoir doit pouvoir regrouper des lignes de
     * plusieurs factures DIFFÉRENTES du même client en une seule fois.
     */
    public function test_creer_un_avoir_couvrant_deux_factures_differentes_du_meme_client(): void
    {
        $client = Client::factory()->create();
        $produitA = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $produitB = Produit::factory()->create(['prix_vente_ht' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureA = $this->emettreFacture($client, $produitA, 2);
        $factureB = $this->emettreFacture($client, $produitB, 1);

        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $factureA->lignes->firstOrFail()->id, 'quantite' => 2],
            ['ligne_facture_id' => $factureB->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Retour de deux achats différents');

        $this->assertSame('130.00', $avoir->total_ht); // 2x50 + 1x30
        $this->assertCount(2, $avoir->lignes);
        $this->assertCount(2, $avoir->facturesConcernees());
        $this->assertTrue($avoir->facturesConcernees()->contains('id', $factureA->id));
        $this->assertTrue($avoir->facturesConcernees()->contains('id', $factureB->id));
    }

    /**
     * Un avoir ne doit jamais pouvoir mélanger les factures de deux
     * clients différents : chaque avoir reste émis à UN client.
     */
    public function test_impossible_dinclure_une_ligne_dune_facture_dun_autre_client(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureDeB = $this->emettreFacture($clientB, $produit);

        $this->expectException(InvalidArgumentException::class);
        app(CreerAvoirAction::class)->handle($clientA, [
            ['ligne_facture_id' => $factureDeB->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
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

        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ], 'Retour partiel');

        $ligneAvoir = $avoir->lignes->firstOrFail();
        $this->assertSame(1, $ligneAvoir->quantite);
        $this->assertSame('50.00', $ligneAvoir->montant_ht);
        $this->assertSame('50.00', $avoir->total_ht);

        // Tant que l'avoir n'est pas ÉMIS, il n'a aucune existence légale
        // et ne réduit pas encore la quantité restante créditable (voir
        // LigneFacture::quantiteDejaCreditee).
        $this->assertSame(3, $ligneFacture->quantiteCreditableRestante());
        app(EmettreAvoirAction::class)->handle($avoir);
        $this->assertSame(2, $ligneFacture->fresh()->quantiteCreditableRestante());
    }

    public function test_creer_un_avoir_ne_touche_pas_encore_au_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $this->assertSame(7, $produit->fresh()->stock_quantite);

        app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_lui_attribue_un_numero_et_restaure_le_stock(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertNotNull($avoir->date_emission);
        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    /**
     * Un produit supprimé depuis sa vente rentre quand même en stock à son
     * retour, avec son mouvement (voir LigneAvoir::produit()).
     */
    public function test_emettre_un_avoir_restaure_le_stock_d_un_produit_supprime_depuis_la_vente(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $produit->delete();

        app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture)));

        $this->assertSame(10, $produit->fresh()->stock_quantite);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $produit->id, 'type' => 'retour_client', 'quantite' => 3]);
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

        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(8, $produit->fresh()->stock_quantite);
    }

    public function test_emettre_un_avoir_ne_touche_pas_au_stock_dun_produit_sans_suivi(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);

        $avoir = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        app(EmettreAvoirAction::class)->handle($avoir);

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    /**
     * 3 articles vendus sans suivi du stock ne sont jamais sortis du stock :
     * le suivi activé ensuite, leur retour n'y fait rien entrer (le stock
     * ne passe pas à 3).
     */
    public function test_le_retour_d_un_article_vendu_sans_suivi_du_stock_ne_le_fait_pas_entrer(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $produit->update(['suivi_stock' => true]);

        app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture)));

        $this->assertSame(0, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $produit->mouvementsStock()->count());
    }

    /**
     * Retourné en deux avoirs, un article vendu revient en stock une
     * seule fois en tout ; chaque retour a pour source la ligne de son
     * avoir.
     */
    public function test_des_retours_successifs_rendent_au_stock_ce_que_la_vente_en_a_sorti(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $premier = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1]]));
        $second = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 2]]));

        $this->assertSame(0, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $facture->quantiteSortieDuStock($produit));
        $this->assertSame(
            [[$premier->lignes->sole()->id, 1], [$second->lignes->sole()->id, 2]],
            $produit->mouvementsStock()->where('type', 'retour_client')->orderBy('id')->get()->map(fn ($mouvement): array => [$mouvement->source_id, $mouvement->quantite])->all(),
        );
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_en_brouillon(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
    }

    public function test_impossible_de_creer_un_avoir_sur_une_facture_annulee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);
        $facture = app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        $this->expectException(LogicException::class);
        app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
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
        app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 4],
        ]);
    }

    /**
     * Après un premier avoir partiel émis, un second avoir peut créditer ce
     * qui reste sur la même ligne : une facture peut recevoir plusieurs
     * avoirs.
     */
    public function test_un_second_avoir_peut_crediter_le_reliquat_dune_ligne_deja_partiellement_creditee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 3);
        $ligneFacture = $facture->lignes->firstOrFail();

        $premierAvoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($premierAvoir);

        $this->assertSame(2, $ligneFacture->fresh()->quantiteCreditableRestante());

        // Tenter de créditer plus que le reliquat (2) échoue toujours.
        try {
            app(CreerAvoirAction::class)->handle($client, [
                ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 3],
            ]);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException) {
            // Comportement attendu.
        }

        $secondAvoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 2],
        ]);
        app(EmettreAvoirAction::class)->handle($secondAvoir);

        $this->assertSame(0, $ligneFacture->fresh()->quantiteCreditableRestante());
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
        app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));

        $second = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture->fresh()));
        $this->assertNotNull($second);
    }

    public function test_impossible_demettre_deux_fois_le_meme_avoir(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit);

        $avoir = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        $avoir = app(EmettreAvoirAction::class)->handle($avoir);

        $this->expectException(LogicException::class);
        app(EmettreAvoirAction::class)->handle($avoir->fresh());
    }

    /**
     * Deux retours du même article saisis en même temps : le second
     * brouillon a été créé avant l'émission du premier, mais au moment
     * d'être émis il ne trouve plus rien à créditer.
     */
    public function test_un_avoir_ne_credite_jamais_une_ligne_creditee_entre_temps_par_un_autre(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 1);
        $premier = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        $second = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        app(EmettreAvoirAction::class)->handle($premier);

        $this->expectExceptionObject(new LogicException("La ligne \"{$produit->nom}\" a été créditée entre-temps par un autre avoir : il ne reste que 0 article(s) à créditer."));

        try {
            app(EmettreAvoirAction::class)->handle($second);
        } finally {
            $this->assertSame(StatutAvoir::Brouillon, $second->fresh()->statut);
            $this->assertSame(10, $produit->fresh()->stock_quantite);
        }
    }

    /**
     * Les deux retours d'une ligne remisée sont saisis avant toute
     * émission : chacun vaut alors 30,00 € HT, mais celui qui est émis en
     * dernier finit de créditer la ligne et en prend le solde exact
     * (29,99 € HT), comme s'il avait été saisi après le premier.
     */
    public function test_le_dernier_avoir_emis_sur_une_ligne_en_credite_le_solde_exact_meme_saisi_avant(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'remise_pourcentage' => 10],
        ]));
        $ligneFacture = $facture->lignes->firstOrFail();
        $premier = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        $dernier = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($premier);

        app(EmettreAvoirAction::class)->handle($dernier);

        $dernier = $dernier->fresh('lignes');
        $ligneAvoir = $dernier->lignes->firstOrFail();
        $this->assertSame(['29.99', '6.01', '36.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
        $this->assertSame(['29.99', '6.01', '36.00'], [$ligneAvoir->montant_ht, $ligneAvoir->montant_tva, $ligneAvoir->montant_ttc]);
    }

    public function test_un_avoir_ne_s_emet_pas_sur_une_facture_annulee_depuis_sa_saisie(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = $this->emettreFacture($client, $produit, 1);
        $avoir = app(CreerAvoirAction::class)->handle($client, $this->toutesLesLignes($facture));
        app(AnnulerFactureAction::class)->handle($facture, 'Erreur de saisie');

        $this->expectExceptionObject(new LogicException("Impossible d'émettre cet avoir : la facture {$facture->numero} a été annulée."));

        try {
            app(EmettreAvoirAction::class)->handle($avoir);
        } finally {
            $this->assertSame(StatutAvoir::Brouillon, $avoir->fresh()->statut);
            $this->assertSame(10, $produit->fresh()->stock_quantite);
        }
    }

    /**
     * Un retour partiel reprend le PU TTC de la ligne vendue (qui fait
     * référence) : rendre 1 article sur 2 vendus à 40 € TTC crédite
     * exactement 40,00 €, et le second retour retombe pile sur les 80,00 €
     * facturés -- jamais 79,99 € ni 80,01 € au total.
     */
    public function test_les_retours_partiels_dun_article_a_40_euros_ttc_creditent_exactement_la_ligne_vendue(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 2);
        $ligneFacture = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $facture->total_ttc);

        $premierRetour = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        $secondRetour = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);

        $this->assertSame('40.00', $premierRetour->total_ttc);
        $this->assertSame('33.33', $premierRetour->total_ht);
        $this->assertSame('6.67', $premierRetour->total_tva);
        $this->assertSame('40.00', $premierRetour->lignes->firstOrFail()->prix_unitaire_ttc);
        $this->assertSame(80.0, (float) $premierRetour->total_ttc + (float) $secondRetour->total_ttc);
    }

    /**
     * Deux retours d'un article sur une ligne de 2 x 40 € TTC créditent
     * exactement la ligne facturée : 66,66 € HT, 13,34 € de TVA, 80,00 €
     * TTC (le dernier retour en prenant le solde exact).
     */
    public function test_le_dernier_retour_dune_ligne_ttc_en_credite_le_solde_exact(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettreFacture($client, $produit, 2);
        $ligneFacture = $facture->lignes->firstOrFail();

        $premier = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]));
        $dernier = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);

        $this->assertSame(['33.33', '6.67', '40.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['33.33', '6.67', '40.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
        $this->assertSame('66.66', number_format((float) $premier->total_ht + (float) $dernier->total_ht, 2, '.', ''));
        $this->assertSame('13.34', number_format((float) $premier->total_tva + (float) $dernier->total_tva, 2, '.', ''));
    }

    /**
     * Avec une remise, un article rendu seul ne vaut pas la moitié de la
     * ligne : 2 x 40 € TTC remisés de 10 % = 59,99 € HT (6,666 € de remise
     * -> 6,67 €), mais un article seul vaut 30,00 € HT. Le dernier retour
     * crédite donc le solde (29,99 € HT, 6,01 € de TVA), jamais un second
     * recalcul qui créditerait 60,00 € HT au total.
     */
    public function test_le_dernier_retour_dune_ligne_remisee_en_credite_le_solde_exact(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'remise_pourcentage' => 10],
        ]));
        $ligneFacture = $facture->lignes->firstOrFail();

        $this->assertSame(['59.99', '12.01', '72.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);

        $premier = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]));
        $dernier = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);

        $this->assertSame(['30.00', '6.00', '36.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['29.99', '6.01', '36.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
    }

    public function test_une_quantite_a_crediter_non_entiere_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ligneFacture = $this->emettreFacture($client, $produit, 3)->lignes->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité à créditer doit être un nombre entier d'articles, au moins 1.");

        app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1.5],
        ]);
    }

    /**
     * @return list<array{ligne_facture_id: int, quantite: int}>
     */
    private function toutesLesLignes(Facture $facture): array
    {
        return $facture->lignes->map(fn ($ligne) => [
            'ligne_facture_id' => $ligne->id,
            'quantite' => $ligne->quantite,
        ])->all();
    }

    /**
     * La même ligne deux fois dans un avoir échapperait au plafond de la
     * quantité restante (2 articles vendus, 4 crédités).
     */
    public function test_une_meme_ligne_deux_fois_dans_un_avoir_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ligneFacture = $this->emettreFacture($client, $produit, 2)->lignes->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Une même ligne de facture ne peut apparaître qu'une fois dans un avoir.");

        app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 2],
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 2],
        ]);
    }
}
