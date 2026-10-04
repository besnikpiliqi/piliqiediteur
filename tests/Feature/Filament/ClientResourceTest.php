<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\ClientResource\Pages\CreateClient;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Test d'intégration bout en bout : vrai panneau Filament, vraie tenancy
 * par URL, vraie policy -- pas seulement l'Action en isolation (déjà
 * couverte par CreerFactureActionTest et consorts).
 */
class ClientResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_proprietaire_peut_afficher_la_liste_des_clients_de_son_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire)
            ->get("/app/{$magasin->uuid}/clients")
            ->assertSuccessful();
    }

    public function test_un_proprietaire_peut_creer_un_client_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        // Livewire::test() ne passe pas par le pipeline HTTP/middleware :
        // IdentifyTenant (qui appelle Filament::setTenant() depuis l'URL)
        // ne s'exécute jamais. On simule donc manuellement ce que la
        // requête HTTP réelle aurait déjà résolu.
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom' => 'Jean Dupont',
                'type_client' => TypeClient::Particulier->value,
                'email' => 'jean@example.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Jean Dupont',
            'magasin_id' => $magasin->id,
        ]);
    }

    /**
     * Non-régression : $get('type_client') renvoie l'instance de l'enum
     * (pas sa valeur scalaire) une fois le Select live interagi, ce qui
     * rendait silencieusement invisibles -- et donc jamais enregistrés --
     * les champs réservés aux professionnels (voir
     * ClientResource::visibleSiProfessionnel()).
     */
    public function test_un_proprietaire_peut_creer_un_client_professionnel_avec_ses_informations_legales(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom' => 'Acme Corp',
                'type_client' => TypeClient::Professionnel->value,
                'siret' => '12345678900012',
                'numero_tva' => 'FR12345678900',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Acme Corp',
            'siret' => '12345678900012',
            'numero_tva' => 'FR12345678900',
        ]);
    }

    /**
     * Un avoir émis n'est jamais remboursé par ce logiciel (pas
     * d'intégration bancaire) : le crédit restant disponible du client
     * doit rester visible en permanence sur la liste, pas seulement au
     * moment où il est appliqué à une facture.
     */
    public function test_le_solde_crediteur_du_client_est_visible_dans_la_liste(): void
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

        Livewire::test(ListClients::class)
            ->assertSuccessful()
            ->assertSee('60,00');
    }

    public function test_un_utilisateur_dun_autre_magasin_ne_peut_pas_acceder_a_la_liste(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);

        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        // 404, pas 403 : IdentifyTenant masque jusqu'à l'existence même du
        // magasin à un utilisateur qui n'y appartient pas (voir
        // vendor/filament/filament/src/Http/Middleware/IdentifyTenant.php).
        $this->actingAs($proprietaireB)
            ->get("/app/{$magasinA->uuid}/clients")
            ->assertNotFound();
    }
}
