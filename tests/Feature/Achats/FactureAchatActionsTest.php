<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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
        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['quantite' => 5],
        ]);
    }

    /**
     * Un brouillon ne crée aucun produit : sa ligne garde seulement la fiche
     * du nouveau produit, et se calcule comme toute ligne d'achat -- 3 x
     * 0,47 € HT = 1,41 € HT + 0,28 € de TVA (20 % de 1,41 €) = 1,69 € TTC.
     */
    public function test_un_brouillon_ne_cree_aucun_produit(): void
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [$this->ligneDuCableUsbC()]);

        $this->assertSame(0, Produit::withTrashed()->count());
        $ligne = $factureAchat->lignes->sole();
        $this->assertNull($ligne->produit_id);
        $this->assertSame(['Câble USB-C', 'CBL-USBC', '0.47', '1.41', '0.28', '1.69'], [$ligne->description, $ligne->reference, $ligne->prix_unitaire_ht, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
        $this->assertSame(
            ['reference' => 'CBL-USBC', 'nom' => 'Câble USB-C', 'prix_vente_ttc' => '10.00', 'taux_tva' => '20.00', 'suivi_stock' => true, 'seuil_alerte' => 2],
            $ligne->nouveau_produit,
        );
    }

    /**
     * Le produit naît à la validation, rattaché au fournisseur de la
     * facture : son coût est celui de la ligne, son prix de vente celui de
     * sa fiche -- jamais l'un tiré de l'autre -- et il entre en stock avec
     * elle. La ligne reçoit alors le produit.
     */
    public function test_la_validation_cree_le_nouveau_produit_chez_le_fournisseur_de_la_facture(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [$this->ligneDuCableUsbC()]);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $produit = Produit::query()->sole();
        $this->assertSame(['Câble USB-C', 'CBL-USBC', $fournisseur->id, 2], [$produit->nom, $produit->reference, $produit->fournisseur_id, $produit->seuil_alerte]);
        $this->assertSame(['0.4700', '10.00', 3], [$produit->cmup_ht, $produit->prix_vente_ttc, $produit->stock_quantite]);
        $ligne = $factureAchat->lignes()->sole();
        $this->assertSame([$produit->id, 'CBL-USBC', null], [$ligne->produit_id, $ligne->reference, $ligne->nouveau_produit]);
    }

    /**
     * Un brouillon supprimé ne laisse derrière lui ni produit ni référence
     * automatique consommée.
     */
    public function test_un_brouillon_supprime_ne_laisse_aucun_produit(): void
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 2, 'prix_unitaire_ht' => 2],
        ]);

        $factureAchat->delete();

        $this->assertSame([0, 0], [Produit::withTrashed()->count(), LigneAchat::query()->count()]);
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', app(TenantContext::class)->id())->exists());
    }

    /**
     * Un brouillon lu avant sa validation par une autre requête (deux
     * onglets, une liste restée ouverte) ne se supprime plus une fois
     * validé, ni par son action ni par le modèle : la facture d'achat
     * validée garde ses lignes, son numéro, sa réception en stock et le
     * produit qu'elle a créé.
     */
    public function test_un_brouillon_lu_avant_sa_validation_ne_se_supprime_plus_une_fois_valide(): void
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 2, 'prix_unitaire_ht' => 2],
        ]);
        $copie = FactureAchat::query()->findOrFail($factureAchat->id);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertThrows(fn () => app(SupprimerFactureAchatAction::class)->handle(self::auteur(), $copie), LogicException::class, FactureAchat::REFUS_VALIDEE);
        $this->assertThrows(fn () => $copie->delete(), LogicException::class, FactureAchat::REFUS_VALIDEE);
        $validee = FactureAchat::query()->findOrFail($factureAchat->id);
        $this->assertSame(StatutFactureAchat::Validee, $validee->statut);
        $this->assertNotNull($validee->numero);
        $this->assertSame(1, LigneAchat::query()->where('facture_achat_id', $validee->id)->count());
        $this->assertSame(2, Produit::query()->where('nom', 'Coque')->sole()->stock_quantite);
    }

    /**
     * Un brouillon déjà supprimé par une autre requête ne se supprime pas
     * une seconde fois, ni ne se modifie ni ne se valide : chaque geste le
     * dit, au lieu d'une page introuvable.
     */
    public function test_un_brouillon_deja_supprime_est_refuse_par_chaque_geste(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 2, 'prix_unitaire_ht' => 2],
        ]);
        $copie = FactureAchat::query()->findOrFail($factureAchat->id);

        app(SupprimerFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertThrows(fn () => app(SupprimerFactureAchatAction::class)->handle(self::auteur(), $copie), LogicException::class, FactureAchat::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => $copie->delete(), LogicException::class, FactureAchat::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $copie, $fournisseur, [['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 2]]), LogicException::class, FactureAchat::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $copie), LogicException::class, FactureAchat::REFUS_INTROUVABLE);
        $this->assertSame([0, 0, 0], [FactureAchat::query()->count(), Produit::withTrashed()->count(), MouvementStock::query()->count()]);
    }

    /**
     * Un produit reçu sans prix de vente (une facture récupérée par API, par
     * exemple) prend celui du barème du magasin s'il l'a activé, calculé
     * sur le prix d'achat remise déduite : 10 € remisés de 50 % = 5 € HT,
     * + 30 € de bénéfice = 35 € HT, soit 42 € TTC (TVA de 7 € comprise).
     * Le brouillon le garde dans la fiche du nouveau produit, qui le prend
     * à sa validation.
     */
    public function test_un_nouveau_produit_sans_prix_de_vente_prend_celui_du_bareme(): void
    {
        app(TenantContext::class)->magasin()->update(['prix_vente_automatique' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 10, 'remise_pourcentage' => 50],
        ]);
        $this->assertSame('42.00', $factureAchat->lignes->sole()->nouveau_produit['prix_vente_ttc']);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Câble', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => '2.35', 'remise_pourcentage' => 15],
        ]);
        $this->assertSame('14.40', $factureAchat->lignes->sole()->nouveau_produit['prix_vente_ttc']);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertSame('14.40', Produit::query()->sole()->prix_vente_ttc);
    }

    /**
     * Sans prix de vente ni barème qui en prévoie un, le nouveau produit ne
     * naît pas : la validation est refusée, avec un message, sans créer de
     * produit ni consommer de numéro ; la facture reste un brouillon.
     */
    public function test_un_nouveau_produit_sans_prix_de_vente_bloque_la_validation(): void
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 10],
        ]);

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat),
            LogicException::class,
            'Indiquez le prix de vente TTC du nouveau produit « Coque » avant de valider la facture d\'achat.',
        );

        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->fresh()->statut, $factureAchat->fresh()->numero]);
        $this->assertSame(0, Produit::withTrashed()->count());
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', app(TenantContext::class)->id())->exists());
    }

    /**
     * La référence d'un nouveau produit n'est prise qu'à sa validation :
     * un autre produit qui la prend entre-temps (quelle que soit la casse)
     * fait refuser la validation, avec un message, et rien n'est créé.
     */
    public function test_une_reference_prise_entre_temps_bloque_la_validation(): void
    {
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Câble', 'reference' => 'CBL-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 1],
        ]);
        $produitDeLEntreTemps = Produit::factory()->create(['reference' => 'cbl-1']);

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat),
            InvalidArgumentException::class,
            'La référence « CBL-1 » est déjà celle d\'un autre produit.',
        );

        $this->assertSame([$produitDeLEntreTemps->id], Produit::withTrashed()->pluck('id')->all());
        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->fresh()->statut, $factureAchat->lignes()->sole()->produit_id]);
    }

    /**
     * Une référence déjà prise par un produit du magasin, archivé compris,
     * est refusée dès l'enregistrement du brouillon.
     */
    public function test_un_nouveau_produit_ne_reprend_pas_une_reference_deja_prise(): void
    {
        Produit::factory()->trashed()->create(['reference' => 'CBL-1']);

        $this->assertThrows(
            fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
                ['produit' => ['nom' => 'Câble', 'reference' => 'cbl-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 1],
            ]),
            InvalidArgumentException::class,
            'La référence « cbl-1 » est déjà celle d\'un autre produit.',
        );

        $this->assertSame(0, FactureAchat::query()->count());
    }

    public function test_deux_nouveaux_produits_d_une_facture_ne_portent_pas_la_meme_reference(): void
    {
        $this->assertThrows(
            fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
                ['produit' => ['nom' => 'Câble noir', 'reference' => 'CBL-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 1],
                ['produit' => ['nom' => 'Câble blanc', 'reference' => 'cbl-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 1],
            ]),
            InvalidArgumentException::class,
            'Deux nouveaux produits de cette facture d\'achat ont la même référence « cbl-1 ».',
        );

        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * Un nouveau produit ne prend pas la référence qu'une autre ligne de la
     * facture donne à un produit existant, quel que soit l'ordre des
     * lignes : le brouillon est refusé tout entier et la fiche du produit
     * existant reste intacte -- sinon il serait accepté pour être refusé à
     * la validation.
     */
    #[DataProvider('ordresDesLignes')]
    public function test_un_nouveau_produit_ne_prend_pas_la_reference_donnee_a_un_produit_existant_de_la_facture(bool $nouveauProduitDAbord): void
    {
        $existant = Produit::factory()->create(['reference' => 'OLD-1', 'taux_tva' => 20]);
        $lignes = [
            ['produit' => ['nom' => 'Coque', 'reference' => 'X-1', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 4, 'prix_unitaire_ht' => 3],
            ['produit_id' => $existant->id, 'produit' => ['reference' => 'x-1'], 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ];

        $this->assertThrows(
            fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), $nouveauProduitDAbord ? $lignes : array_reverse($lignes)),
            InvalidArgumentException::class,
            'La référence « X-1 » est déjà celle d\'un autre produit.',
        );

        $this->assertSame('OLD-1', $existant->fresh()->reference);
        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function ordresDesLignes(): array
    {
        return [
            'nouveau produit d\'abord' => [true],
            'produit existant d\'abord' => [false],
        ];
    }

    /**
     * Les références automatiques (PRD-000001...) se prennent dans l'ordre
     * des validations, pas dans celui des brouillons.
     */
    public function test_les_references_automatiques_suivent_l_ordre_des_validations(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $premierBrouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);
        $secondBrouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Câble', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 1],
        ]);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $secondBrouillon);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $premierBrouillon);

        $this->assertSame(
            ['PRD-000001', 'PRD-000002'],
            [Produit::query()->where('nom', 'Câble')->value('reference'), Produit::query()->where('nom', 'Coque')->value('reference')],
        );
    }

    public function test_une_facture_dachat_refusee_ne_laisse_aucun_produit_derriere_elle(): void
    {
        $fournisseur = Fournisseur::factory()->create();

        try {
            app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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
        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 1]], ['source' => SourceFactureAchat::Api]);

        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Ecran', 'prix_vente_ttc' => 110], 'quantite' => 1],
        ]);
        $this->assertSame('110.00', $produit->fresh()->prix_vente_ttc);

        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'produit' => ['nom' => 'Écran (nom du fournisseur)'], 'quantite' => 1],
        ], ['source' => SourceFactureAchat::Api]);
        $this->assertSame('Écran (nom du fournisseur)', $produit->fresh()->nom, 'La source elle-même peut encore corriger le produit.');

        $this->expectExceptionObject(new LogicException('Le produit « Écran (nom du fournisseur) » vient d\'une facture d\'achat récupérée automatiquement : ses informations fournisseur ne se modifient pas.'));

        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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
            app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
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
            app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
                ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 0.47],
            ]);
            $this->fail('Aucun produit ne doit être créé sans le droit d\'en créer.');
        } catch (LogicException $exception) {
            $this->assertSame('Vous ne pouvez pas créer de produit : « Câble USB-C » ne peut être saisi que par une personne autorisée à créer des produits.', $exception->getMessage());
        }

        $this->assertSame(0, Produit::query()->count());
        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * Un membre qui ne peut pas créer de produits corrige la quantité d'une
     * autre ligne du brouillon où le propriétaire a saisi un nouveau
     * produit : la fiche gardée telle quelle ne redemande pas le droit de
     * créer. Valider ce brouillon crée la Coque : sans ce droit, sa
     * validation est refusée, sans rien créer ni valider (complément 3) ;
     * le propriétaire le valide, et la Coque naît alors.
     * 2 x 2,00 € + 4 x 3,00 € = 16,00 € HT + 3,20 € de TVA = 19,20 € TTC.
     */
    public function test_sans_droit_de_creer_des_produits_un_membre_corrige_le_brouillon_qui_garde_le_nouveau_produit_d_un_collegue_sans_pouvoir_le_valider(): void
    {
        [$fournisseur, $brouillon, $lignes, $existant] = $this->brouillonDuProprietaireAvecUnNouveauProduitPuisUnMembreSansDroitDeCreer();
        $lignes[0]['quantite'] = 2;

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $brouillon, $fournisseur, $lignes);
        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $brouillon),
            AuthorizationException::class,
            'Valider cette facture crée de nouveaux produits : il faut aussi le droit de créer des produits.',
        );
        $this->assertSame([StatutFactureAchat::Brouillon, null, 0], [$brouillon->fresh()->statut, $brouillon->fresh()->numero, $existant->fresh()->stock_quantite]);
        $this->assertSame(0, Produit::query()->where('nom', 'Coque')->count());

        app(ValiderFactureAchatAction::class)->handle($this->proprietaireDe($brouillon->magasin), $brouillon);

        $coque = Produit::query()->where('nom', 'Coque')->sole();
        $this->assertSame([$fournisseur->id, '3.0000', '15.00', 4], [$coque->fournisseur_id, $coque->cmup_ht, $coque->prix_vente_ttc, $coque->stock_quantite]);
        $this->assertSame(2, $existant->fresh()->stock_quantite);
        $brouillon->refresh();
        $this->assertSame(['16.00', '3.20', '19.20'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    /**
     * Sans le droit de créer des produits, changer la fiche du nouveau
     * produit qu'un collègue a saisi, ou la recopier sur une seconde ligne,
     * est refusé : le brouillon reste tel quel.
     *
     * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $modification
     */
    #[DataProvider('fichesNouvellesOuChangees')]
    public function test_sans_droit_de_creer_des_produits_une_fiche_nouvelle_ou_changee_est_refusee(Closure $modification): void
    {
        [$fournisseur, $brouillon, $lignes] = $this->brouillonDuProprietaireAvecUnNouveauProduitPuisUnMembreSansDroitDeCreer();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $brouillon, $fournisseur, $modification($lignes)),
            LogicException::class,
            'Vous ne pouvez pas créer de produit : « Coque » ne peut être saisi que par une personne autorisée à créer des produits.',
        );

        $this->assertSame(
            [[1, null], [4, '15.00']],
            $brouillon->lignes()->orderBy('ordre')->get()->map(fn (LigneAchat $ligne): array => [$ligne->quantite, $ligne->nouveau_produit['prix_vente_ttc'] ?? null])->all(),
        );
        $this->assertSame(1, Produit::query()->count());
    }

    /**
     * @return array<string, array{Closure(list<array<string, mixed>>): list<array<string, mixed>>}>
     */
    public static function fichesNouvellesOuChangees(): array
    {
        return [
            'prix de vente changé' => [fn (array $lignes): array => [$lignes[0], array_replace_recursive($lignes[1], ['produit' => ['prix_vente_ttc' => 12]])]],
            'fiche recopiée sur une seconde ligne' => [fn (array $lignes): array => [...$lignes, $lignes[1]]],
        ];
    }

    /**
     * Valider une facture d'achat qui crée des produits exige le droit de
     * créer des produits (complément 3) : un membre qui peut valider les
     * achats sans pouvoir créer de produits est refusé, et rien n'est créé,
     * ni numéroté, ni reçu en stock.
     */
    public function test_valider_une_facture_qui_cree_des_produits_exige_le_droit_de_creer_des_produits(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = app(CreerFactureAchatAction::class)->handle($this->proprietaireDe($magasin), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 2],
        ]);
        $membre = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_VIEW]);

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle($membre, $brouillon),
            AuthorizationException::class,
            'Valider cette facture crée de nouveaux produits : il faut aussi le droit de créer des produits.',
        );

        $this->assertSame([StatutFactureAchat::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(0, Produit::query()->count());
        $this->assertSame(0, MouvementStock::query()->count());
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->exists());
    }

    /**
     * Sans nouveau produit, valider une facture d'achat n'exige que le
     * droit de valider : le même membre reçoit le stock d'un produit déjà
     * au catalogue.
     */
    public function test_valider_une_facture_sans_nouveau_produit_n_exige_pas_le_droit_de_creer_des_produits(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $brouillon = app(CreerFactureAchatAction::class)->handle($this->proprietaireDe($magasin), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 2],
        ]);
        $membre = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_VIEW]);

        app(ValiderFactureAchatAction::class)->handle($membre, $brouillon);

        $this->assertSame(StatutFactureAchat::Validee, $brouillon->fresh()->statut);
        $this->assertSame(3, $produit->fresh()->stock_quantite);
    }

    public function test_une_quantite_achetee_non_entiere_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 10, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité doit être un nombre entier d'articles, au moins 1.");

        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => '2.5'],
        ]);
    }

    public function test_valider_une_facture_dachat_lui_attribue_un_numero_et_incremente_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

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

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 7],
        ]));
        $this->assertSame('6.0000', $produit->fresh()->cmup_ht);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $this->assertSame($ancienFournisseur->id, $produit->fresh()->fournisseur_id, 'Un brouillon ne change rien au produit.');

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

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
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 100, 'remise_pourcentage' => 10],
        ]);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

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
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => '1.18', 'remise_pourcentage' => 15],
        ]);

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

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
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 100, 'remise_pourcentage' => 10],
        ], ['escompte_pourcentage' => 2]);

        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertSame('88.2000', $produit->fresh()->cmup_ht);
        $this->assertSame('270.00', $factureAchat->fresh()->total_ht);
    }

    public function test_un_escompte_hors_de_0_a_100_pourcent_est_refuse(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        $this->expectExceptionObject(new InvalidArgumentException('L\'escompte doit être compris entre 0 et 100 %.'));

        try {
            app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->expectException(LogicException::class);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat->fresh());
    }

    public function test_un_brouillon_reste_modifiable_lignes_comprises(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produitInitial = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $nouveauProduit = Produit::factory()->create(['cmup_ht' => 10, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produitInitial->id, 'quantite' => 5],
        ]);

        $factureAchat = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertSame(SourceFactureAchat::Manuelle, $factureAchat->source);
        $this->assertTrue($factureAchat->estModifiable());
        $this->assertSame(8, $produit->fresh()->stock_quantite);

        // Correction d'une erreur de saisie : 5 reçus en réalité, pas 8.
        $factureAchat = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 5],
        ]));
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 9);

        $factureAchat = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 6],
        ]);
        $this->assertSame(['6.0000', 1], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $sansSuivi->id, 'quantite' => 1, 'prix_unitaire_ht' => 4],
        ]));
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $sansSuivi->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]));
        $this->assertSame('9.0000', $sansSuivi->fresh()->cmup_ht);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        $ancienne = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]);
        app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
        ]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $ancienne, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5.5],
        ]);

        $this->assertSame(6.0, $produit->fresh()->dernierPrixDAchatHt());
    }

    public function test_corriger_lescompte_dune_facture_dachat_validee_recalcule_le_cout_dachat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 50],
        ]));

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 50],
        ], ['escompte_pourcentage' => 3]);

        $this->assertSame('48.5000', $produit->fresh()->cmup_ht);
    }

    /**
     * Un brouillon d'avoir fournisseur, que l'écran ne laisse jamais mais
     * que le domaine peut créer seul, retient aussi les lignes de la
     * facture d'achat : elle ne se modifie plus, au lieu d'échouer contre
     * la base, qui refuse d'effacer une ligne d'achat retournée.
     */
    public function test_une_facture_dachat_qui_a_un_avoir_fournisseur_en_brouillon_n_est_plus_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 2],
        ]);

        $this->assertFalse($factureAchat->fresh()->estModifiable());
        $this->assertThrows(fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat->fresh(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]), LogicException::class, 'Cette facture d\'achat n\'est plus modifiable.');
        $this->assertSame([5, 5], [$factureAchat->lignes()->sole()->quantite, $produit->fresh()->stock_quantite]);
    }

    public function test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ], ['source' => SourceFactureAchat::Api]);

        $this->assertFalse($factureAchat->estModifiable(), 'Même en brouillon, une facture API ne doit jamais être modifiable.');

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }

    public function test_une_facture_dachat_deja_retournee_au_fournisseur_nest_plus_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $avoir = app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ], 'Produit défectueux');
        app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), $avoir);

        $this->assertFalse($factureAchat->fresh()->estModifiable());

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat->fresh(), $fournisseur, [
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

        $seconde = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $seconde, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 5],
        ]);
        $this->assertSame(['3.5000', 10], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $seconde, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        $f1 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 8]]);
        $f2 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f2);
        $this->travelTo(self::aParis('2026-10-09 10:01:00'));
        $f1 = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f1);
        $this->assertSame('8.0000', $produit->fresh()->cmup_ht);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $f1, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]);

        $this->assertSame('9.0000', $produit->fresh()->cmup_ht);
    }

    /**
     * Le dernier achat reste le plus récemment validé quand deux
     * validations tombent dans la même seconde (deux personnes, ou une
     * validation par l'API) : F1 (8 €, chez B) est créée avant F2 (5 €,
     * chez A), mais F2 est validée la première, au même instant figé. Le
     * rang de validation, pris sous verrou, les départage dans l'ordre de
     * leur validation, pas dans celui de leur création : corrigée à 9 €,
     * F1 reste le dernier achat, le produit, qui ne suit pas son stock, en
     * reprend le coût et reste chez B.
     */
    public function test_le_dernier_achat_reste_le_plus_recemment_valide_quand_deux_validations_tombent_dans_la_meme_seconde(): void
    {
        $chezA = Fournisseur::factory()->create();
        $chezB = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $f1 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $chezB, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 8]]);
        $f2 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $chezA, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $f2 = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f2);
        $f1 = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f1);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $f1, $chezB, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]);

        $this->assertEquals($f1->date_validation, $f2->date_validation);
        $this->assertSame([1, 2], [$f2->fresh()->rang_validation, $f1->fresh()->rang_validation]);
        $this->assertSame(['9.0000', $chezB->id], [$produit->fresh()->cmup_ht, $produit->fresh()->fournisseur_id]);
    }

    /**
     * Le dernier achat reste le plus récemment validé même quand l'heure
     * de Paris se répète, la nuit du passage à l'heure d'hiver : F2 (5 €,
     * chez A) est validée à 2 h 59 heure d'été, F1 (8 €, chez B) une
     * minute plus tard, à 2 h 00 heure d'hiver. Corrigée à 9 €, F1 reste le
     * dernier achat : le produit, qui ne suit pas son stock, en reprend le
     * coût, et reste chez B. L'instant de chaque validation, enregistré en
     * UTC, se relit à son heure de Paris, décalage compris.
     */
    public function test_le_dernier_achat_reste_le_plus_recemment_valide_quand_l_heure_de_paris_se_repete(): void
    {
        $chezA = Fournisseur::factory()->create();
        $chezB = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $f1 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $chezB, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 8]]);
        $f2 = app(CreerFactureAchatAction::class)->handle(self::auteur(), $chezA, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travelTo(self::enUtc('2026-10-25 00:59:30'));
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f2);
        $this->travelTo(self::enUtc('2026-10-25 01:00:30'));
        $f1 = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $f1);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $f1, $chezB, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 9],
        ]);

        $this->assertSame(['9.0000', $chezB->id], [$produit->fresh()->cmup_ht, $produit->fresh()->fournisseur_id]);
        $this->assertSame(['2026-10-25 02:59:30 +02:00', '2026-10-25 02:00:30 +01:00'], [$f2->fresh()->date_validation->format('Y-m-d H:i:s P'), $f1->fresh()->date_validation->format('Y-m-d H:i:s P')]);
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
        $factureAchat = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 4],
        ]);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
        $produit->update(['suivi_stock' => true]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $seconde, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        $factureAchat = app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 4],
        ]);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2],
            ['produit_id' => $ajoute->id, 'produit' => ['suivi_stock' => false], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);

        $ajoute->refresh();
        $this->assertFalse($ajoute->suivi_stock);
        $this->assertSame(['3.0000', 0], [$ajoute->cmup_ht, $ajoute->stock_quantite]);
        $this->assertSame(0, $ajoute->mouvementsStock()->count());
    }

    /**
     * La marchandise d'une réception validée est déjà reçue : le nouveau
     * produit qu'y ajoute une correction naît tout de suite, chez le
     * fournisseur de la facture, et entre en stock à son coût.
     */
    public function test_un_nouveau_produit_ajoute_a_une_reception_validee_nait_et_entre_en_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $recu = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $recu->id, 'quantite' => 10, 'prix_unitaire_ht' => 2],
            ['produit' => ['nom' => 'Coque', 'reference' => 'COQ-1', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);

        $coque = Produit::query()->where('reference', 'COQ-1')->sole();
        $this->assertSame([$fournisseur->id, '3.0000', '15.00', 4], [$coque->fournisseur_id, $coque->cmup_ht, $coque->prix_vente_ttc, $coque->stock_quantite]);
        $this->assertSame([[$recu->id, null], [$coque->id, null]], $factureAchat->lignes()->get()->map(fn (LigneAchat $ligne): array => [$ligne->produit_id, $ligne->nouveau_produit])->all());
    }

    /**
     * Le fournisseur d'un produit est celui de son dernier achat validé,
     * même saisi à la main après une facture récupérée automatiquement chez
     * un autre ; ce que la facture récupérée dit du produit (son nom) reste
     * figé.
     */
    public function test_un_achat_saisi_a_la_main_donne_son_fournisseur_a_un_produit_recu_par_api(): void
    {
        $fournisseurDeLaFactureRecue = Fournisseur::factory()->create();
        $dernierFournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Écran (nom du fournisseur)', 'taux_tva' => 20, 'suivi_stock' => true]);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseurDeLaFactureRecue, [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 40],
        ], ['source' => SourceFactureAchat::Api]));
        $this->assertSame($fournisseurDeLaFactureRecue->id, $produit->fresh()->fournisseur_id);
        $this->travelTo(self::aParis('2026-10-09 10:01:00'));

        $this->recevoir($dernierFournisseur, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 38]]);

        $this->assertSame([$dernierFournisseur->id, 'Écran (nom du fournisseur)'], [$produit->fresh()->fournisseur_id, $produit->fresh()->nom]);
    }

    /**
     * Un retour au fournisseur se rejoue avec le grand livre, au coût de sa
     * ligne d'achat : A, 10 reçus à 2 €, puis B, 10 à 10 € (CMUP 6 €) ; 5
     * retournés sur B : (20 x 6 - 5 x 10) / 15 = 4,6667 €. A corrigée à
     * 3 € : (10 x 3 + 10 x 10) / 20 = 6,50 €, puis le même retour,
     * (20 x 6,50 - 5 x 10) / 15 = 5,3333 €.
     */
    public function test_corriger_une_reception_rejoue_les_retours_au_fournisseur_a_leur_cout(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureA = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]]);
        $factureB = $this->recevoir($fournisseur, [['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 10]]);
        app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureB, [
            ['ligne_achat_id' => $factureB->lignes->sole()->id, 'quantite' => 5],
        ], 'Produit défectueux'));
        $this->assertSame(['4.6667', 15], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureA, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 3],
        ]);

        $this->assertSame(['5.3333', 15], [$produit->fresh()->cmup_ht, $produit->fresh()->stock_quantite]);
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $ancienneFacture = $this->recevoir($ancien, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travelTo(self::aParis('2026-10-09 10:01:00'));
        $this->recevoir($dernier, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6]]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $ancienneFacture, $ancien, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5.5],
        ], ['notes' => 'Prix corrigé']);

        $this->assertSame($dernier->id, $produit->fresh()->fournisseur_id);
    }

    /**
     * Retiré de sa dernière facture d'achat, le produit revient au
     * fournisseur de l'achat validé d'avant ; retiré de son seul achat
     * validé, il n'a plus de fournisseur, puisqu'il n'a plus d'achat validé.
     */
    public function test_un_produit_retire_de_sa_derniere_facture_reprend_le_fournisseur_de_l_achat_d_avant_ou_aucun(): void
    {
        $ancien = Fournisseur::factory()->create();
        $dernier = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $seulAchat = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $autre = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->recevoir($ancien, [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]]);
        $this->travelTo(self::aParis('2026-10-09 10:01:00'));
        $derniereFacture = $this->recevoir($dernier, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
            ['produit_id' => $seulAchat->id, 'quantite' => 1, 'prix_unitaire_ht' => 6],
        ]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $derniereFacture, $dernier, [
            ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        $this->assertSame($ancien->id, $produit->fresh()->fournisseur_id);
        $this->assertNull($seulAchat->fresh()->fournisseur_id);
    }

    /**
     * Le nouveau produit né à la validation d'une facture d'achat, puis
     * retiré de cette seule facture par sa correction, sort du stock et n'a
     * plus de fournisseur.
     */
    public function test_un_nouveau_produit_retire_de_sa_seule_facture_validee_n_a_plus_de_fournisseur(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $autre = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($fournisseur, [
            ['produit' => ['nom' => 'Coque', 'reference' => 'COQ-1', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);
        $coque = Produit::query()->where('reference', 'COQ-1')->sole();
        $this->assertSame([$fournisseur->id, 4], [$coque->fournisseur_id, $coque->stock_quantite]);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => $autre->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        $coque->refresh();
        $this->assertSame([null, 0, 0], [$coque->fournisseur_id, $coque->stock_quantite, $coque->achatsValides()->count()]);
    }

    /**
     * Un produit archivé depuis la saisie du brouillon ne se reçoit plus
     * en silence : la validation est refusée, avec un message, et rien
     * n'entre en stock.
     */
    public function test_valider_une_facture_dachat_dont_un_produit_est_archive_est_refuse(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $produit->delete();

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat),
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $fournisseur->delete();

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat),
            LogicException::class,
            'Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant de valider cette facture d\'achat.',
        );

        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->fresh()->statut, $factureAchat->fresh()->numero]);
        $this->assertSame(0, $produit->fresh()->stock_quantite);

        $fournisseur->restore();
        $validee = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);

        $this->assertSame('FA-'.app(TenantContext::class)->magasin()->code.'-2026-000001', $validee->numero);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
    }

    /**
     * Un nouveau produit naît rattaché au fournisseur de la facture, à sa
     * validation : archivé depuis la saisie du brouillon, ce fournisseur
     * n'en reçoit pas tant qu'il n'est pas restauré. Le brouillon reste
     * modifiable, nouveau produit compris, sans que rien ne soit créé ; sa
     * validation est refusée (message), puis, le fournisseur restauré, crée
     * le produit chez lui.
     */
    public function test_un_fournisseur_archive_ne_recoit_pas_de_nouveau_produit_avant_sa_restauration(): void
    {
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $fournisseur->delete();

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $brouillon, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
            ['produit' => ['nom' => 'Câble USB-C', 'reference' => 'CAB-1', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 4],
        ]);
        $this->assertSame(['22.00', 0], [$brouillon->fresh()->total_ht, Produit::withTrashed()->where('reference', 'CAB-1')->count()]);

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $brouillon),
            LogicException::class,
            'Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant de valider cette facture d\'achat.',
        );
        $this->assertSame(0, Produit::withTrashed()->where('reference', 'CAB-1')->count());

        $fournisseur->restore();
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $brouillon);

        $this->assertSame([$fournisseur->id, 3], [Produit::query()->where('reference', 'CAB-1')->sole()->fournisseur_id, Produit::query()->where('reference', 'CAB-1')->sole()->stock_quantite]);
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
            fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
     * L'écran résout le fournisseur choisi avant la correction : archivé
     * depuis par une autre requête, il est relu sous verrou par la
     * correction, qui le voit archivé et la refuse, avec un message ; la
     * facture et son produit gardent leur fournisseur.
     */
    public function test_corriger_une_facture_dachat_validee_vers_un_fournisseur_archive_depuis_sa_lecture_est_refuse(): void
    {
        $ancien = Fournisseur::factory()->create(['nom' => 'Grossiste']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir($ancien, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        $nouveauLu = Fournisseur::factory()->create(['nom' => 'Nouveau grossiste']);
        Fournisseur::query()->findOrFail($nouveauLu->id)->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $nouveauLu, [
                ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
            ]),
            LogicException::class,
            'Le fournisseur « Nouveau grossiste » a été archivé : restaurez-le depuis la liste des fournisseurs avant de corriger cette facture d\'achat.',
        );

        $this->assertSame([$ancien->id, $ancien->id], [$factureAchat->fresh()->fournisseur_id, $produit->fresh()->fournisseur_id]);
    }

    /**
     * Chaque ligne d'achat reçoit un produit, ou dans un brouillon la fiche
     * du nouveau produit qu'elle créera (voir LigneAchat) -- jamais aucun des
     * deux, ni les deux à la fois ; sa ligne d'avoir fournisseur reprend le
     * produit, et la base la refuse sans lui.
     */
    public function test_une_ligne_d_achat_ou_d_avoir_fournisseur_sans_produit_est_refusee(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir(Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]);
        $avoir = app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1],
        ]);
        $message = 'Une ligne d\'achat reçoit soit un produit du catalogue, soit la fiche d\'un nouveau produit.';

        $this->assertThrows(fn () => $factureAchat->lignes->sole()->replicate()->forceFill(['produit_id' => null])->save(), InvalidArgumentException::class, $message);
        $this->assertThrows(fn () => $factureAchat->lignes->sole()->replicate()->forceFill(['nouveau_produit' => ['nom' => 'Coque']])->save(), InvalidArgumentException::class, $message);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $avoir->lignes->sole()->replicate()->forceFill(['produit_id' => null])->save()), QueryException::class);

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
            fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $produit->delete();

        $this->assertThrows(
            fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $brouillon, $fournisseur, [
                ['produit_id' => $produit->id, 'quantite' => 6, 'prix_unitaire_ht' => 2],
            ]),
            LogicException::class,
            'Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture.',
        );
        $this->assertSame(5, $brouillon->fresh()->lignes->sole()->quantite);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $brouillon, $fournisseur, [
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

        $requetes = $this->requetesDe(fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $livre->id, 'quantite' => 1, 'prix_unitaire_ht' => 5],
        ]);

        $requetes = $this->requetesDe(fn () => app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
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

        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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

        $this->assertThrows(fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
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
     * Un câble USB-C acheté 0,47 € HT, à revendre 10,00 € TTC : la ligne
     * d'achat d'un nouveau produit.
     *
     * @return array<string, mixed>
     */
    private function ligneDuCableUsbC(): array
    {
        return [
            'produit' => ['nom' => 'Câble USB-C', 'reference' => 'CBL-USBC', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 2],
            'quantite' => 3,
            'prix_unitaire_ht' => 0.47,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function recevoir(Fournisseur $fournisseur, array $lignes): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, $lignes));
    }

    /**
     * Le propriétaire enregistre un brouillon qui reçoit 1 produit existant
     * à 2,00 € HT et 4 nouvelles Coques à 3,00 € HT, à revendre 15,00 €
     * TTC ; puis un membre qui saisit et valide les achats, mais ne peut pas
     * créer de produits, se connecte.
     *
     * @return array{Fournisseur, FactureAchat, list<array<string, mixed>>, Produit}
     */
    private function brouillonDuProprietaireAvecUnNouveauProduitPuisUnMembreSansDroitDeCreer(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        $fournisseur = Fournisseur::factory()->create();
        $existant = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $lignes = [
            ['produit_id' => $existant->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ];
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, $lignes);
        $this->actingAs($this->membre($magasin, RoleMagasin::Observateur, [
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::FACTURE_ACHAT_CREER,
            PermissionRegistry::FACTURE_ACHAT_VALIDER,
            PermissionRegistry::PRODUIT_VIEW,
        ]));

        return [$fournisseur, $brouillon, $lignes, $existant];
    }

    /**
     * Un observateur à qui le propriétaire a confié la saisie des achats,
     * avec les droits produit donnés.
     *
     * @param  list<string>  $droitsProduit
     */
    private function connecterUnMembreQuiSaisitLesAchats(array $droitsProduit): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Observateur, [...RoleMagasin::Observateur->permissionsParDefaut(), PermissionRegistry::FACTURE_ACHAT_CREER, ...$droitsProduit], [
            'name' => 'Léa', 'email' => 'lea@example.test',
        ]);
        $this->actingAs($membre);
    }
}
