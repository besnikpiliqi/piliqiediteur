<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AvoirFournisseurActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function receptionnerAchat(Fournisseur $fournisseur, Produit $produit, int $quantite): FactureAchat
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => 20],
        ]);

        return app(ValiderFactureAchatAction::class)->handle($factureAchat);
    }

    public function test_creer_un_avoir_fournisseur_total_reprend_la_ligne_et_les_totaux(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 5);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 5],
        ], 'Marchandise non conforme');

        $this->assertSame(StatutAvoirFournisseur::Brouillon, $avoir->statut);
        $this->assertSame('Marchandise non conforme', $avoir->motif);
        $this->assertSame($factureAchat->total_ht, $avoir->total_ht);
        $this->assertSame(SourceAvoirFournisseur::Manuelle, $avoir->source, 'Cette action ne crée aujourd\'hui que des avoirs saisis manuellement.');

        $ligneAvoir = $avoir->lignes->firstOrFail();
        $this->assertSame($ligneAchat->id, $ligneAvoir->ligne_achat_id);
        $this->assertSame($ligneAchat->produit_id, $ligneAvoir->produit_id);
    }

    public function test_emettre_un_avoir_fournisseur_lui_attribue_un_numero_et_decremente_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 5);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ]);
        $avoir = app(EmettreAvoirFournisseurAction::class)->handle($avoir);

        $this->assertSame(StatutAvoirFournisseur::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertSame(3, $produit->fresh()->stock_quantite);
    }

    public function test_impossible_de_creer_un_avoir_fournisseur_sur_une_facture_dachat_en_brouillon(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);

        $this->expectException(LogicException::class);
        app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->first()->id, 'quantite' => 1],
        ]);
    }

    /**
     * Exigence explicite : un produit déjà vendu à un client ne doit
     * jamais pouvoir être retourné au fournisseur. Cette application ne
     * trace pas le stock par lot ; la quantité retournable est donc
     * plafonnée au stock actuellement disponible (voir
     * LigneAchat::quantiteRetournableRestante()) -- un produit vendu a
     * quitté ce stock, il ne peut plus être compté dedans.
     */
    public function test_un_produit_deja_vendu_ne_peut_pas_etre_inclus_dans_lavoir_fournisseur(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => true]);

        // Réception de 5 unités.
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 5);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        // 3 unités sont vendues à un client : il ne doit en rester que 2
        // disponibles au retour fournisseur, même si la ligne d'achat
        // porte sur 5.
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);
        app(EmettreFactureAction::class)->handle($facture);

        $this->assertSame(2, $produit->fresh()->stock_quantite);
        $this->assertEquals(2, $ligneAchat->fresh()->quantiteRetournableRestante());

        // Tenter de retourner les 5 unités d'origine (dont 3 déjà vendues)
        // doit échouer.
        try {
            app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
                ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 5],
            ]);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException) {
            // Comportement attendu.
        }

        // Retourner exactement les 2 unités encore en stock doit réussir.
        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ]);
        app(EmettreAvoirFournisseurAction::class)->handle($avoir);

        $this->assertSame(0, $produit->fresh()->stock_quantite);
    }

    public function test_impossible_de_retourner_plus_que_la_quantite_recue(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 3);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $this->expectException(LogicException::class);
        app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 4],
        ]);
    }

    public function test_une_ligne_qui_nappartient_pas_a_la_facture_dachat_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchatA = $this->receptionnerAchat($fournisseur, $produit, 3);
        $factureAchatB = $this->receptionnerAchat($fournisseur, $produit, 3);

        $this->expectException(InvalidArgumentException::class);
        app(CreerAvoirFournisseurAction::class)->handle($factureAchatA, [
            ['ligne_achat_id' => $factureAchatB->lignes->first()->id, 'quantite' => 1],
        ]);
    }

    public function test_un_second_avoir_fournisseur_peut_retourner_le_reliquat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 5);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $premierAvoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ]);
        app(EmettreAvoirFournisseurAction::class)->handle($premierAvoir);

        $this->assertEquals(3, $ligneAchat->fresh()->quantiteRetournableRestante());

        $secondAvoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 3],
        ]);
        app(EmettreAvoirFournisseurAction::class)->handle($secondAvoir);

        $this->assertEquals(0, $ligneAchat->fresh()->quantiteRetournableRestante());
    }

    /**
     * Ticket d'achat saisi en TTC (2 x 40 € TTC = 80,00 €) : HT, TVA et TTC
     * retournés retombent pile sur la ligne achetée (66,66 € / 13,34 € /
     * 80,00 €), le dernier retour en prenant le solde exact.
     */
    public function test_le_dernier_retour_fournisseur_dune_ligne_en_credite_le_solde_exact(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20],
        ]));
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $this->assertSame(['66.66', '13.34', '80.00'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);

        $premier = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]));
        $dernier = app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]);

        $this->assertSame(['33.33', '6.67', '40.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['33.33', '6.67', '40.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
    }

    /**
     * Même règle côté fournisseur : 2 x 40 € TTC remisés de 10 % = 59,99 €
     * HT ; le premier article rendu vaut 30,00 € HT, le dernier en prend
     * le solde (29,99 € HT, 6,01 € de TVA).
     */
    public function test_le_dernier_retour_fournisseur_dune_ligne_remisee_en_credite_le_solde_exact(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20, 'remise_pourcentage' => 10],
        ]));
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $this->assertSame(['59.99', '12.01', '72.00'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);

        $premier = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]));
        $dernier = app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]);

        $this->assertSame(['30.00', '6.00', '36.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['29.99', '6.01', '36.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
    }

    public function test_une_quantite_a_retourner_non_entiere_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité à retourner doit être un nombre entier d'articles, au moins 1.");

        app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->firstOrFail()->id, 'quantite' => '1.5'],
        ]);
    }

    public function test_une_meme_ligne_deux_fois_dans_un_avoir_fournisseur_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = $this->receptionnerAchat($fournisseur, $produit, 2);
        $ligneAchatId = $factureAchat->lignes->firstOrFail()->id;

        $this->expectException(InvalidArgumentException::class);

        app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchatId, 'quantite' => 2],
            ['ligne_achat_id' => $ligneAchatId, 'quantite' => 2],
        ]);
    }
}
