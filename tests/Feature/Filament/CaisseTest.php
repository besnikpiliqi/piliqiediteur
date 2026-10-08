<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
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
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => '8.33', 'prix_unitaire_ttc' => '10.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => '8.33', 'prix_unitaire_ttc' => '10.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
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
        $produit = Produit::factory()->create(['prix_vente_ttc' => 9, 'taux_tva' => 20]);

        $component = Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 7.5, 'taux_tva' => 20, 'remise_pourcentage' => 0],
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

    public function test_ajouter_un_produit_ajoute_une_ligne_vide_avec_les_valeurs_par_defaut(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit');

        $ligne = $this->seuleLigne($component);
        $this->assertNull($ligne['service_id']);
        $this->assertNull($ligne['produit_id']);
        $this->assertSame(1, $ligne['quantite']);
        $this->assertSame(20, $ligne['taux_tva']);
        $component->assertFormFieldIsVisible('lignes.'.array_key_first($component->get('data.lignes')).'.produit_id');
    }

    public function test_un_service_coche_devient_une_ligne_de_service_sans_produit_ni_pu_ht(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]])
            ->assertHasNoFormComponentActionErrors();

        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Remplacement écran', $ligne['description']);
        $this->assertSame(1, $ligne['quantite']);
        $this->assertNull($ligne['prix_unitaire_ttc']);
        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->assertFormFieldIsHidden("lignes.{$cle}.produit_id")
            ->assertFormFieldIsHidden("lignes.{$cle}.prix_unitaire_ht")
            ->assertFormFieldIsVisible("lignes.{$cle}.prix_unitaire_ttc")
            ->assertFormFieldIsVisible("lignes.{$cle}.taux_tva");
    }

    /**
     * L'exemple du magasin : écran 50 € + main-d'œuvre, payés ensemble. La
     * TVA du service se déduit du TTC saisi, comme pour un produit : 15,03 €
     * TTC donnent 12,53 € HT, et non le TTC recalculé depuis ce HT
     * (15,04 €).
     */
    public function test_une_vente_avec_un_service_enregistre_sa_ligne_depuis_le_ttc_saisi(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 3]);
        $mainDOeuvre = Service::factory()->create(['nom' => 'Main-d\'œuvre']);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit');
        $component->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$mainDOeuvre->id]]);
        [$cleProduit, $cleService] = array_keys($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cleProduit}.produit_id", $ecran->id)
            ->set("data.lignes.{$cleService}.prix_unitaire_ttc", '15.03')
            ->assertSee('12,53 € HT + 2,50 € de TVA')
            ->assertSee('Total HT 54,20 € · TVA 10,83 € · Total TTC 65,03 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('65.03', $facture->total_ttc);
        $ligneService = $facture->lignes->firstWhere('service_id', $mainDOeuvre->id);
        $this->assertSame(NatureLigne::Bien, $facture->lignes->firstWhere('produit_id', $ecran->id)->nature);
        $this->assertSame(NatureLigne::Service, $ligneService->nature);
        $this->assertNull($ligneService->produit_id);
        $this->assertSame('Main-d\'œuvre', $ligneService->description);
        $this->assertSame('12.53', $ligneService->montant_ht);
        $this->assertSame('2.50', $ligneService->montant_tva);
        $this->assertSame('15.03', $ligneService->montant_ttc);
        $this->assertSame(2, $ecran->fresh()->stock_quantite);
        $this->assertSame($magasin->id, $ligneService->magasin_id);
    }

    public function test_un_service_absent_de_la_liste_est_cree_et_propose_en_case_a_cocher_la_fois_suivante(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => '  Remplacement   batterie '])
            ->assertHasNoFormComponentActionErrors()
            ->assertNotified('Service « Remplacement batterie » ajouté à la liste');

        $service = Service::query()->sole();
        $this->assertSame('Remplacement batterie', $service->nom);
        $this->assertTrue($service->actif);
        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Remplacement batterie', $ligne['description']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Remplacement batterie');
    }

    public function test_un_service_deja_cree_sous_une_autre_ecriture_n_est_pas_cree_en_double(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Main d\'œuvre']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: [
                'services' => [$service->id],
                'nouveau_service' => ' MAIN  d’oeuvre',
            ])
            ->assertNotNotified('Service « Main d\'œuvre » ajouté à la liste');

        $this->assertSame(1, Service::query()->count());
        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Main d\'œuvre', $ligne['description']);
    }

    public function test_chaque_service_coche_devient_une_ligne_dans_l_ordre_des_cases_cochees(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $nettoyage = Service::factory()->create(['nom' => 'Nettoyage']);
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$diagnostic->id, $nettoyage->id]]);

        $this->assertSame(
            ['Diagnostic', 'Nettoyage'],
            array_values(array_column($component->get('data.lignes'), 'description')),
        );
    }

    public function test_la_fenetre_refuse_de_valider_sans_service_coche_ni_nouveau_nom(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [], 'nouveau_service' => ''])
            ->assertHasFormComponentActionErrors(['services' => ['Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']]);

        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_sans_aucun_service_cree_le_nom_du_premier_service_est_demande(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => ''])
            ->assertHasFormComponentActionErrors(['nouveau_service' => 'required']);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_un_service_archive_n_est_plus_propose_a_la_caisse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Désoxydation']);
        Service::factory()->archive()->create(['nom' => 'Flashage ancien modèle']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Désoxydation')
            ->assertDontSee('Flashage ancien modèle');
    }

    public function test_les_services_d_un_autre_magasin_ne_sont_jamais_proposes(): void
    {
        $autreProprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre Boutique'], $autreProprietaire);
        Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->ouvrirLaCaisseDUnMagasin();

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertDontSee('Service de la concurrence')
            ->assertSee('Nom du service');
    }

    /**
     * Le vendeur au comptoir doit pouvoir facturer une réparation qui n'est
     * pas encore dans la liste, sans permission de catalogue.
     */
    public function test_un_vendeur_avec_les_permissions_par_defaut_cree_un_service_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => 'Pose de verre trempé'])
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame('Pose de verre trempé', Service::query()->sole()->nom);
    }

    public function test_le_nouveau_service_tape_s_ajoute_apres_les_services_coches(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $nettoyage = Service::factory()->create(['nom' => 'Nettoyage']);
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: [
                'services' => [$diagnostic->id],
                'nouveau_service' => 'nettoyage',
            ]);

        $this->assertSame(
            ['Diagnostic', 'Nettoyage'],
            array_values(array_column($component->get('data.lignes'), 'description')),
        );
        $this->assertSame($nettoyage->id, array_values($component->get('data.lignes'))[1]['service_id']);
    }

    public function test_retaper_un_service_archive_le_repropose_a_la_caisse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->archive()->create(['nom' => 'Désoxydation']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => 'desoxydation'])
            ->assertNotified('Service « Désoxydation » de nouveau proposé à la caisse')
            ->assertNotNotified('Service « Désoxydation » ajouté à la liste');

        $this->assertTrue($service->fresh()->actif);
        $this->assertSame(1, Service::query()->count());
        $this->assertSame($service->id, $this->seuleLigne($component)['service_id']);
    }

    /**
     * Un clavier de téléphone insère facilement des espaces insécables :
     * « required » les laisse passer, le nom serait vide une fois nettoyé.
     */
    public function test_un_nom_de_service_fait_seulement_d_espaces_insecables_est_refuse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => "\u{00A0}\u{00A0}"])
            ->assertHasFormComponentActionErrors(['nouveau_service' => ['Le nom du service ne peut pas être fait seulement d\'espaces.']]);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_un_service_d_un_autre_magasin_envoye_par_la_fenetre_n_ajoute_aucune_ligne(): void
    {
        $autreProprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre Boutique'], $autreProprietaire);
        $serviceAutreMagasin = Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$serviceAutreMagasin->id]]);

        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_effacer_le_prix_ttc_d_un_service_remet_son_total_a_zero(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Diagnostic']);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '15.03')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '')
            ->assertSee('0,00 € HT + 0,00 € de TVA')
            ->assertSee('Total HT 0,00 € · TVA 0,00 € · Total TTC 0,00 €');
    }

    private function ouvrirLaCaisseDUnMagasin(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * @return array<string, mixed>
     */
    private function seuleLigne(Testable $component): array
    {
        $lignes = $component->get('data.lignes');
        $this->assertCount(1, $lignes);

        return array_values($lignes)[0];
    }
}
