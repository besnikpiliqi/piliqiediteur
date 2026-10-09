<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\ProduitResource\Pages\CreateProduit;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
}
