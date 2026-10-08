<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class FactureAchatActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_creer_une_facture_dachat_calcule_les_lignes_et_les_totaux(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);

        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->statut);
        $this->assertNull($factureAchat->numero);
        $this->assertSame('100.00', $factureAchat->total_ht);
        $this->assertSame('120.00', $factureAchat->total_ttc);
        $this->assertSame(0, $produit->fresh()->stock_quantite, 'Un brouillon ne doit avoir aucun impact sur le stock.');
    }

    /**
     * Un fournisseur facture le plus souvent en HT, mais un ticket de
     * magasin se saisit en TTC : chaque ligne est calculée depuis le prix
     * réellement saisi (8,33 € HT x 3 = 24,99 € HT ; 12 € TTC x 3 = 36,00 €
     * TTC), jamais en reconvertissant l'un dans l'autre avant de multiplier.
     */
    public function test_chaque_ligne_dachat_est_calculee_depuis_le_prix_reellement_saisi(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $jamaisAchete = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 8.33],
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ttc' => 12],
            ['produit_id' => $jamaisAchete->id, 'quantite' => 1],
        ]);

        [$ligneHt, $ligneTtc, $ligneCatalogue] = $factureAchat->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(PrixReference::Ht, $ligneHt->prix_reference);
        $this->assertSame(['24.99', '5.01', '30.00'], [$ligneHt->montant_ht, $ligneHt->montant_tva, $ligneHt->montant_ttc]);

        $this->assertSame(PrixReference::Ttc, $ligneTtc->prix_reference);
        $this->assertSame(['30.00', '6.00', '36.00'], [$ligneTtc->montant_ht, $ligneTtc->montant_tva, $ligneTtc->montant_ttc]);
        $this->assertSame('10.00', $ligneTtc->prix_unitaire_ht);

        $this->assertSame(PrixReference::Ht, $ligneCatalogue->prix_reference);
        $this->assertSame('5.00', $ligneCatalogue->prix_unitaire_ht);
        $this->assertSame('6.00', $ligneCatalogue->prix_unitaire_ttc);
    }

    public function test_une_ligne_sans_produit_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['quantite' => 5],
        ]);
    }

    /**
     * Le produit naît avec la facture, dans la même transaction, rattaché à
     * son fournisseur : son prix d'achat est celui de la ligne, son prix de
     * vente celui saisi pour la vente -- jamais l'un tiré de l'autre.
     */
    public function test_une_ligne_cree_son_nouveau_produit_avec_la_facture(): void
    {
        $fournisseur = Fournisseur::factory()->create();

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            [
                'produit' => ['nom' => 'Câble USB-C', 'reference' => 'CBL-USBC', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 2],
                'quantite' => 3,
                'prix_unitaire_ht' => 0.47,
            ],
        ]);

        $produit = Produit::query()->sole();
        $this->assertSame(['Câble USB-C', 'CBL-USBC', $fournisseur->id], [$produit->nom, $produit->reference, $produit->fournisseur_id]);
        $this->assertSame(['0.4700', '10.00', '8.33'], [$produit->cmup_ht, $produit->prix_vente_ttc, $produit->prix_vente_ht]);
        $this->assertSame(0, $produit->stock_quantite, 'Un brouillon ne doit avoir aucun impact sur le stock.');

        $ligne = $factureAchat->lignes->sole();
        $this->assertSame([$produit->id, 'Câble USB-C', '0.47', '1.41'], [$ligne->produit_id, $ligne->description, $ligne->prix_unitaire_ht, $ligne->montant_ht]);
    }

    /**
     * Un produit reçu sans prix de vente (une facture récupérée par API, par
     * exemple) prend celui du barème du magasin s'il l'a activé, calculé
     * sur le prix d'achat remise déduite : 10 € remisés de 50 % = 5 €,
     * + 30 € de bénéfice, + 20 % de TVA = 42 € TTC.
     */
    public function test_un_nouveau_produit_sans_prix_de_vente_prend_celui_du_bareme(): void
    {
        app(TenantContext::class)->magasin()->update(['prix_vente_automatique' => true]);

        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 10, 'remise_pourcentage' => 50],
        ]);

        $this->assertSame('42.00', Produit::query()->sole()->prix_vente_ttc);
    }

    public function test_une_facture_dachat_refusee_ne_laisse_aucun_produit_derriere_elle(): void
    {
        $fournisseur = Fournisseur::factory()->create();

        try {
            app(CreerFactureAchatAction::class)->handle($fournisseur, [
                ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 0.47],
                ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 0, 'prix_unitaire_ht' => 2],
            ]);
            $this->fail('Une quantité nulle doit être refusée.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Produit::query()->count());
            $this->assertSame(0, FactureAchat::query()->count());
        }
    }

    /**
     * La fiche d'un produit se corrige depuis sa facture d'achat ; un champ
     * obligatoire laissé vide garde sa valeur.
     */
    public function test_une_ligne_corrige_la_fiche_de_son_produit(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Ecran', 'reference' => 'ECR-1', 'prix_vente_ttc' => 100, 'taux_tva' => 20]);

        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Écran OLED', 'reference' => null, 'prix_vente_ttc' => 120], 'quantite' => 1, 'prix_unitaire_ht' => 40],
        ]);

        $produit->refresh();
        $this->assertSame(['Écran OLED', 'ECR-1', '120.00'], [$produit->nom, $produit->reference, $produit->prix_vente_ttc]);
    }

    /**
     * Ce que le fournisseur dit d'un produit reçu par API ne se réécrit pas
     * localement, seulement par la source elle-même (voir
     * Produit::vientDUneFactureAchatApi()) ; son prix de vente, lui, reste
     * celui du magasin.
     */
    public function test_la_fiche_dun_produit_recu_par_api_garde_ce_que_son_fournisseur_en_dit(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Ecran', 'prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(CreerFactureAchatAction::class)->handle($fournisseur, [['produit_id' => $produit->id, 'quantite' => 1]], ['source' => SourceFactureAchat::Api]);

        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Ecran', 'prix_vente_ttc' => 110], 'quantite' => 1],
        ]);
        $this->assertSame('110.00', $produit->fresh()->prix_vente_ttc);

        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Écran (nom du fournisseur)'], 'quantite' => 1],
        ], ['source' => SourceFactureAchat::Api]);
        $this->assertSame('Écran (nom du fournisseur)', $produit->fresh()->nom, 'La source elle-même peut encore corriger le produit.');

        $this->expectExceptionObject(new LogicException('Le produit « Écran (nom du fournisseur) » vient d\'une facture d\'achat récupérée automatiquement : ses informations fournisseur ne se modifient pas.'));

        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Écran renommé'], 'quantite' => 1],
        ]);
    }

    public function test_une_quantite_achetee_non_entiere_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 10, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité doit être un nombre entier d'articles, au moins 1.");

        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => '2.5'],
        ]);
    }

    public function test_valider_une_facture_dachat_lui_attribue_un_numero_et_incremente_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->statut);
        $this->assertNotNull($factureAchat->numero);
        $this->assertNotNull($factureAchat->date_validation);
        $this->assertSame(8, $produit->fresh()->stock_quantite);
    }

    /**
     * Le stock est valorisé au coût moyen unitaire pondéré : 10 articles à
     * 5,00 € en stock, puis 10 reçus à 7,00 € et 20 à 7,60 €, valent
     * (10 x 5 + 10 x 7 + 20 x 7,60) / 40 = 6,80 € l'article.
     */
    public function test_chaque_reception_recalcule_le_cout_moyen_pondere_du_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);

        app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 7],
        ]));
        $this->assertSame('6.0000', $produit->fresh()->cmup_ht);

        app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 7.6],
            ['produit_id' => $produit->id, 'quantite' => 15, 'prix_unitaire_ht' => 7.6],
        ]));

        $this->assertSame(['6.8000', 40], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
    }

    /**
     * Un stock vide (ou négatif, vendu avant d'être reçu) ne pèse rien : le
     * CMUP repart du coût de la réception. Un produit qui ne suit pas son
     * stock n'a pas de quantités à pondérer : il garde le coût de son
     * dernier achat.
     */
    public function test_sans_stock_a_ponderer_le_cmup_est_le_cout_de_la_reception(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $venduAvantDEtreRecu = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20, 'stock_quantite' => -2, 'suivi_stock' => true]);
        $sansSuiviDeStock = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20, 'suivi_stock' => false]);

        app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $venduAvantDEtreRecu->id, 'quantite' => 4, 'prix_unitaire_ht' => 8],
            ['produit_id' => $sansSuiviDeStock->id, 'quantite' => 3, 'prix_unitaire_ht' => 9],
        ]));

        $this->assertSame(['8.0000', 2], [$venduAvantDEtreRecu->fresh()->cmup_ht, $venduAvantDEtreRecu->fresh()->stock_quantite]);
        $this->assertSame('9.0000', $sansSuiviDeStock->fresh()->cmup_ht);
    }

    /**
     * Le fournisseur d'un produit est celui chez qui il vient d'être acheté,
     * comme son prix d'achat.
     */
    public function test_valider_une_facture_dachat_rattache_le_produit_a_son_fournisseur(): void
    {
        $ancienFournisseur = Fournisseur::factory()->create();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['fournisseur_id' => $ancienFournisseur->id, 'cmup_ht' => 20, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $this->assertSame($ancienFournisseur->id, $produit->fresh()->fournisseur_id, 'Un brouillon ne change rien au produit.');

        app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame($fournisseur->id, $produit->fresh()->fournisseur_id);
    }

    /**
     * Le coût d'achat d'un stock se compte après remises et escomptes (PCG
     * art. 213-31) : 100 € remisés de 10 % coûtent 90 €.
     */
    public function test_valider_une_facture_dachat_remisee_retient_le_cout_apres_remise(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 100, 'remise_pourcentage' => 10],
        ]);

        app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame('90.0000', $produit->fresh()->cmup_ht);
    }

    /**
     * L'escompte pour paiement rapide baisse encore le coût de chaque
     * article (90 € moins 2 % = 88,20 €), mais pas les totaux de la
     * facture, qui restent ceux de ses lignes.
     */
    public function test_lescompte_de_la_facture_baisse_le_cout_dachat_sans_changer_ses_totaux(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 100, 'remise_pourcentage' => 10],
        ], ['escompte_pourcentage' => 2]);

        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame('88.2000', $produit->fresh()->cmup_ht);
        $this->assertSame('270.00', $factureAchat->fresh()->total_ht);
    }

    public function test_un_escompte_hors_de_0_a_100_pourcent_est_refuse(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        $this->expectExceptionObject(new InvalidArgumentException('L\'escompte doit être compris entre 0 et 100 %.'));

        try {
            app(CreerFactureAchatAction::class)->handle($fournisseur, [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ], ['escompte_pourcentage' => 120]);
        } finally {
            $this->assertSame(0, FactureAchat::query()->count());
        }
    }

    public function test_impossible_de_valider_deux_fois_la_meme_facture_dachat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->expectException(LogicException::class);
        app(ValiderFactureAchatAction::class)->handle($factureAchat->fresh());
    }

    public function test_un_brouillon_reste_modifiable_lignes_comprises(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produitInitial = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $nouveauProduit = Produit::factory()->create(['cmup_ht' => 10, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produitInitial->id, 'quantite' => 5],
        ]);

        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $nouveauProduit->id, 'quantite' => 2],
        ]);

        $this->assertSame(1, $factureAchat->lignes()->count());
        $this->assertSame($nouveauProduit->id, $factureAchat->lignes->first()->produit_id);
        $this->assertSame('20.00', $factureAchat->total_ht);
    }

    /**
     * Exigence explicite : une facture d'achat saisie manuellement reste
     * modifiable même après validation (la personne qui l'enregistre peut
     * se tromper), et la modification recalcule le stock et le CMUP du
     * produit plutôt que de réappliquer la réception en aveugle : la
     * réception corrigée remplace l'ancienne dans la valeur du stock.
     */
    public function test_une_facture_dachat_validee_saisie_manuellement_reste_modifiable_et_recalcule_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame(SourceFactureAchat::Manuelle, $factureAchat->source);
        $this->assertTrue($factureAchat->estModifiable());
        $this->assertSame(8, $produit->fresh()->stock_quantite);

        // Correction d'une erreur de saisie : 5 reçus en réalité, pas 8.
        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => 22],
        ]);

        $this->assertSame(8, $factureAchat->lignes->first()->quantite);
        $this->assertSame(11, $produit->fresh()->stock_quantite, '3 (stock avant réception) + 8 (quantité corrigée) = 11.');
        $this->assertSame('21.4545', $produit->fresh()->cmup_ht, '(3 x 20,00 € + 8 x 22,00 €) / 11.');
    }

    public function test_corriger_lescompte_dune_facture_dachat_validee_recalcule_le_cout_dachat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 50],
        ]));

        app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 50],
        ], ['escompte_pourcentage' => 3]);

        $this->assertSame('48.5000', $produit->fresh()->cmup_ht);
    }

    public function test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ], ['source' => SourceFactureAchat::Api]);

        $this->assertFalse($factureAchat->estModifiable(), 'Même en brouillon, une facture API ne doit jamais être modifiable.');

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }

    public function test_une_facture_dachat_deja_retournee_au_fournisseur_nest_plus_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ], 'Produit défectueux');
        app(EmettreAvoirFournisseurAction::class)->handle($avoir);

        $this->assertFalse($factureAchat->fresh()->estModifiable());

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle($factureAchat->fresh(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }
}
