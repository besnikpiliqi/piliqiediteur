<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DevisResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_devis_avec_des_lignes_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->firstOrFail();
        $this->assertSame(StatutDevis::Brouillon, $devis->statut);
        $this->assertSame('100.00', $devis->total_ht);
        $this->assertCount(1, $devis->lignes);
    }

    /**
     * Le crédit disponible d'un client (voir Client::soldeCrediteur()) doit
     * être visible dès sa sélection, avant même de valider -- même si le
     * crédit sera de toute façon appliqué automatiquement à l'émission de
     * la future facture (voir AppliquerAvoirsDisponiblesAction).
     */
    public function test_le_credit_disponible_du_client_est_affiche_sous_le_select(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $client->id])
            ->assertSee('Crédit disponible pour ce client : 60,00');

        $autreClient = Client::factory()->create();

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $autreClient->id])
            ->assertDontSee('Crédit disponible');
    }

    /**
     * Besoin explicite : un magasin sans gestion de stock (ou une ligne de
     * type "main d'œuvre"/service) doit pouvoir créer un devis entièrement
     * à la main, sans jamais sélectionner de produit du catalogue.
     */
    public function test_creer_un_devis_avec_une_ligne_entierement_libre_sans_produit(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    // PU TTC saisi : c'est lui qui fait foi pour une vente
                    // sur une ligne (voir DevisResource::ligneSchema()). 54 €
                    // TTC à 20 % de TVA = 45 € HT.
                    ['description' => 'Main d\'œuvre - pose', 'quantite' => 3, 'prix_unitaire_ttc' => 54, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->firstOrFail();
        $ligne = $devis->lignes->firstOrFail();

        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main d\'œuvre - pose', $ligne->description);
        $this->assertSame('135.00', $devis->total_ht);
    }

    public function test_envoyer_un_devis_brouillon_depuis_la_liste_lui_attribue_un_numero(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create');

        $devis = Devis::query()->firstOrFail();

        Livewire::test(ListDevis::class)
            ->callTableAction('envoyer', $devis);

        $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut);
        $this->assertNotNull($devis->fresh()->numero);
    }

    private function envoyerDevis(Client $client, Produit $produit): Devis
    {
        Livewire::test(CreateDevis::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $devis = Devis::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListDevis::class)->callTableAction('envoyer', $devis);

        return $devis->fresh();
    }

    public function test_accepter_un_devis_envoye_depuis_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    public function test_refuser_un_devis_envoye_depuis_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('refuser', $devis);

        $this->assertSame(StatutDevis::Refuse, $devis->fresh()->statut);
    }

    public function test_convertir_un_devis_accepte_en_facture_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        $facture = Facture::query()->where('devis_id', $devis->id)->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertSame($devis->total_ttc, $facture->total_ttc);
        $this->assertCount(1, $facture->lignes);
    }

    public function test_la_conversion_nest_plus_proposee_une_fois_le_devis_deja_converti(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);
        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }

    public function test_impossible_de_convertir_un_devis_pas_encore_accepte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }

    /**
     * Un devis reste modifiable, lignes comprises, tant qu'il n'a pas été
     * transformé en facture -- y compris une fois accepté.
     */
    public function test_modifier_un_devis_accepte_remplace_les_lignes_et_recalcule_les_totaux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        Livewire::test(EditDevis::class, ['record' => $devis->fresh()->getRouteKey()])
            ->fillForm([
                'client_id' => $autreClient->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            // Filament reste par défaut sur le formulaire après sauvegarde :
            // une fois le devis corrigé, le vendeur doit revenir directement
            // à la liste.
            ->assertRedirect(DevisResource::getUrl('index'));

        $devis->refresh();
        $this->assertSame($autreClient->id, $devis->client_id);
        $this->assertSame(1, $devis->lignes()->count());
        $this->assertSame('250.00', $devis->total_ht);
    }

    public function test_enregistrer_un_devis_garde_le_service_de_ses_lignes_de_service(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $ligne = $devis->fresh()->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertSame('15.00', $ligne->montant_ttc);
    }

    public function test_laction_modifier_disparait_des_quun_devis_est_converti_en_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        Livewire::test(ListDevis::class)->assertTableActionVisible('edit', $devis->fresh());

        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        Livewire::test(ListDevis::class)->assertTableActionHidden('edit', $devis->fresh());
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateDevis::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Nouveau Client Express',
                'type_client' => 'particulier',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Nouveau Client Express']);
    }

    /**
     * Exigence explicite : dès que le type "Professionnel" est choisi
     * dans la modale de création rapide, les champs légaux/postaux
     * (SIRET, TVA, adresse...) doivent apparaître et être enregistrables
     * -- un particulier n'en a pas besoin, voir le test précédent.
     */
    public function test_creer_un_nouveau_client_professionnel_a_la_volee_avec_ses_informations_legales(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateDevis::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Acme Corp',
                'type_client' => 'professionnel',
                'siret' => '12345678900012',
                'numero_tva' => 'FR12345678900',
                'adresse' => '10 rue de la Paix',
                'code_postal' => '75002',
                'ville' => 'Paris',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Acme Corp',
            'siret' => '12345678900012',
            'numero_tva' => 'FR12345678900',
            'adresse' => '10 rue de la Paix',
            'code_postal' => '75002',
            'ville' => 'Paris',
        ]);
    }

    /**
     * Exigence explicite : la création rapide d'un produit depuis une
     * ligne doit proposer les mêmes informations que la page dédiée
     * Produits (prix, TVA, stock...), pas un sous-ensemble réduit --
     * contrairement au client, où un formulaire allégé suffit.
     */
    public function test_creer_un_nouveau_produit_a_la_volee_depuis_une_ligne_de_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateDevis::class)
            ->fillForm(['lignes' => [['description' => 'temp']]])
            ->callFormComponentAction('lignes.0.produit_id', 'createOption', data: [
                'reference' => 'ECR-IP17-PRO',
                'nom' => 'Ecran Iphone 17 Pro',
                'prix_achat_ht' => '80',
                // 180 € TTC à 20 % de TVA = 150 € HT (voir
                // ProduitResource::prixVenteTtcSchema() : seul le TTC est
                // saisi, le HT en est dérivé).
                'prix_vente_ttc' => '180',
                'taux_tva' => '20',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('produits', [
            'reference' => 'ECR-IP17-PRO',
            'nom' => 'Ecran Iphone 17 Pro',
            'prix_achat_ht' => 80,
            'prix_vente_ht' => 150,
        ]);
    }

    /**
     * Exigence explicite : le PU HT reste visible et saisissable sur une
     * ligne (mention nécessaire), à côté du PU TTC qui, lui, fait référence
     * pour le calcul (voir CalculateurLigne::calculerDepuisTtc()).
     */
    public function test_le_pu_ht_et_le_pu_ttc_dune_ligne_restent_tous_deux_visibles(): void
    {
        $champs = collect(DevisResource::ligneSchema())
            ->filter(fn ($component) => in_array($component->getName(), ['prix_unitaire_ht', 'prix_unitaire_ttc'], true));

        $this->assertCount(2, $champs);
        $champs->each(fn ($champ) => $this->assertInstanceOf(TextInput::class, $champ));
    }

    /**
     * Saisir le PU TTC d'une ligne doit calculer le PU HT à sa place, au
     * centime -- même principe que pour Caisse (voir CaisseTest), partagé
     * via DevisResource::ligneSchema() et donc valable aussi pour Facture.
     */
    public function test_saisir_le_prix_ttc_dune_ligne_calcule_le_prix_ht(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        $component = Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['description' => 'Pose', 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ]);

        $cle = array_key_first($component->get('data.lignes'));
        $component->set("data.lignes.{$cle}.prix_unitaire_ttc", '12');

        $this->assertSame(10.0, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ht"));
    }

    /**
     * Changer le taux de TVA d'une ligne doit recalculer le HT à partir du
     * TTC (qui fait référence), jamais l'inverse -- sans quoi le prix TTC
     * visé changerait sous les yeux du vendeur au seul changement de taux.
     */
    public function test_changer_le_taux_de_tva_dune_ligne_recalcule_le_ht_sans_changer_le_ttc_affiche(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        $component = Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['description' => 'Pose', 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ]);

        $cle = array_key_first($component->get('data.lignes'));
        $component->set("data.lignes.{$cle}.prix_unitaire_ttc", '12');
        $component->set("data.lignes.{$cle}.taux_tva", '5.5');

        $this->assertSame(12.0, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ttc"));
        // 12,00 / 1,055 = 11,374... -> 11,37 € HT, au centime.
        $this->assertSame(11.37, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ht"));
    }

    /**
     * Une douchette USB se contente de "taper" les chiffres scannés dans
     * le champ actif : il faut donc que la recherche du produit porte
     * aussi sur le code-barres, pas seulement sur le nom.
     */
    public function test_rechercher_un_produit_par_code_barres_le_retrouve(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $produitCible = Produit::factory()->create(['nom' => 'Chaise de bureau', 'code_barres' => '3456789012345']);
        Produit::factory()->create(['nom' => 'Autre produit', 'code_barres' => '0000000000000']);

        $selectProduit = collect(DevisResource::ligneSchema())
            ->first(fn ($component) => $component instanceof Select && $component->getName() === 'produit_id');

        $resultats = $selectProduit->getSearchResults('3456789012345');

        $this->assertSame([$produitCible->id => 'Chaise de bureau ('.$produitCible->reference.')'], $resultats);
    }

    /**
     * Un devis converti en facture n'est plus modifiable : sa fiche reste
     * le seul endroit où voir ses lignes, avec le HT, la TVA et le TTC de
     * chacune et le récapitulatif par taux, comme sur la facture.
     */
    public function test_la_fiche_du_devis_montre_chaque_ligne_et_la_tva_par_taux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]);

        $this->assertSame(['76.66', '13.34', '90.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('66,66')
            ->assertSee('13,34')
            ->assertSee('76,66')
            ->assertSee('TVA par taux')
            ->assertSee('20 %')
            ->assertSee('0 %');
    }
}
