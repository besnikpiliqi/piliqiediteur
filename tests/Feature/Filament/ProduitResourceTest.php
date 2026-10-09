<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Pages\CreateProduit;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le prix de vente d'un produit se saisit et s'enregistre en TTC (voir
 * ProduitResource::prixVenteTtcSchema()) : c'est le prix affiché au client,
 * et c'est lui qu'une ligne de vente reprend. Le HT n'en est que la
 * conversion au centime (voir Produit::synchroniserPrixDeVente()).
 */
class ProduitResourceTest extends TestCase
{
    use RefreshDatabase;

    private function connecterProprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
    }

    public function test_le_prix_de_vente_ttc_saisi_est_enregistre_tel_quel_avec_son_ht(): void
    {
        $this->connecterProprietaire();

        Livewire::test(CreateProduit::class)
            ->fillForm([
                'nom' => 'Ecran Iphone 18 Pro',
                'cmup_ht' => 25,
                'prix_vente_ttc' => 40,
                'taux_tva' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $produit = Produit::query()->where('nom', 'Ecran Iphone 18 Pro')->firstOrFail();

        $this->assertSame('40.00', $produit->prix_vente_ttc);
        $this->assertSame('33.33', $produit->prix_vente_ht);
    }

    public function test_modifier_le_prix_de_vente_ttc_recalcule_le_ht(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormSet(['prix_vente_ttc' => '120.00'])
            ->fillForm(['prix_vente_ttc' => 144])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('144.00', $produit->fresh()->prix_vente_ttc);
        $this->assertSame('120.00', $produit->fresh()->prix_vente_ht);
    }

    /**
     * Un produit enregistré avec un HT (fabrique de test, future API)
     * obtient son TTC au centime :
     * 33,33 € HT donne bien 40,00 € TTC, jamais 39,996 €.
     */
    public function test_un_produit_enregistre_en_ht_obtient_son_ttc_au_centime(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['prix_vente_ht' => 33.33, 'taux_tva' => 20]);

        $this->assertSame('40.00', $produit->fresh()->prix_vente_ttc);
    }

    public function test_changer_le_taux_de_tva_garde_le_prix_ttc_et_recalcule_le_ht(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $produit->update(['taux_tva' => 5.5]);

        $this->assertSame('12.00', $produit->fresh()->prix_vente_ttc);
        $this->assertSame('11.37', $produit->fresh()->prix_vente_ht);
    }

    /**
     * Le taux de TVA a un impact sur la conformité : sans clé de signature,
     * son changement ne peut pas s'inscrire au journal des événements,
     * donc ne s'enregistre pas, et l'écran dit pourquoi.
     */
    public function test_sans_cle_de_signature_le_taux_de_tva_ne_change_pas_et_l_ecran_dit_pourquoi(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['taux_tva' => 5.5])
            ->call('save')
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertSame('20.00', $produit->fresh()->taux_tva);
    }

    /**
     * Reproduit exactement la capture d'écran du magasin : un produit à
     * 40 € TTC choisi sur une ligne de facture, quantité 2, affichait
     * "Total TTC 79,99 €". Le total doit être 80,00 €, à l'écran comme une
     * fois la facture enregistrée.
     */
    public function test_un_produit_a_40_euros_ttc_vendu_par_2_totalise_80_euros_a_lecran_et_en_base(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro', 'prix_vente_ht' => 33.33, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->create();

        $component = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id]);

        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '40.00')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '33.33')
            ->assertSee('80,00 €')
            ->assertDontSee('79,99 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('40.00', $ligne->prix_unitaire_ttc);
        $this->assertSame('33.33', $ligne->prix_unitaire_ht);
        $this->assertSame('80.00', $facture->total_ttc);
        $this->assertSame('66.66', $facture->total_ht);
        $this->assertSame('13.34', $facture->total_tva);
    }

    public function test_le_cmup_est_enregistre_au_dix_millieme(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['cmup_ht' => 8.33335]);

        $this->assertEquals(8.3334, DB::table('produits')->where('id', $produit->id)->value('cmup_ht'));
    }

    public function test_le_cmup_ne_se_saisit_qu_a_la_creation_du_produit(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['cmup_ht' => 6.8]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormFieldIsDisabled('cmup_ht')
            ->assertFormSet(['cmup_ht' => '6.80'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('6.8000', $produit->fresh()->cmup_ht);
    }

    /**
     * Un produit reçu sur une facture d'achat affiche le fournisseur chez
     * qui il vient d'être acheté.
     */
    public function test_la_fiche_produit_affiche_le_fournisseur_de_sa_facture_dachat(): void
    {
        $this->connecterProprietaire();
        $fournisseur = Fournisseur::factory()->create();
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 0.47],
        ]);
        app(ValiderFactureAchatAction::class)->handle($factureAchat);

        Livewire::test(EditProduit::class, ['record' => Produit::query()->sole()->getRouteKey()])
            ->assertFormSet(['fournisseur_id' => $fournisseur->id, 'cmup_ht' => '0.47']);
    }

    /**
     * Ce que le fournisseur dit d'un produit reçu par API (référence, nom,
     * fournisseur, prix d'achat) reste affiché mais figé ; le magasin garde
     * la main sur son prix de vente.
     */
    public function test_un_produit_recu_par_api_garde_ce_que_son_fournisseur_en_dit(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Ecran', 'prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['source' => SourceFactureAchat::Api]);

        $component = Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()]);

        foreach (['reference', 'nom', 'code_barres', 'fournisseur_id', 'cmup_ht'] as $champ) {
            $component->assertFormFieldIsDisabled($champ);
        }

        $component
            ->assertFormFieldIsEnabled('prix_vente_ttc')
            ->fillForm(['prix_vente_ttc' => 110])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Ecran', '110.00'], [$produit->fresh()->nom, $produit->fresh()->prix_vente_ttc]);
    }

    public function test_un_produit_saisi_a_la_main_reste_entierement_modifiable(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Ecran']);
        app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormFieldIsEnabled('nom')
            ->fillForm(['nom' => 'Écran OLED'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Écran OLED', $produit->fresh()->nom);
    }

    public function test_une_reference_deja_prise_est_refusee(): void
    {
        $this->connecterProprietaire();
        Produit::factory()->create(['reference' => 'ECR-1']);

        Livewire::test(CreateProduit::class)
            ->fillForm(['reference' => 'ECR-1', 'nom' => 'Autre écran', 'cmup_ht' => 10, 'prix_vente_ttc' => 20, 'taux_tva' => 20])
            ->call('create')
            ->assertHasFormErrors(['reference' => 'unique']);

        $this->assertSame(1, Produit::query()->count());
    }

    public function test_le_fournisseur_d_un_autre_magasin_est_refuse_a_la_creation(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $fournisseurDUnAutreMagasin = Fournisseur::factory()->create();
        $this->connecterProprietaire();

        Livewire::test(CreateProduit::class)
            ->fillForm(['nom' => 'Écran', 'cmup_ht' => 10, 'prix_vente_ttc' => 20, 'taux_tva' => 20, 'fournisseur_id' => $fournisseurDUnAutreMagasin->id])
            ->call('create')
            ->assertHasFormErrors(['fournisseur_id' => 'exists']);

        $this->assertSame(0, Produit::query()->count());
    }

    public function test_le_fournisseur_d_un_autre_magasin_est_refuse_a_la_modification(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $fournisseurDUnAutreMagasin = Fournisseur::factory()->create();
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['fournisseur_id' => $fournisseurDUnAutreMagasin->id])
            ->call('save')
            ->assertHasFormErrors(['fournisseur_id' => 'exists']);

        $this->assertNull($produit->fresh()->fournisseur_id);
    }

    public function test_le_proprietaire_supprime_des_produits_en_masse(): void
    {
        $this->connecterProprietaire();
        $produits = Produit::factory()->count(2)->create();

        Livewire::test(ListProduits::class)
            ->callTableBulkAction('delete', $produits)
            ->assertNotified('Supprimé(e)s');

        $produits->each(fn (Produit $produit) => $this->assertSoftDeleted($produit));
    }

    /**
     * Sans la permission de supprimer les produits, le vendeur n'a pas le
     * bouton de suppression en masse, et une requête forgée ne supprime
     * rien.
     */
    public function test_un_vendeur_ne_supprime_pas_de_produits_en_masse(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $produits = Produit::factory()->count(2)->create();

        Livewire::test(ListProduits::class)
            ->assertTableBulkActionHidden('delete')
            ->call('mountTableBulkAction', 'delete', $produits->modelKeys())
            ->call('callMountedTableBulkAction');

        $produits->each(fn (Produit $produit) => $this->assertNotSoftDeleted($produit));
    }

    /**
     * Un taux vidé n'est ni 0 % ni le taux par défaut : il est demandé,
     * plutôt que d'échouer sur la base.
     */
    public function test_un_taux_de_tva_vide_est_demande_a_la_creation(): void
    {
        $this->connecterProprietaire();

        Livewire::test(CreateProduit::class)
            ->fillForm(['nom' => 'Écran', 'cmup_ht' => 10, 'prix_vente_ttc' => 12, 'taux_tva' => ''])
            ->call('create')
            ->assertHasFormErrors(['taux_tva' => 'required']);

        $this->assertSame(0, Produit::query()->count());
    }

    public function test_un_taux_de_tva_vide_est_demande_a_la_modification(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['taux_tva' => ''])
            ->call('save')
            ->assertHasFormErrors(['taux_tva' => 'required']);

        $this->assertSame('20.00', $produit->fresh()->taux_tva);
    }

    /**
     * Le détail HT et TVA du prix de vente ne se calcule pas tant que la
     * TVA est vide.
     */
    public function test_le_detail_du_prix_de_vente_attend_le_taux_de_tva(): void
    {
        $this->connecterProprietaire();

        Livewire::test(CreateProduit::class)
            ->fillForm(['prix_vente_ttc' => '12', 'taux_tva' => '20'])
            ->assertSee('Soit 10,00 € HT + 2,00 € de TVA')
            ->fillForm(['taux_tva' => ''])
            ->assertDontSee('€ de TVA');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function prixHorsLimites(): array
    {
        return [
            'prix de vente négatif' => ['prix_vente_ttc', '-12', 'min'],
            'prix de vente démesuré' => ['prix_vente_ttc', '99999999999', 'max'],
            'prix d\'achat négatif' => ['cmup_ht', '-5', 'min'],
            'prix d\'achat démesuré' => ['cmup_ht', '99999999999', 'max'],
        ];
    }

    #[DataProvider('prixHorsLimites')]
    public function test_un_prix_negatif_ou_demesure_est_refuse(string $champ, string $prix, string $regle): void
    {
        $this->connecterProprietaire();

        Livewire::test(CreateProduit::class)
            ->fillForm(['nom' => 'Écran', 'cmup_ht' => 10, 'prix_vente_ttc' => 12, 'taux_tva' => 20, $champ => $prix])
            ->call('create')
            ->assertHasFormErrors([$champ => $regle]);

        $this->assertSame(0, Produit::query()->count());
    }

    /**
     * Un produit qui ne suit pas son stock n'est ni « En stock » ni
     * « Épuisé », comme dans le widget de la liste -- même s'il gardait une
     * quantité d'avant l'arrêt du suivi.
     */
    public function test_le_statut_du_stock_ne_retient_que_les_produits_suivis(): void
    {
        $this->connecterProprietaire();
        $enStock = Produit::factory()->create(['suivi_stock' => true, 'stock_quantite' => 5]);
        $epuise = Produit::factory()->create(['suivi_stock' => true, 'stock_quantite' => 0]);
        $sansSuiviAZero = Produit::factory()->create(['suivi_stock' => false, 'stock_quantite' => 0]);
        $sansSuiviAvecQuantite = Produit::factory()->create(['suivi_stock' => false, 'stock_quantite' => 3]);

        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'epuise')
            ->assertCanSeeTableRecords([$epuise])
            ->assertCanNotSeeTableRecords([$enStock, $sansSuiviAZero, $sansSuiviAvecQuantite])
            ->filterTable('statut_stock', 'en_stock')
            ->assertCanSeeTableRecords([$enStock])
            ->assertCanNotSeeTableRecords([$epuise, $sansSuiviAZero, $sansSuiviAvecQuantite]);
    }

    /**
     * Sa référence reste prise (voir Produit::verifierQueSaReferenceEstLibre()) :
     * un produit supprimé se retrouve et se restaure depuis la liste.
     */
    public function test_un_produit_supprime_se_retrouve_et_se_restaure_depuis_la_liste(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();
        $produit->delete();

        Livewire::test(ListProduits::class)
            ->assertCanNotSeeTableRecords([$produit])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$produit])
            ->assertTableActionHidden('edit', $produit)
            ->callTableAction('restore', $produit);

        $this->assertNotSoftDeleted($produit);
    }

    /**
     * Sa page de modification ne trouve plus un produit supprimé (voir
     * ProduitPolicy::update()) : sa ligne n'y mène pas.
     */
    public function test_la_ligne_d_un_produit_supprime_ne_mene_a_aucune_page(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();
        $supprime = Produit::factory()->create();
        $supprime->delete();

        $table = Livewire::test(ListProduits::class)
            ->filterTable('trashed', false)
            ->instance()
            ->getTable();

        $this->assertSame(ProduitResource::getUrl('edit', ['record' => $produit]), $table->getRecordUrl($produit));
        $this->assertNull($table->getRecordUrl($supprime));
    }
}
