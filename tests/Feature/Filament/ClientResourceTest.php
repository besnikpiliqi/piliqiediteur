<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClientResource\Pages\CreateClient;
use App\Filament\App\Resources\ClientResource\Pages\EditClient;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertHasNoFormErrors()
            ->assertRedirect(ClientResource::getUrl('index'));

        $this->assertDatabaseHas('clients', [
            'nom' => 'Jean Dupont',
            'magasin_id' => $magasin->id,
        ]);
    }

    /**
     * Ses factures et ses relances partent à cette adresse : une adresse
     * que le serveur d'envoi refuserait (avec un commentaire) est refusée
     * dès la fiche.
     */
    public function test_une_adresse_e_mail_que_le_serveur_d_envoi_refuserait_est_refusee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom' => 'Jean Dupont',
                'type_client' => TypeClient::Particulier->value,
                'email' => 'jean(bureau)@example.com',
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail n\'est pas valide.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Jean Dupont']);
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

        $facture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);
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

    /**
     * L'observateur, en lecture seule, n'a pas le bouton d'archivage en
     * masse, et une requête forgée n'archive aucun client.
     */
    public function test_un_observateur_n_archive_pas_de_clients_en_masse(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $observateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Observateur);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);
        $clients = Client::factory()->count(2)->create();

        Livewire::test(ListClients::class)
            ->assertTableBulkActionHidden('archiver')
            ->call('mountTableBulkAction', 'archiver', $clients->modelKeys())
            ->call('callMountedTableBulkAction');

        $clients->each(fn (Client $client) => $this->assertNotSoftDeleted($client));
    }

    private function connecterLeProprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
    }

    /**
     * Le client comptoir garde son nom et son type (voir
     * Client::estComptoir()) : ses champs sont désactivés, et une valeur
     * forcée n'est pas enregistrée. Il n'a pas non plus de bouton
     * « Archiver ».
     */
    public function test_le_client_comptoir_ne_se_renomme_ni_ne_change_de_type_ni_ne_s_archive(): void
    {
        $this->connecterLeProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();

        Livewire::test(EditClient::class, ['record' => $comptoir->getRouteKey()])
            ->assertFormFieldIsDisabled('nom')
            ->assertFormFieldIsDisabled('type_client')
            ->assertActionHidden('archiver')
            ->fillForm(['nom' => 'Vente comptoir', 'type_client' => TypeClient::Professionnel->value, 'notes' => 'Ventes anonymes'])
            ->call('save')
            ->assertHasNoFormErrors();

        $comptoir->refresh();
        $this->assertSame(['Client comptoir', TypeClient::Particulier, 'Ventes anonymes'], [$comptoir->nom, $comptoir->type_client, $comptoir->notes]);
    }

    public function test_l_archivage_en_masse_garde_le_client_comptoir(): void
    {
        $this->connecterLeProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $client = Client::factory()->create();

        Livewire::test(ListClients::class)
            ->callTableBulkAction('archiver', [$comptoir, $client])
            ->assertNotified('1 ligne cochée n\'a pas été archivée');

        $this->assertNotSoftDeleted($comptoir);
        $this->assertSoftDeleted($client);
    }

    /**
     * Un client ne se supprime pas : il s'archive, et son ticket reste
     * lisible et téléchargeable, tel qu'il a été émis.
     */
    public function test_le_ticket_d_un_client_archive_reste_lisible_et_telechargeable(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['nom' => 'Jeanne Martin']);
        $produit = Produit::factory()->create(['nom' => 'Coque', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EnregistrerVenteCaisseAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction('archiver')
            ->assertNotified('Client archivé');

        $this->assertSoftDeleted($client);
        $this->assertSame(['TK-MABOUT-2026-000001', '12.00'], [$ticket->numero, $ticket->total_ttc]);
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('Jeanne Martin')
            ->assertSee('12,00');
        Livewire::test(ListFactures::class)
            ->callTableAction('telecharger_pdf', $ticket)
            ->assertFileDownloaded('ticket-TK-MABOUT-2026-000001.pdf');
    }

    /**
     * Archivé, un client n'est plus proposé pour une vente, ni accepté par
     * une requête forgée ; restauré, il l'est de nouveau.
     */
    public function test_un_client_archive_n_est_plus_propose_puis_le_redevient_une_fois_restaure(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->trashed()->create(['nom' => 'Jeanne Martin']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getSearchResults('Jeanne') === []);
        Livewire::test(CreateFacture::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => ! array_key_exists($client->id, $champ->getOptions()))
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);
        $this->assertSame(0, Facture::query()->count());

        Livewire::test(ListClients::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $client);

        $this->assertNotSoftDeleted($client);
        Livewire::test(Caisse::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getSearchResults('Jeanne') === [$client->id => 'Jeanne Martin']);
        Livewire::test(CreateFacture::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => array_key_exists($client->id, $champ->getOptions()));
    }

    /**
     * Sans la permission d'archiver les clients, pas de restauration non
     * plus.
     */
    public function test_un_vendeur_ne_restaure_pas_un_client_archive(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));
        Filament::setTenant($magasin);
        $client = Client::factory()->trashed()->create();

        Livewire::test(ListClients::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $client);

        $this->assertSoftDeleted($client);
    }

    /**
     * Les messages de validation s'affichent en français, le champ nommé
     * par son libellé.
     */
    public function test_un_client_sans_nom_est_refuse_avec_un_message_en_francais(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => '', 'type_client' => TypeClient::Particulier->value])
            ->call('create')
            ->assertHasFormErrors(['nom' => 'Le champ nom est obligatoire.']);

        $this->assertSame(1, Client::query()->count(), 'Seul le client comptoir existe.');
    }

    /**
     * Facultatif, mais un SIRET saisi compte 14 chiffres.
     */
    public function test_un_siret_qui_ne_compte_pas_14_chiffres_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siret' => 'abc'])
            ->call('create')
            ->assertHasFormErrors(['siret' => 'Le SIRET compte 14 chiffres, sans espace.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Acme Corp']);
    }

    public function test_un_client_professionnel_s_enregistre_sans_siret(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Acme Corp', 'siret' => null]);
    }

    /**
     * Ses champs disparaissent du formulaire : ils ne restent pas en base
     * pour s'imprimer encore sur ses factures.
     */
    public function test_un_client_repasse_en_particulier_perd_son_siret_et_son_numero_de_tva(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->professionnel()->create(['telephone' => '0102030405']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['type_client' => TypeClient::Particulier->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame([TypeClient::Particulier, null, null], [$client->type_client, $client->siret, $client->numero_tva]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function delaisDePaiementInvalides(): array
    {
        return [
            'négatif' => ['-5', 'min'],
            'au-delà d\'un an' => ['366', 'max'],
            'décimal' => ['1.5', 'integer'],
        ];
    }

    #[DataProvider('delaisDePaiementInvalides')]
    public function test_un_delai_de_paiement_hors_de_0_a_365_jours_entiers_est_refuse(string $delai, string $regle): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'delai_paiement_jours' => $delai])
            ->call('create')
            ->assertHasFormErrors(['delai_paiement_jours' => $regle]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Jean Dupont']);
    }
}
