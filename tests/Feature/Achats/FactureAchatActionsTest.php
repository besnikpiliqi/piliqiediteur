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
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * Un achat part toujours du PU HT du fournisseur, multiplié par la
     * quantité, la TVA calculée sur ce HT : 8,33 € HT x 3 = 24,99 € HT +
     * 5,00 € de TVA (20 % de 24,99 €). Sans PU HT saisi, c'est le dernier
     * prix d'achat du produit (à défaut son coût moyen).
     */
    public function test_un_achat_part_toujours_du_pu_ht(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $jamaisAchete = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 8.33],
            ['produit_id' => $jamaisAchete->id, 'quantite' => 1],
        ]);

        [$ligneSaisie, $ligneCatalogue] = $factureAchat->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(['8.33', '24.99', '5.00', '29.99'], [$ligneSaisie->prix_unitaire_ht, $ligneSaisie->montant_ht, $ligneSaisie->montant_tva, $ligneSaisie->montant_ttc]);
        $this->assertSame(['5.00', '5.00', '1.00', '6.00'], [$ligneCatalogue->prix_unitaire_ht, $ligneCatalogue->montant_ht, $ligneCatalogue->montant_tva, $ligneCatalogue->montant_ttc]);
    }

    public function test_un_nouveau_produit_sans_pu_ht_est_refuse(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Une ligne d\'achat doit fournir prix_unitaire_ht.'));

        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'taux_tva' => 20], 'quantite' => 1],
        ]);
    }

    /**
     * Facture signalée par le magasin : la TVA d'une ligne au prix HT est
     * 20 % de son HT -- 10 x 1,18 € = 11,80 € HT + 2,36 € de TVA = 14,16 €
     * TTC, et non 10 x 1,42 € (1,18 € x 1,2 = 1,416 € arrondi) = 14,20 €.
     */
    public function test_la_tva_dune_ligne_au_prix_ht_se_calcule_sur_son_total_ht(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $coque = Produit::factory()->create(['taux_tva' => 20]);
        $ecran = Produit::factory()->create(['taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $coque->id, 'quantite' => 10, 'prix_unitaire_ht' => '1.18'],
            ['produit_id' => $ecran->id, 'quantite' => 5, 'prix_unitaire_ht' => '33.65'],
        ]);

        [$coques, $ecrans] = $factureAchat->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(['11.80', '2.36', '14.16'], [$coques->montant_ht, $coques->montant_tva, $coques->montant_ttc]);
        $this->assertSame(['168.25', '33.65', '201.90'], [$ecrans->montant_ht, $ecrans->montant_tva, $ecrans->montant_ttc]);
        $this->assertSame(['180.05', '36.01', '216.06'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);
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
     * sur le prix d'achat remise déduite : 10 € remisés de 50 % = 5 € HT,
     * + 30 € de bénéfice = 35 € HT, soit 42 € TTC (TVA de 7 € comprise).
     */
    public function test_un_nouveau_produit_sans_prix_de_vente_prend_celui_du_bareme(): void
    {
        app(TenantContext::class)->magasin()->update(['prix_vente_automatique' => true]);

        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 10, 'remise_pourcentage' => 50],
        ]);

        $this->assertSame('42.00', Produit::query()->sole()->prix_vente_ttc);
    }

    /**
     * Le barème part du coût exact : 2,35 € remisés de 15 % coûtent
     * 1,9975 €, sous le palier de 2 € -- 10 € de bénéfice, (1,9975 € +
     * 10 €) x 1,2 = 14,397 € -> 14,40 € TTC, et non le palier suivant
     * (20,40 €) qu'un coût arrondi à 2,00 € aurait donné.
     */
    public function test_le_bareme_part_du_cout_exact_sans_larrondir(): void
    {
        app(TenantContext::class)->magasin()->update(['prix_vente_automatique' => true]);

        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Câble', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => '2.35', 'remise_pourcentage' => 15],
        ]);

        $this->assertSame('14.40', Produit::query()->sole()->prix_vente_ttc);
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

    /**
     * L'état du formulaire se forge : corriger la fiche d'un produit depuis
     * une ligne demande le droit de modifier les produits, même à qui peut
     * en créer.
     */
    public function test_sans_droit_de_modifier_les_produits_une_ligne_ne_corrige_pas_leur_fiche(): void
    {
        $this->connecterUnMembreQuiSaisitLesAchats([PermissionRegistry::PRODUIT_CREATE]);
        $produit = Produit::factory()->create(['nom' => 'Écran iPhone 13', 'prix_vente_ttc' => 150, 'taux_tva' => 20]);

        try {
            app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
                ['produit_id' => $produit->id, 'produit' => ['prix_vente_ttc' => '1.00'], 'quantite' => 1, 'prix_unitaire_ht' => 40],
            ]);
            $this->fail('La fiche du produit ne doit pas être corrigée sans le droit de la modifier.');
        } catch (LogicException $exception) {
            $this->assertSame('Vous ne pouvez pas modifier la fiche du produit « Écran iPhone 13 » : seule la ligne se modifie ici.', $exception->getMessage());
        }

        $this->assertSame('150.00', $produit->fresh()->prix_vente_ttc);
        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * Créer un produit depuis une ligne demande le droit de créer des
     * produits, même à qui peut en modifier.
     */
    public function test_sans_droit_de_creer_des_produits_une_ligne_ne_cree_pas_de_produit(): void
    {
        $this->connecterUnMembreQuiSaisitLesAchats([PermissionRegistry::PRODUIT_UPDATE]);

        try {
            app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
                ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 0.47],
            ]);
            $this->fail('Aucun produit ne doit être créé sans le droit d\'en créer.');
        } catch (LogicException $exception) {
            $this->assertSame('Vous ne pouvez pas créer de produit : « Câble USB-C » doit d\'abord être ajouté au catalogue.', $exception->getMessage());
        }

        $this->assertSame(0, Produit::query()->count());
        $this->assertSame(0, FactureAchat::query()->count());
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
     * Le coût d'un article n'est pas arrondi au centime avant le CMUP : 3 x
     * 1,18 € remisés de 15 % coûtent 1,003 € chacun (la ligne vaut 3,01 €
     * HT), pas 1,00 €.
     */
    public function test_le_cmup_retient_le_cout_exact_sans_larrondir_au_centime(): void
    {
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => '1.18', 'remise_pourcentage' => 15],
        ]);

        app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame('1.0030', $produit->fresh()->cmup_ht);
        $this->assertSame('3.01', $factureAchat->fresh()->total_ht);
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

        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
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
        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => 22],
        ]);

        $this->assertSame(8, $factureAchat->lignes->first()->quantite);
        $this->assertSame(11, $produit->fresh()->stock_quantite, '3 (stock avant réception) + 8 (quantité corrigée) = 11.');
        $this->assertSame('21.4545', $produit->fresh()->cmup_ht, '(3 x 20,00 € + 8 x 22,00 €) / 11.');
    }

    /**
     * Corriger une réception dont une partie est déjà vendue ne revalorise
     * que ce qui reste en stock : 10 reçus à 5 €, 9 vendus, puis la
     * réception corrigée à 6 € -- l'article restant vaut 6 €, pas
     * (1 x 5 - 10 x 5 + 10 x 6) / 1 = 15 €. Deux articles de plus reçus
     * entrent au nouveau coût.
     */
    public function test_corriger_une_reception_deja_en_partie_vendue_ne_revalorise_que_le_stock_restant(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 5],
        ]));
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 9);

        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 6],
        ]);
        $this->assertSame(['6.0000', 1], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 12, 'prix_unitaire_ht' => 6],
        ]);
        $this->assertSame(['6.0000', 3], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
    }

    /**
     * Un produit qui ne suit pas son stock coûte ce que lui a coûté son
     * dernier achat : retiré d'une facture corrigée, il reprend le coût de
     * l'achat d'avant.
     */
    public function test_un_produit_sans_suivi_de_stock_retire_d_une_facture_reprend_le_cout_de_son_dernier_achat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $sansSuivi = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $autre = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $sansSuivi->id, 'quantite' => 1, 'prix_unitaire_ht' => 4],
        ]));
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $sansSuivi->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]));
        $this->assertSame('9.0000', $sansSuivi->fresh()->cmup_ht);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertSame('4.0000', $sansSuivi->fresh()->cmup_ht);
    }

    /**
     * Le dernier prix d'achat proposé est celui de la facture enregistrée
     * le plus récemment : corriger une facture plus ancienne n'en fait pas
     * le dernier achat.
     */
    public function test_le_dernier_prix_dachat_est_celui_de_la_facture_la_plus_recente(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 3, 'taux_tva' => 20]);
        $ancienne = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]);
        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
        ]);

        app(ModifierFactureAchatAction::class)->handle($ancienne, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5.5],
        ]);

        $this->assertSame(6.0, $produit->fresh()->dernierPrixDAchatHt());
    }

    public function test_corriger_lescompte_dune_facture_dachat_validee_recalcule_le_cout_dachat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 50],
        ]));

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
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
        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
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
        app(ModifierFactureAchatAction::class)->handle($factureAchat->fresh(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }

    /**
     * Le CMUP d'une réception corrigée se rejoue sur tout le grand livre :
     * 10 reçus à 2 €, puis 10 à 10 € (CMUP 6 €), 10 vendus. La seconde
     * réception corrigée à 5 € donne (10 x 2 + 10 x 5) / 20 = 3,50 € aux 10
     * articles restants ; corrigée ensuite à 1 €, (10 x 2 + 10 x 1) / 20 =
     * 1,50 €.
     */
    public function test_corriger_une_reception_sur_un_stock_deja_melange_rejoue_le_cmup(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 2, 'taux_tva' => 20, 'suivi_stock' => true]);
        $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);
        $seconde = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 10]]);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 10);
        $this->assertSame(['6.0000', 10], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        $seconde = app(ModifierFactureAchatAction::class)->handle($seconde, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 5],
        ]);
        $this->assertSame(['3.5000', 10], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle($seconde, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 1],
        ]);
        $this->assertSame(['1.5000', 10], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
    }

    /**
     * Une marchandise finalement offerte ne coûte rien : 10 reçus à 5 €,
     * puis la réception corrigée à 0 € -- CMUP 0 €, pas l'ancien coût.
     */
    public function test_une_reception_corrigee_a_cout_nul_donne_un_cmup_nul(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 5]]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 0],
        ]);

        $this->assertSame(['0.0000', 10], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
    }

    /**
     * Le dernier achat est celui validé le plus récemment, pas celui de la
     * facture créée la dernière : F1 (8 €) est créée avant F2 (5 €), mais
     * validée après elle. Corrigée à 9 €, F1 reste le dernier achat d'un
     * produit qui ne suit pas son stock.
     */
    public function test_un_produit_sans_suivi_reprend_le_cout_de_l_achat_valide_le_plus_recemment(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $f1 = app(CreerFactureAchatAction::class)->handle($fournisseur, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 8]]);
        $f2 = app(CreerFactureAchatAction::class)->handle($fournisseur, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        app(ValiderFactureAchatAction::class)->handle($f2);
        $this->travel(1)->minute();
        $f1 = app(ValiderFactureAchatAction::class)->handle($f1);
        $this->assertSame('8.0000', $produit->fresh()->cmup_ht);

        app(ModifierFactureAchatAction::class)->handle($f1, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]);

        $this->assertSame('9.0000', $produit->fresh()->cmup_ht);
    }

    /**
     * Ce qu'une réception a fait entrer en stock se lit dans le grand
     * livre et se corrige même quand le produit ne suit plus son stock :
     * 10 reçus, puis corrigés à 5 après que le suivi a été désactivé sur la
     * fiche (le stock suit la facture). Le suivi réactivé, la réception
     * corrigée à 7 en fait entrer 2 de plus.
     */
    public function test_corriger_une_reception_part_de_ce_qu_elle_a_fait_entrer_en_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 4, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 4]]);
        $produit->update(['suivi_stock' => false]);
        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 4],
        ]);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
        $produit->update(['suivi_stock' => true]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 7, 'prix_unitaire_ht' => 4],
        ]);

        $this->assertSame(['4.0000', 7], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
    }

    /**
     * Décocher le suivi depuis la ligne d'une réception corrigée revient au
     * même que le désactiver sur la fiche : ce qu'elle a fait entrer se
     * corrige, et le produit, qui ne suit plus son stock, coûte son dernier
     * achat. 10 reçus à 2 €, puis 10 à 4 € (CMUP 3 €), la seconde réception
     * corrigée à 5 à 6 € sans suivi : 15 en stock à 6 €, pas au CMUP
     * rejoué de (10 x 2 + 5 x 6) / 15 = 3,33 €.
     */
    public function test_decocher_le_suivi_en_corrigeant_une_reception_corrige_son_stock_au_cout_du_dernier_achat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 2, 'taux_tva' => 20, 'suivi_stock' => true]);
        $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);
        $seconde = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 4]]);

        app(ModifierFactureAchatAction::class)->handle($seconde, $fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['suivi_stock' => false], 'quantite' => 5, 'prix_unitaire_ht' => 6],
        ]);

        $produit->refresh();
        $this->assertFalse($produit->suivi_stock);
        $this->assertSame(['6.0000', 15], [$produit->cmup_ht, $produit->stock_quantite]);
        $this->assertSame(
            [['ajustement_sortie', 5, $seconde->id]],
            $produit->mouvementsStock()->whereNot('type', TypeMouvementStock::ReceptionAchat)->get()->map(fn (MouvementStock $mouvement): array => [$mouvement->type->value, $mouvement->quantite, $mouvement->source_id])->all(),
        );
    }

    /**
     * Une réception validée alors que le produit ne suivait pas son stock
     * n'y est jamais entrée : activer le suivi depuis la ligne de sa
     * correction ne fait rien entrer ni sortir (le stock ne tombe pas à
     * -5), seul son coût change.
     */
    public function test_activer_le_suivi_en_corrigeant_une_reception_jamais_entree_en_stock_ne_cree_aucun_ajustement(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 4, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 4]]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['suivi_stock' => true], 'quantite' => 5, 'prix_unitaire_ht' => 3],
        ]);

        $produit->refresh();
        $this->assertTrue($produit->suivi_stock);
        $this->assertSame(['3.0000', 0], [$produit->cmup_ht, $produit->stock_quantite]);
        $this->assertSame(0, $produit->mouvementsStock()->count());
    }

    /**
     * Le grand livre dit seul le sens de chaque mouvement : une réception
     * de 10 corrigée à 5, puis à 15, puis 4 vendus -- la somme des
     * mouvements donne le stock, et chaque correction a pour source la
     * facture d'achat corrigée.
     */
    public function test_le_stock_se_recalcule_depuis_le_grand_livre_apres_chaque_correction(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 4, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 4]]);
        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 4],
        ]);
        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 15, 'prix_unitaire_ht' => 4],
        ]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4);

        $grandLivre = $produit->mouvementsStock()->orderBy('id')->get();
        $this->assertSame(
            [['reception_achat', 10, $factureAchat->id], ['ajustement_sortie', 5, $factureAchat->id], ['ajustement_entree', 10, $factureAchat->id], ['vente', 4, null]],
            $grandLivre->map(fn (MouvementStock $mouvement): array => [$mouvement->type->value, $mouvement->quantite, $mouvement->source_id])->all(),
        );
        $this->assertSame(11, $grandLivre->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite)));
        $this->assertSame(11, $produit->fresh()->stock_quantite);
    }

    /**
     * Un produit ajouté à une réception déjà validée entre en stock à son
     * coût, comme s'il avait été reçu avec elle.
     */
    public function test_un_produit_ajoute_a_une_reception_validee_entre_en_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $recu = Produit::factory()->create(['cmup_ht' => 2, 'taux_tva' => 20, 'suivi_stock' => true]);
        $ajoute = Produit::factory()->create(['cmup_ht' => 9, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2],
            ['produit_id' => $ajoute->id, 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);

        $this->assertSame(['3.0000', 4], [$ajoute->fresh()->cmup_ht, $ajoute->fresh()->stock_quantite]);
        $this->assertSame(['2.0000', 10], [$recu->fresh()->cmup_ht, $recu->fresh()->stock_quantite]);
    }

    /**
     * Un produit ajouté à une réception validée la suit comme une nouvelle
     * réception, avec le suivi du stock que sa ligne lui donne : un produit
     * qui ne suivait pas son stock, coché depuis la ligne, y entre.
     */
    public function test_un_produit_ajoute_dont_la_ligne_active_le_suivi_entre_en_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $recu = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $ajoute = Produit::factory()->create(['cmup_ht' => 9, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2],
            ['produit_id' => $ajoute->id, 'produit' => ['suivi_stock' => true], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);

        $ajoute->refresh();
        $this->assertTrue($ajoute->suivi_stock);
        $this->assertSame(['3.0000', 4], [$ajoute->cmup_ht, $ajoute->stock_quantite]);
        $this->assertSame(
            [['ajustement_entree', 4, $factureAchat->id]],
            $ajoute->mouvementsStock()->get()->map(fn (MouvementStock $mouvement): array => [$mouvement->type->value, $mouvement->quantite, $mouvement->source_id])->all(),
        );
    }

    /**
     * À l'inverse, un produit ajouté dont la ligne décoche le suivi du
     * stock n'y entre pas : il coûte son dernier achat, cette ligne.
     */
    public function test_un_produit_ajoute_dont_la_ligne_desactive_le_suivi_n_entre_pas_en_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $recu = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $ajoute = Produit::factory()->create(['cmup_ht' => 9, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);

        app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2],
            ['produit_id' => $ajoute->id, 'produit' => ['suivi_stock' => false], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);

        $ajoute->refresh();
        $this->assertFalse($ajoute->suivi_stock);
        $this->assertSame(['3.0000', 0], [$ajoute->cmup_ht, $ajoute->stock_quantite]);
        $this->assertSame(0, $ajoute->mouvementsStock()->count());
    }

    /**
     * Le fournisseur d'un produit est celui de sa dernière facture d'achat
     * validée : corriger l'ancienne facture chez A ne le ramène pas chez A
     * tant que la facture chez B reste son dernier achat.
     */
    public function test_corriger_une_ancienne_facture_garde_le_fournisseur_du_dernier_achat(): void
    {
        $ancien = Fournisseur::factory()->create();
        $dernier = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $ancienneFacture = $this->recevoir($ancien, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travel(1)->minute();
        $this->recevoir($dernier, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6]]);

        app(ModifierFactureAchatAction::class)->handle($ancienneFacture, $ancien, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5.5],
        ], ['notes' => 'Prix corrigé']);

        $this->assertSame($dernier->id, $produit->fresh()->fournisseur_id);
    }

    /**
     * Retiré de sa dernière facture d'achat, le produit revient au
     * fournisseur de l'achat validé d'avant ; retiré de son seul achat, il
     * garde son fournisseur.
     */
    public function test_un_produit_retire_de_sa_derniere_facture_reprend_le_fournisseur_de_l_achat_d_avant(): void
    {
        $ancien = Fournisseur::factory()->create();
        $dernier = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $seulAchat = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $autre = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $this->recevoir($ancien, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travel(1)->minute();
        $derniereFacture = $this->recevoir($dernier, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
            ['produit_id' => $seulAchat->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
        ]);

        app(ModifierFactureAchatAction::class)->handle($derniereFacture, $dernier, [
            ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertSame($ancien->id, $produit->fresh()->fournisseur_id);
        $this->assertSame($dernier->id, $seulAchat->fresh()->fournisseur_id);
    }

    /**
     * Un produit archivé depuis la saisie du brouillon ne se reçoit plus
     * en silence : la validation est refusée, avec un message, et rien
     * n'entre en stock.
     */
    public function test_valider_une_facture_dachat_dont_un_produit_est_archive_est_refuse(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $produit->delete();

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle($factureAchat),
            LogicException::class,
            'Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture, avant de la valider.',
        );

        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->fresh()->statut);
        $this->assertSame(0, Produit::withTrashed()->find($produit->id)->stock_quantite);
        $this->assertSame(0, MouvementStock::query()->count());
    }

    /**
     * Un fournisseur archivé depuis la saisie du brouillon ne devient pas
     * en silence celui de ses produits : la validation est refusée, avec un
     * message, sans consommer de numéro ; restauré, il se valide avec le
     * premier numéro.
     */
    public function test_valider_une_facture_dachat_dont_le_fournisseur_est_archive_est_refuse(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $fournisseur->delete();

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle($factureAchat),
            LogicException::class,
            'Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant de valider cette facture d\'achat.',
        );

        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->fresh()->statut, $factureAchat->fresh()->numero]);
        $this->assertSame(0, $produit->fresh()->stock_quantite);

        $fournisseur->restore();
        $validee = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame('FA-'.app(TenantContext::class)->magasin()->code.'-2026-000001', $validee->numero);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
    }

    /**
     * Un nouveau produit est rattaché au fournisseur de la facture dès sa
     * création : archivé depuis la saisie du brouillon, ce fournisseur n'en
     * reçoit pas (message), et aucun produit n'est créé. Le brouillon reste
     * modifiable pour ses produits existants.
     */
    public function test_un_brouillon_dont_le_fournisseur_est_archive_ne_cree_pas_de_nouveau_produit(): void
    {
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $brouillon = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $fournisseur->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle($brouillon, $fournisseur, [
                ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
                ['produit' => ['nom' => 'Câble USB-C', 'reference' => 'CAB-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 4],
            ]),
            LogicException::class,
            'Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant d\'ajouter un nouveau produit à cette facture d\'achat.',
        );

        $this->assertSame(0, Produit::withTrashed()->where('reference', 'CAB-1')->count());
        $this->assertSame([$produit->id], $brouillon->fresh()->lignes->pluck('produit_id')->all());

        app(ModifierFactureAchatAction::class)->handle($brouillon, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 6, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertSame([6, '12.00'], [$brouillon->fresh()->lignes->sole()->quantite, $brouillon->fresh()->total_ht]);
    }

    /**
     * Corriger une réception rattache ses produits au fournisseur de leur
     * dernier achat validé : archivé depuis la validation, ce fournisseur
     * ne le devient pas sans être restauré. La correction est refusée avec
     * un message, sans mouvement de stock ni changement de fournisseur.
     */
    public function test_corriger_une_facture_dachat_validee_dont_le_fournisseur_est_archive_est_refuse(): void
    {
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produitA = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $produitB = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produitA->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        $fournisseur->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
                ['produit_id' => $produitA->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
                ['produit_id' => $produitB->id, 'quantite' => 3, 'prix_unitaire_ht' => 4],
            ]),
            LogicException::class,
            'Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant de corriger cette facture d\'achat.',
        );

        $this->assertSame([$produitA->id], $factureAchat->fresh()->lignes->pluck('produit_id')->all());
        $this->assertSame([5, 0], [$produitA->fresh()->stock_quantite, $produitB->fresh()->stock_quantite]);
        $this->assertNull($produitB->fresh()->fournisseur_id);
        $this->assertSame(1, MouvementStock::query()->count());
    }

    /**
     * Chaque ligne d'achat reçoit un produit (voir ConstructeurLigneAchat),
     * que sa ligne d'avoir fournisseur reprend : la base refuse l'une comme
     * l'autre sans produit.
     */
    public function test_la_base_refuse_une_ligne_d_achat_ou_d_avoir_fournisseur_sans_produit(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir(Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1],
        ]);

        $this->assertThrows(fn () => $factureAchat->lignes->sole()->replicate()->forceFill(['produit_id' => null])->save(), QueryException::class);
        $this->assertThrows(fn () => $avoir->lignes->sole()->replicate()->forceFill(['produit_id' => null])->save(), QueryException::class);

        $this->assertSame([1, 1], [$factureAchat->lignes()->count(), $avoir->lignes()->count()]);
    }

    /**
     * Le produit d'une réception validée archivé depuis : la corriger est
     * refusé avec un message (plus d'erreur de produit introuvable), et la
     * facture reste telle quelle.
     */
    public function test_corriger_une_facture_dachat_validee_dont_un_produit_est_archive_est_refuse(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $autre = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        $produit->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
                ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
            ]),
            LogicException::class,
            'Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue avant de corriger cette facture d\'achat.',
        );

        $this->assertSame([$produit->id], $factureAchat->fresh()->lignes->pluck('produit_id')->all());
        $this->assertSame(0, $autre->fresh()->stock_quantite);
    }

    /**
     * Dans un brouillon, un produit archivé ne se garde pas (message), mais
     * se retire.
     */
    public function test_un_brouillon_ne_garde_pas_un_produit_archive_mais_peut_le_retirer(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20]);
        $autre = Produit::factory()->create(['taux_tva' => 20]);
        $brouillon = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $produit->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle($brouillon, $fournisseur, [
                ['produit_id' => $produit->id, 'quantite' => 6, 'prix_unitaire_ht' => 2],
            ]),
            LogicException::class,
            'Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture.',
        );
        $this->assertSame(5, $brouillon->fresh()->lignes->sole()->quantite);

        app(ModifierFactureAchatAction::class)->handle($brouillon, $fournisseur, [
            ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertSame([$autre->id], $brouillon->fresh()->lignes->pluck('produit_id')->all());
    }

    /**
     * Une vente verrouille le registre fiscal, puis les produits qu'elle
     * sort du stock. Une facture d'achat qui change le taux de TVA d'un
     * produit (JET 270) verrouille donc le registre avant d'écrire la
     * facture ou le moindre produit, même celui que corrige une ligne
     * précédente : sinon l'achat et la vente s'attendraient l'un l'autre.
     */
    public function test_une_facture_dachat_qui_change_un_taux_de_tva_verrouille_d_abord_le_registre_fiscal(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $corrige = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20]);
        $livre = Produit::factory()->create(['reference' => 'LIV-01', 'nom' => 'Livre', 'taux_tva' => 20]);

        $requetes = $this->requetesDe(fn () => app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $corrige->id, 'produit' => ['prix_vente_ttc' => 12], 'quantite' => 1, 'prix_unitaire_ht' => 5],
            ['produit_id' => $livre->id, 'produit' => ['taux_tva' => '5.5'], 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]));

        $verrouDuRegistre = $this->rangDeLaPremiere($requetes, '"tetes_registre_fiscal"');
        $this->assertLessThan($this->rangDeLaPremiere($requetes, 'insert into "factures_achat"'), $verrouDuRegistre);
        $this->assertLessThan($this->rangDeLaPremiere($requetes, 'update "produits"'), $verrouDuRegistre);
        $this->assertSame(['12.00', '5.50'], [$corrige->fresh()->prix_vente_ttc, $livre->fresh()->taux_tva]);
        $this->assertSame([
            'parametre' => 'taux_tva',
            'produit' => ['id' => $livre->id, 'reference' => 'LIV-01', 'nom' => 'Livre'],
            'ancienne_valeur' => '20.00',
            'nouvelle_valeur' => '5.50',
        ], EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ParametreDeConformite->value)->sole()->contenuSigne()['donnees']['details']);
    }

    public function test_corriger_un_taux_de_tva_depuis_une_facture_dachat_verrouille_le_registre_avant_la_facture(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $livre = Produit::factory()->create(['taux_tva' => 20]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $livre->id, 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]);

        $requetes = $this->requetesDe(fn () => app(ModifierFactureAchatAction::class)->handle($factureAchat, $fournisseur, [
            ['produit_id' => $livre->id, 'produit' => ['taux_tva' => '5.5'], 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]));

        $this->assertLessThan($this->rangDeLaPremiere($requetes, '"factures_achat"'), $this->rangDeLaPremiere($requetes, '"tetes_registre_fiscal"'));
        $this->assertSame('5.50', $livre->fresh()->taux_tva);
    }

    /**
     * Le formulaire renvoie le taux de la fiche avec chaque ligne : une
     * facture d'achat qui ne le change pas ne touche pas au registre
     * fiscal, et s'enregistre même quand la caisse sécurisée ne peut rien
     * signer.
     */
    public function test_une_facture_dachat_qui_ne_change_aucun_taux_s_enregistre_sans_le_registre_fiscal(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $livre = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20]);
        $this->retirerLaCleDeSignature();

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $livre->id, 'produit' => ['prix_vente_ttc' => 12, 'taux_tva' => '20'], 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]);

        $this->assertModelExists($factureAchat);
        $this->assertSame('12.00', $livre->fresh()->prix_vente_ttc);
        $this->assertSame(0, EnregistrementFiscal::query()->count());
    }

    public function test_sans_cle_de_signature_une_facture_dachat_qui_change_un_taux_est_refusee_tout_entiere(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $livre = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20]);
        $this->retirerLaCleDeSignature();

        $this->assertThrows(fn () => app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $livre->id, 'produit' => ['prix_vente_ttc' => 12, 'taux_tva' => '5.5'], 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]), ModuleFiscalNonConfigureException::class);

        $this->assertSame(['10.00', '20.00'], [$livre->fresh()->prix_vente_ttc, $livre->fresh()->taux_tva]);
        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * La clé privée de signature disparaît de l'environnement : le
     * registre déjà résolu, qui la garde en mémoire, est oublié.
     */
    private function retirerLaCleDeSignature(): void
    {
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
    }

    /**
     * Les requêtes SQL qu'exécute l'opération, dans l'ordre.
     *
     * @return list<string>
     */
    private function requetesDe(Closure $operation): array
    {
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $operation();

        return $requetes;
    }

    /**
     * Le rang de la première requête qui contient l'extrait.
     *
     * @param  list<string>  $requetes
     */
    private function rangDeLaPremiere(array $requetes, string $extrait): int
    {
        foreach ($requetes as $rang => $requete) {
            if (str_contains($requete, $extrait)) {
                return $rang;
            }
        }

        $this->fail("Aucune requête ne contient {$extrait}.");
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function recevoir(Fournisseur $fournisseur, array $lignes): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, $lignes));
    }

    /**
     * Un observateur à qui le propriétaire a confié la saisie des achats,
     * avec les droits produit donnés.
     *
     * @param  list<string>  $droitsProduit
     */
    private function connecterUnMembreQuiSaisitLesAchats(array $droitsProduit): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Observateur);
        $membre->givePermissionTo([PermissionRegistry::FACTURE_ACHAT_CREER, ...$droitsProduit]);
        $this->actingAs($membre);
    }
}
