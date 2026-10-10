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

    private const MESSAGE_SIREN_OBLIGATOIRE = 'Le SIREN (ou le SIRET) est obligatoire pour un client professionnel établi en France.';

    /**
     * Le message contient « : », que Livewire lirait comme le nom d'une
     * règle : il se compare donc aux messages eux-mêmes.
     */
    private const MESSAGE_PROPRE_ENTREPRISE = 'Ce SIREN est celui de votre propre entreprise : une opération entre magasins d\'une même société n\'est pas une vente.';

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
     * plus : ni bouton, ni effet pour une requête forgée.
     */
    public function test_un_vendeur_ne_restaure_pas_un_client_archive(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));
        Filament::setTenant($magasin);
        $client = Client::factory()->trashed()->create();

        Livewire::test(ListClients::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $client)
            ->call('mountTableAction', 'restore', (string) $client->getKey())
            ->call('callMountedTableAction');

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

    /**
     * Le SIREN, ou le SIRET qui le contient, est obligatoire pour un
     * professionnel établi en France.
     */
    public function test_un_professionnel_etabli_en_france_sans_siren_ni_siret_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value])
            ->call('create')
            ->assertHasFormErrors(['siren' => self::MESSAGE_SIREN_OBLIGATOIRE]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Acme Corp']);
    }

    public function test_un_siren_qui_ne_compte_pas_9_chiffres_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siren' => '12345678'])
            ->call('create')
            ->assertHasFormErrors(['siren' => 'Le SIREN compte 9 chiffres, sans espace.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Acme Corp']);
    }

    public function test_un_siret_qui_ne_commence_pas_par_le_siren_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siren' => '123456789', 'siret' => '98765432100011'])
            ->call('create')
            ->assertHasFormErrors(['siret' => 'Le SIRET commence par les 9 chiffres du SIREN.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Acme Corp']);
    }

    public function test_un_siret_qui_commence_par_le_siren_est_accepte(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siren' => '123456789', 'siret' => '12345678900011'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Acme Corp', 'siren' => '123456789', 'siret' => '12345678900011']);
    }

    /**
     * Les 9 premiers chiffres du SIRET sont le SIREN : il s'enregistre
     * aussi.
     */
    public function test_un_siret_seul_enregistre_le_siren_qu_il_contient(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siret' => '12345678900011'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Acme Corp', 'siren' => '123456789', 'siret' => '12345678900011']);
    }

    /**
     * Seul le format est contrôlé : les SIREN fictifs du bac à sable
     * SUPER PDP, qui ne respectent pas la clé de contrôle, sont acceptés.
     */
    public function test_le_siren_d_une_entreprise_du_bac_a_sable_super_pdp_est_accepte(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Burger Queen', 'type_client' => TypeClient::Professionnel->value, 'siren' => '000000002'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Burger Queen', 'siren' => '000000002']);
    }

    /**
     * Le pays propose « Établi en France » : une adresse en Belgique le
     * décoche, et le n° de TVA ou un autre identifiant remplace le SIREN.
     */
    public function test_un_professionnel_etabli_hors_de_france_sans_tva_ni_autre_identifiant_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Brasserie de Namur', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE'])
            ->assertFormSet(['etabli_en_france' => false])
            ->call('create')
            ->assertHasFormErrors(['identifiant_etranger' => 'Indiquez le n° de TVA ou un autre identifiant de ce client établi hors de France.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Brasserie de Namur']);
    }

    public function test_le_numero_de_tva_d_un_professionnel_etranger_s_enregistre_sans_separateurs(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Brasserie de Namur', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE', 'numero_tva' => 'be 0123.456.789'])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::query()->where('nom', 'Brasserie de Namur')->sole();
        $this->assertSame(['BE', false, 'BE0123456789', null], [$client->pays, $client->etabli_en_france, $client->numero_tva, $client->siren]);
    }

    public function test_un_numero_de_tva_qui_ne_commence_pas_par_le_code_du_pays_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Brasserie de Namur', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE', 'numero_tva' => '0123456789'])
            ->call('create')
            ->assertHasFormErrors(['numero_tva' => 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de chiffres ou de lettres, sans espace.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Brasserie de Namur']);
    }

    /**
     * Une entreprise hors de l'Union européenne n'a pas de n° de TVA
     * intracommunautaire : un autre identifiant suffit.
     */
    public function test_un_professionnel_suisse_s_enregistre_avec_un_autre_identifiant(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Horlogerie de Genève', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'CH', 'identifiant_etranger' => 'CHE-123.456.789'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Horlogerie de Genève', 'pays' => 'CH', 'etabli_en_france' => false, 'identifiant_etranger' => 'CHE-123.456.789', 'numero_tva' => null]);
    }

    /**
     * La Guadeloupe est en France : ses entreprises ont un SIREN.
     */
    public function test_un_professionnel_de_guadeloupe_est_propose_comme_etabli_en_france_et_doit_avoir_un_siren(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Distillerie de Basse-Terre', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE'])
            ->fillForm(['pays' => 'GP'])
            ->assertFormSet(['etabli_en_france' => true])
            ->call('create')
            ->assertHasFormErrors(['siren' => self::MESSAGE_SIREN_OBLIGATOIRE]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Distillerie de Basse-Terre']);
    }

    public function test_le_code_du_pays_s_enregistre_en_majuscules(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'pays' => 'fr'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Jean Dupont', 'pays' => 'FR']);
    }

    public function test_un_code_de_pays_qui_ne_compte_pas_deux_lettres_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'pays' => 'F1'])
            ->call('create')
            ->assertHasFormErrors(['pays' => fn (array $reglesEnEchec, array $messages): bool => $messages === ['Le code du pays compte deux lettres : FR, BE, CH...']]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Jean Dupont']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function identifiantsDeLEntrepriseDuMagasin(): array
    {
        return [
            'son SIREN' => [['siren' => '732829320'], 'siren'],
            'le SIRET d\'un de ses établissements' => [['siret' => '73282932000082'], 'siret'],
        ];
    }

    /**
     * Vendre à un autre magasin de la même société n'est pas une vente.
     *
     * @param  array<string, string>  $identifiant
     */
    #[DataProvider('identifiantsDeLEntrepriseDuMagasin')]
    public function test_un_client_de_l_entreprise_du_magasin_est_refuse(array $identifiant, string $champ): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique', 'siren' => '732829320'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Ma Boutique Lyon', 'type_client' => TypeClient::Professionnel->value, ...$identifiant])
            ->call('create')
            ->assertHasFormErrors([$champ => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_PROPRE_ENTREPRISE]]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Ma Boutique Lyon']);
    }

    /**
     * Seul le SIREN du magasin actif est comparé : l'entreprise d'un autre
     * magasin du logiciel peut être cliente.
     */
    public function test_l_entreprise_d_un_autre_magasin_du_logiciel_peut_etre_cliente(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique', 'siren' => '732829320'], User::factory()->create());
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Autre boutique SAS', 'type_client' => TypeClient::Professionnel->value, 'siren' => '732829320'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Autre boutique SAS', 'siren' => '732829320']);
    }

    /**
     * Ses champs disparaissent du formulaire : ils ne restent pas en base
     * pour s'imprimer encore sur ses factures.
     */
    public function test_un_client_repasse_en_particulier_perd_ses_identifiants_d_entreprise(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->professionnel()->create(['telephone' => '0102030405']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['type_client' => TypeClient::Particulier->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame([TypeClient::Particulier, null, null, null], [$client->type_client, $client->siren, $client->siret, $client->numero_tva]);
    }

    /**
     * Un particulier n'a pas d'identifiant d'entreprise, même forcé dans
     * un champ caché.
     */
    public function test_un_particulier_s_enregistre_sans_siren(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'siren' => '123456789'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Jean Dupont', 'siren' => null]);
    }

    public function test_le_mode_de_paiement_prefere_se_choisit_parmi_les_modes_de_paiement(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'mode_paiement_prefere' => 'carte_bancaire'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(ModePaiement::CarteBancaire, Client::query()->where('nom', 'Jean Dupont')->sole()->mode_paiement_prefere);
    }

    public function test_un_mode_de_paiement_prefere_inconnu_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'mode_paiement_prefere' => 'bitcoin'])
            ->call('create')
            ->assertHasFormErrors(['mode_paiement_prefere' => 'La valeur choisie pour mode de paiement préféré n\'est pas valide.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Jean Dupont']);
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
