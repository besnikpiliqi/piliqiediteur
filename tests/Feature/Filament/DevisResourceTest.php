<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
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
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
                    ['description' => 'Main d\'œuvre - pose', 'quantite' => 3, 'prix_unitaire_ht' => 45, 'taux_tva' => 20, 'remise_pourcentage' => 0],
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
     * transformé en facture -- y compris une fois accepté, contrairement
     * à l'ancien comportement qui ne l'autorisait qu'en brouillon.
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
            ->assertHasNoFormErrors();

        $devis->refresh();
        $this->assertSame($autreClient->id, $devis->client_id);
        $this->assertSame(1, $devis->lignes()->count());
        $this->assertSame('250.00', $devis->total_ht);
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
                'prix_vente_ht' => '150',
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
}
