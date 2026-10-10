<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
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

    /**
     * Le retour sort du stock au coût réellement payé sur sa ligne, remise
     * et escompte déduits, et recalcule le CMUP du stock restant ; l'avoir,
     * lui, crédite les montants de la ligne achetée. Facture 1 : 8 à
     * 9,00 € HT (CMUP 9 €). Facture 2, escompte 2 % : 12 à 12,50 € HT
     * remisés de 10 %, soit 11,025 € l'article ; CMUP (72 + 132,30) / 20 =
     * 10,215 €. 5 retournés sur la facture 2 : l'avoir crédite 56,25 € HT +
     * 11,25 € de TVA = 67,50 €, et les 15 articles restants valent
     * 204,30 - 55,125 = 149,175 €, soit 9,9450 € chacun.
     */
    public function test_un_retour_sort_du_stock_au_cout_de_sa_ligne_et_recalcule_le_cmup(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => 9],
        ]));
        $this->assertSame('9.0000', $produit->fresh()->cmup_ht);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 12, 'prix_unitaire_ht' => '12.50', 'remise_pourcentage' => 10],
        ], ['escompte_pourcentage' => 2]));
        $this->assertSame('10.2150', $produit->fresh()->cmup_ht);

        $avoir = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 5],
        ], 'Produit défectueux'))->fresh();

        $this->assertSame(['56.25', '11.25', '67.50'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame([15, '9.9450'], [$produit->fresh()->stock_quantite, $produit->fresh()->cmup_ht]);
        $this->assertSame('11.0250', $produit->mouvementsStock()->where('type', TypeMouvementStock::RetourFournisseur)->sole()->cout_unitaire_ht);
    }

    /**
     * Un produit archivé depuis sa réception se retourne toujours au
     * fournisseur, et sort du stock (voir LigneAvoirFournisseur::produit()).
     */
    public function test_un_avoir_fournisseur_sort_du_stock_un_produit_archive_depuis_sa_reception(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchat(Fournisseur::factory()->create(), $produit, 5);
        $produit->delete();

        $avoir = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->firstOrFail()->id, 'quantite' => 2],
        ]));

        $this->assertSame(StatutAvoirFournisseur::Emise, $avoir->statut);
        $this->assertSame(3, $produit->fresh()->stock_quantite);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $produit->id, 'type' => TypeMouvementStock::RetourFournisseur->value, 'quantite' => 2]);
    }

    /**
     * Archivé, un produit garde son stock : le retour reste plafonné à ce
     * qu'il en reste, même quand la ligne d'achat en permettrait plus.
     */
    public function test_le_retour_d_un_produit_archive_reste_plafonne_a_son_stock(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchat(Fournisseur::factory()->create(), $produit, 5);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);
        $produit->delete();

        $this->assertThrows(
            fn () => app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
                ['ligne_achat_id' => $factureAchat->lignes->firstOrFail()->id, 'quantite' => 4],
            ]),
            LogicException::class,
            'La ligne "Câble USB-C" ne peut pas être retournée au-delà de la quantité disponible (3) : elle a déjà été vendue ou déjà retournée.',
        );

        $this->assertSame(0, AvoirFournisseur::query()->count());
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
        $this->assertSame(2, $ligneAchat->fresh()->quantiteRetournableRestante());

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

    /**
     * Deux lignes du même produit (5 + 5 reçus), puis 4 vendus : il en
     * reste 6 en stock. Chaque ligne prise seule pourrait en retourner 5,
     * mais ensemble elles ne retournent pas plus que les 6 en stock --
     * sinon des articles déjà vendus seraient « retournés » et le stock
     * tomberait à -4.
     */
    public function test_deux_lignes_du_meme_produit_ne_retournent_pas_ensemble_plus_que_le_stock(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoirEnDeuxLignes($produit);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4);
        [$premiere, $seconde] = $factureAchat->lignes->all();

        $this->assertThrows(
            fn () => app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
                ['ligne_achat_id' => $premiere->id, 'quantite' => 5],
                ['ligne_achat_id' => $seconde->id, 'quantite' => 5],
            ]),
            LogicException::class,
            '« Câble USB-C » : ces lignes en retournent 10 au total, mais il n\'en reste que 6 en stock (les autres ont déjà été vendus ou retournés).',
        );

        $this->assertSame(0, AvoirFournisseur::query()->count());
        $this->assertSame(6, $produit->fresh()->stock_quantite);
    }

    /**
     * Le stock change entre la création de l'avoir et son émission : 3 + 3
     * retours acceptés sur 6 en stock, puis 2 de plus vendus -- l'émission
     * est refusée et rien ne sort du stock.
     */
    public function test_le_total_retourne_par_produit_est_reverifie_a_l_emission(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoirEnDeuxLignes($produit);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4);
        [$premiere, $seconde] = $factureAchat->lignes->all();
        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $premiere->id, 'quantite' => 3],
            ['ligne_achat_id' => $seconde->id, 'quantite' => 3],
        ]);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);

        $this->assertThrows(
            fn () => app(EmettreAvoirFournisseurAction::class)->handle($avoir),
            LogicException::class,
            '« Câble USB-C » : ces lignes en retournent 6 au total, mais il n\'en reste que 4 en stock (les autres ont déjà été vendus ou retournés).',
        );

        $this->assertSame(StatutAvoirFournisseur::Brouillon, $avoir->fresh()->statut);
        $this->assertSame(4, $produit->fresh()->stock_quantite);
    }

    private function recevoirEnDeuxLignes(Produit $produit): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
        ]));
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

        $this->assertSame(3, $ligneAchat->fresh()->quantiteRetournableRestante());

        $secondAvoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 3],
        ]);
        app(EmettreAvoirFournisseurAction::class)->handle($secondAvoir);

        $this->assertSame(0, $ligneAchat->fresh()->quantiteRetournableRestante());
    }

    private function acheter(float $prixUnitaireHt, int $quantite, float $remisePourcentage = 0): FactureAchat
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'remise_pourcentage' => $remisePourcentage],
        ]));
    }

    /**
     * 10 x 0,13 € HT (1,30 € + 0,26 € de TVA) retournés un par un : chaque
     * retour vaut la ligne calculée pour tout ce qui a été rendu moins les
     * retours précédents -- 0,02 € ou 0,03 € de TVA, jamais -0,01 € au
     * dernier -- et leur somme retombe exactement sur la ligne achetée.
     */
    public function test_des_retours_fournisseur_un_par_un_retombent_exactement_sur_la_ligne(): void
    {
        $factureAchat = $this->acheter(0.13, 10);
        $ligneAchat = $factureAchat->lignes->firstOrFail();
        $tvaParRetour = [];

        for ($retour = 1; $retour <= 10; $retour++) {
            $avoir = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
                ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
            ]));
            $tvaParRetour[] = $avoir->total_tva;
        }

        $this->assertSame(['0.03', '0.02', '0.03', '0.02', '0.03', '0.03', '0.02', '0.03', '0.02', '0.03'], $tvaParRetour);
        $this->assertSame(['1.30', '0.26', '1.56'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);
    }

    /**
     * 2 x 33,33 € HT remisés de 10 % = 59,99 € HT (6,666 € de remise ->
     * 6,67 €) + 12,00 € de TVA ; le premier article rendu vaut 30,00 € HT
     * + 6,00 € de TVA, le second le reste de la ligne : 29,99 € HT + 6,00 €.
     */
    public function test_le_second_retour_fournisseur_dune_ligne_remisee_vaut_le_reste_de_la_ligne(): void
    {
        $factureAchat = $this->acheter(33.33, 2, 10);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $this->assertSame(['59.99', '12.00', '71.99'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);

        $premier = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]));
        $second = app(EmettreAvoirFournisseurAction::class)->handle(app(CreerAvoirFournisseurAction::class)->handle($factureAchat->fresh(), [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]));

        $this->assertSame(['30.00', '6.00', '36.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['29.99', '6.00', '35.99'], [$second->total_ht, $second->total_tva, $second->total_ttc]);
    }

    /**
     * Deux retours saisis en même temps (deux brouillons) : leurs montants
     * sont arrêtés à l'émission, sur les retours déjà émis -- le second
     * prend le reste de la ligne (0,23 € de TVA), jamais un centime de
     * plus que la facture d'achat.
     */
    public function test_deux_brouillons_simultanes_sont_arretes_a_lemission(): void
    {
        $factureAchat = $this->acheter(1.18, 2);
        $ligneAchat = $factureAchat->lignes->firstOrFail();
        $selection = [['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1]];

        $brouillonA = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, $selection);
        $brouillonB = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, $selection);

        $avoirA = app(EmettreAvoirFournisseurAction::class)->handle($brouillonA);
        $avoirB = app(EmettreAvoirFournisseurAction::class)->handle($brouillonB);

        $this->assertSame(['2.36', '0.47', '2.83'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);
        $this->assertSame(['1.18', '0.24', '1.42'], [$avoirA->total_ht, $avoirA->total_tva, $avoirA->total_ttc]);
        $this->assertSame(['1.18', '0.23', '1.41'], [$avoirB->total_ht, $avoirB->total_tva, $avoirB->total_ttc]);
        $this->assertSame(['1.18', '0.23', '1.41'], [$avoirB->lignes->sole()->montant_ht, $avoirB->lignes->sole()->montant_tva, $avoirB->lignes->sole()->montant_ttc]);
    }

    public function test_un_brouillon_dont_la_ligne_a_ete_retournee_entre_temps_ne_peut_pas_etre_emis(): void
    {
        $factureAchat = $this->acheter(1.18, 1);
        $selection = [['ligne_achat_id' => $factureAchat->lignes->firstOrFail()->id, 'quantite' => 1]];

        $brouillonA = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, $selection);
        $brouillonB = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, $selection);
        app(EmettreAvoirFournisseurAction::class)->handle($brouillonA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a été retournée ou vendue entre-temps : il ne reste que 0 article(s) à retourner.');

        app(EmettreAvoirFournisseurAction::class)->handle($brouillonB);
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
