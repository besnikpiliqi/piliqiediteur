<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CaisseTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_formulaire_propose_le_client_comptoir_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->assertFormSet(['client_id' => $clientComptoir->id]);
    }

    public function test_un_vendeur_encaisse_une_vente_comptoir_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('48.00', $facture->total_ttc); // 2 x 20 HT, TVA 20%
        $this->assertSame(8, $produit->fresh()->stock_quantite);

        $this->assertDatabaseHas('paiements', [
            'client_id' => $clientComptoir->id,
            'mode_paiement' => 'especes',
            'montant' => 48,
        ]);
    }

    /**
     * Exigence explicite : chaque vendeur a sa propre caisse -- un vendeur
     * avec les permissions par défaut (donc sans aucune permission de
     * clôture de la journée du magasin, voir SeedMagasinRolesAction) doit
     * tout de même pouvoir encaisser une vente comptoir.
     */
    public function test_un_vendeur_avec_les_permissions_par_defaut_peut_encaisser_une_vente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertTrue(Caisse::canAccess());

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 30, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('cree_par', $vendeur->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
    }

    public function test_encaisser_sans_aucune_ligne_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [],
            ])
            ->call('encaisser')
            ->assertHasFormErrors(['lignes']);

        $this->assertDatabaseCount('factures', 0);
    }

    /**
     * Bug rapporté : deux lignes à 8,33 € HT (TVA 20 %) affichent chacune
     * 10,00 € TTC, donc 20,00 € au total -- mais le "Total à payer" et la
     * "Monnaie à rendre" affichaient 19,99 € / 5,01 € au lieu de 20,00 € /
     * 5,00 €, car ces deux aperçus sommaient les lignes en flottant non
     * arrondi au lieu de passer par CalculateurLigne comme la facture
     * réellement enregistrée. Voir CalculateurLigneTest pour le test unitaire
     * du calcul lui-même.
     */
    public function test_le_total_a_payer_correspond_exactement_a_la_somme_des_lignes_affichees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 8.33, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
                'montant_recu' => 25,
            ])
            ->assertSeeHtml('20,00 €')
            ->assertSeeHtml('5,00 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame('20.00', $facture->total_ttc);
    }

    /**
     * Même cas que la capture d'écran du magasin, côté caisse : un article
     * à 40 € TTC vendu par 2 doit faire payer exactement 80,00 €.
     */
    public function test_un_article_a_40_euros_ttc_vendu_par_2_encaisse_exactement_80_euros(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', Client::NOM_COMPTOIR)->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set('data.montant_recu', '100')
            ->assertSeeHtml('80,00 €')
            ->assertSeeHtml('20,00 €')
            ->assertDontSeeHtml('79,99 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Le crédit d'avoir du client est déduit automatiquement à l'émission
     * (voir AppliquerAvoirsDisponiblesAction) : la caisse doit donc
     * annoncer ce qui reste vraiment à payer, et rendre la monnaie sur ce
     * reste -- 50 € moins 10 € de crédit = 40 € ; 60 € reçus, 20 € rendus
     * (et non 10 €, ce qui laisserait la caisse avec 10 € de trop).
     */
    public function test_la_caisse_deduit_le_credit_davoir_du_client_du_total_et_de_la_monnaie_a_rendre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(['nom' => 'Mme Martin']);
        $produitRendu = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $venteInitiale = app(EnregistrerVenteCaisseAction::class)->handle($client, [['produit_id' => $produitRendu->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteInitiale->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $this->assertSame(10.0, $client->soldeCrediteur());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)->set('data.client_id', $client->id);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '60')
            ->assertSeeHtml('40,00 €')
            ->assertSeeHtml('Total 50,00 € moins 10,00 € de crédit d&#039;avoir du client.')
            ->assertSeeHtml('20,00 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame(10.0, $facture->montantCrediteParAvoir());
        $this->assertSame(40.0, $facture->montantPaye());
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * Un vendeur pense souvent en prix affiché/TTC (ex: viser pile 12 €)
     * plutôt qu'en HT : saisir le PU TTC (qui fait référence pour le calcul)
     * doit afficher le PU HT correspondant à sa place, sans qu'il ait à
     * faire le calcul lui-même. Voir DevisResource::ligneSchema().
     */
    public function test_saisir_le_prix_ttc_dune_ligne_calcule_automatiquement_le_prix_ht(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        $component = Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['description' => 'Pose écran', 'quantite' => 1, 'prix_unitaire_ht' => 0, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ]);

        $cle = array_key_first($component->get('data.lignes'));

        $component->set("data.lignes.{$cle}.prix_unitaire_ttc", '12');

        $this->assertSame(10.0, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ht"));
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client fidèle',
                'type_client' => 'particulier',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Client fidèle']);
    }

    /**
     * En espèces, un montant reçu saisi qui ne couvre pas ce qui reste à
     * payer est refusé : sinon la vente serait enregistrée payée avec un
     * tiroir-caisse en manque.
     */
    public function test_un_montant_recu_insuffisant_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '30')
            ->call('encaisser')
            ->assertHasFormErrors(['montant_recu' => 'min']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le formulaire montre le HT et la TVA de chaque ligne et les totaux de
     * la vente, calculés comme la facture qui sera enregistrée.
     */
    public function test_la_caisse_montre_le_ht_et_la_tva_de_chaque_ligne_et_les_totaux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->assertSee('66,66 € HT + 13,34 € de TVA')
            ->assertSee('Total HT 66,66 € · TVA 13,34 € · Total TTC 80,00 €');
    }

    /**
     * Un montant reçu absurde (des milliards de milliards) ne fait jamais
     * planter la caisse pendant la frappe, et il est refusé à l'encaissement.
     */
    public function test_un_montant_recu_absurde_ne_fait_pas_planter_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '100000000000000000')
            ->assertSuccessful()
            ->call('encaisser')
            ->assertHasFormErrors(['montant_recu' => 'max']);

        $this->assertSame(0, Facture::query()->count());
    }
}
