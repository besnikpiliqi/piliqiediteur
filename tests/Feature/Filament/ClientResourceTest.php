<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClientResource\Pages\CreateClient;
use App\Filament\App\Resources\ClientResource\Pages\EditClient;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Filament\App\Resources\ClientResource\Pages\ViewClient;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private const MESSAGE_PROPRE_NUMERO_TVA = 'Ce n° de TVA est celui de votre propre entreprise : une opération entre magasins d\'une même société n\'est pas une vente.';

    private const URL_VIES = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /**
     * Ce que la fiche dit de la vérification que crée consultationValide().
     */
    private const PREUVE_VIES = 'Le n° DE123456789 est valide sur VIES selon la consultation WAPI1 du 09/10/2026 à 14:32.';

    public function test_un_proprietaire_peut_afficher_la_liste_des_clients_de_son_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire)
            ->get("/app/{$magasin->uuid}/clients")
            ->assertSuccessful();
    }

    public function test_un_proprietaire_peut_creer_un_client_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom' => 'Acme Corp',
                'type_client' => TypeClient::Professionnel->value,
                'siret' => '12121212000012',
                'numero_tva' => 'FR12345678900',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Acme Corp',
            'siret' => '12121212000012',
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        Livewire::test(ListClients::class)
            ->assertSuccessful()
            ->assertSee('60,00');
    }

    public function test_un_utilisateur_dun_autre_magasin_ne_peut_pas_acceder_a_la_liste(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A'], $proprietaireA);

        $proprietaireB = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, null, [
            'name' => 'Léa', 'email' => 'lea@example.test',
        ]);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);
        $clients = Client::factory()->count(2)->create();

        Livewire::test(ListClients::class)
            ->assertTableBulkActionHidden('archiver')
            ->call('mountTableBulkAction', 'archiver', $clients->modelKeys())
            ->call('callMountedTableBulkAction');

        $clients->each(fn (Client $client) => $this->assertNotSoftDeleted($client));
    }

    /**
     * La liste des clients se cherche sans tenir compte de la casse ni des
     * accents, et ne montre jamais l'homonyme d'un autre magasin. Les
     * e-mails sont fixés, sans « helene » : la recherche porte aussi sur
     * l'e-mail, et un e-mail tiré au hasard pourrait faire trouver Paul, ou
     * trouver Hélène autrement que par son nom.
     */
    public function test_la_recherche_de_la_liste_des_clients_ignore_la_casse_et_les_accents(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $homonymeDUnAutreMagasin = Client::factory()->create(['nom' => 'Hélène Martin', 'email' => 'h.martin@example.net']);
        $this->connecterLeProprietaire();
        $helene = Client::factory()->create(['nom' => 'Hélène Martin', 'email' => 'h.martin@example.net']);
        $paul = Client::factory()->create(['nom' => 'Paul Durand', 'email' => 'p.durand@example.net']);

        Livewire::test(ListClients::class)
            ->searchTable('HELENE')
            ->assertCanSeeTableRecords([$helene])
            ->assertCanNotSeeTableRecords([$paul, $homonymeDUnAutreMagasin]);
    }

    /**
     * Le client de la caisse se cherche, lui aussi, sans casse ni accents,
     * avec des e-mails fixés sans « helene » pour la même raison.
     */
    public function test_la_recherche_du_client_de_la_caisse_ignore_la_casse_et_les_accents(): void
    {
        $this->connecterLeResponsable();
        $helene = Client::factory()->create(['nom' => 'Hélène Martin', 'email' => 'h.martin@example.net']);
        Client::factory()->create(['nom' => 'Paul Durand', 'email' => 'p.durand@example.net']);

        Livewire::test(Caisse::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getSearchResults('helene') === [$helene->id => 'Hélène Martin']);
    }

    /**
     * Le client d'une facture se choisit dans une liste recherchée côté
     * serveur (relationProposable()), elle aussi sans casse ni accents,
     * avec des e-mails fixés sans « helene ».
     */
    public function test_la_recherche_du_client_d_une_facture_ignore_la_casse_et_les_accents(): void
    {
        $this->connecterLeResponsable();
        $helene = Client::factory()->create(['nom' => 'Hélène Martin', 'email' => 'h.martin@example.net']);
        Client::factory()->create(['nom' => 'Paul Durand', 'email' => 'p.durand@example.net']);

        Livewire::test(CreateFacture::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getSearchResults('HÉLENE') === [$helene->id => 'Hélène Martin']);
    }

    /**
     * Une vérification qui a trouvé valide le n° DE123456789 du client, le
     * 09/10/2026 à 14:32 à Paris (12:32 en UTC), sous le n° de consultation
     * WAPI1.
     */
    private function consultationValide(Client $client): ConsultationVies
    {
        return ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'identifiant_consultation' => 'WAPI1',
            'consultee_le' => CarbonImmutable::parse('2026-10-09 12:32:10', 'UTC'),
            'reponse' => '{"valid":true,"requestIdentifier":"WAPI1"}',
        ]);
    }

    /**
     * Le responsable de test d'un nouveau magasin, connecté : il vend, ce
     * que le propriétaire ne fait jamais.
     */
    private function connecterLeResponsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);
    }

    private function connecterLeProprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
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
        $this->connecterLeResponsable();
        $client = Client::factory()->create(['nom' => 'Jeanne Martin']);
        $produit = Produit::factory()->create(['nom' => 'Coque', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

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
        $this->connecterLeResponsable();
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
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
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siren' => '121212120', 'siret' => '98765432100011'])
            ->call('create')
            ->assertHasFormErrors(['siret' => 'Le SIRET commence par les 9 chiffres du SIREN.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Acme Corp']);
    }

    public function test_un_siret_qui_commence_par_le_siren_est_accepte(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siren' => '121212120', 'siret' => '12121212000020'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Acme Corp', 'siren' => '121212120', 'siret' => '12121212000020']);
    }

    /**
     * Les 9 premiers chiffres du SIRET sont le SIREN : il s'enregistre
     * aussi.
     */
    public function test_un_siret_seul_enregistre_le_siren_qu_il_contient(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Acme Corp', 'type_client' => TypeClient::Professionnel->value, 'siret' => '12121212000020'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Acme Corp', 'siren' => '121212120', 'siret' => '12121212000020']);
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
            ->assertHasFormErrors(['numero_tva' => 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de 2 à 13 chiffres ou lettres ; les espaces, points et tirets ne comptent pas.']);

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

    /**
     * Une entreprise dont l'adresse est en France y est établie : « Établi
     * en France » ne se décoche pas, même forcé, et son SIREN reste
     * obligatoire.
     */
    public function test_une_entreprise_dont_l_adresse_est_en_france_y_est_etablie_et_doit_avoir_un_siren(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Garage Martin', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'FR'])
            ->assertFormFieldIsDisabled('etabli_en_france')
            ->set('data.etabli_en_france', false)
            ->set('data.identifiant_etranger', 'x')
            ->call('create')
            ->assertHasFormErrors(['siren' => self::MESSAGE_SIREN_OBLIGATOIRE]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Garage Martin']);
    }

    /**
     * Une entreprise étrangère qui a un établissement en France se coche
     * « Établi en France » : son SIREN l'identifie.
     */
    public function test_une_entreprise_etrangere_qui_a_un_etablissement_en_france_s_enregistre_avec_son_siren(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Brasserie de Namur', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE'])
            ->assertFormFieldIsEnabled('etabli_en_france')
            ->fillForm(['etabli_en_france' => true, 'siren' => '121212120'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Brasserie de Namur', 'pays' => 'BE', 'etabli_en_france' => true, 'siren' => '121212120']);
    }

    /**
     * Passer en professionnel propose « Établi en France » depuis le pays
     * de la fiche : une adresse en Belgique le décoche, et le n° de TVA
     * suffit.
     */
    public function test_un_particulier_a_l_etranger_qui_passe_en_professionnel_s_enregistre_avec_son_seul_numero_de_tva(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['nom' => 'Brasserie de Namur', 'pays' => 'BE', 'telephone' => '0102030405']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['type_client' => TypeClient::Professionnel->value])
            ->assertFormSet(['etabli_en_france' => false])
            ->fillForm(['numero_tva' => 'BE0123456789'])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame([TypeClient::Professionnel, false, 'BE0123456789', null], [$client->type_client, $client->etabli_en_france, $client->numero_tva, $client->siren]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paysDesAdresses(): array
    {
        return [
            'adresse en France' => ['FR'],
            'adresse à l\'étranger' => ['BE'],
        ];
    }

    /**
     * « Établi en France » vaut oui ou non : vide, forcé par une requête,
     * il est refusé par le formulaire au lieu de casser l'enregistrement.
     */
    #[DataProvider('paysDesAdresses')]
    public function test_un_etablissement_en_france_force_a_vide_est_refuse_par_le_formulaire(string $pays): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Garage Martin', 'type_client' => TypeClient::Professionnel->value, 'pays' => $pays, 'identifiant_etranger' => 'X'])
            ->set('data.etabli_en_france', null)
            ->call('create')
            ->assertHasFormErrors(['etabli_en_france' => 'required']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Garage Martin']);
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
            'son SIREN' => [['siren' => '100000009'], 'siren'],
            'le SIRET d\'un de ses établissements' => [['siret' => '10000000900025'], 'siret'],
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'siren' => '100000009'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Ma Boutique Lyon', 'type_client' => TypeClient::Professionnel->value, ...$identifiant])
            ->call('create')
            ->assertHasFormErrors([$champ => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_PROPRE_ENTREPRISE]]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Ma Boutique Lyon']);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function numerosDeTvaDeLEntrepriseDuMagasin(): array
    {
        return [
            'son n° de TVA, saisi avec des espaces' => ['FR88100000009', 'fr 88 100 000 009'],
            'le n° de TVA français que forme son SIREN' => [null, 'FR88100000009'],
        ];
    }

    /**
     * Le n° de TVA identifie aussi l'entreprise du magasin : le sien, ou
     * le n° français formé de son SIREN (FR, clé, SIREN), est refusé, même
     * pour une adresse à l'étranger, où le SIREN n'est pas demandé.
     */
    #[DataProvider('numerosDeTvaDeLEntrepriseDuMagasin')]
    public function test_un_client_qui_porte_le_numero_de_tva_de_l_entreprise_du_magasin_est_refuse(?string $numeroTvaDuMagasin, string $numeroTva): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'siren' => '100000009', 'numero_tva' => $numeroTvaDuMagasin], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Ma Boutique Bruxelles', 'type_client' => TypeClient::Professionnel->value, 'pays' => 'BE', 'numero_tva' => $numeroTva])
            ->call('create')
            ->assertHasFormErrors(['numero_tva' => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_PROPRE_NUMERO_TVA]]);

        $this->assertDatabaseMissing('clients', ['nom' => 'Ma Boutique Bruxelles']);
    }

    /**
     * Seul le SIREN du magasin actif est comparé : l'entreprise d'un autre
     * magasin du logiciel peut être cliente.
     */
    public function test_l_entreprise_d_un_autre_magasin_du_logiciel_peut_etre_cliente(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique', 'siren' => '100000009']);
        $this->connecterLeProprietaire();

        Livewire::test(CreateClient::class)
            ->fillForm(['nom' => 'Autre boutique SAS', 'type_client' => TypeClient::Professionnel->value, 'siren' => '100000009'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Autre boutique SAS', 'siren' => '100000009']);
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
            ->fillForm(['nom' => 'Jean Dupont', 'type_client' => TypeClient::Particulier->value, 'siren' => '121212120'])
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

    /**
     * VIES ne vérifie que les n° de TVA de l'Union (et d'Irlande du Nord)
     * : le bouton n'apparaît, dans la liste comme sur la fiche, en lecture
     * seule ou en modification, que pour un professionnel qui en a un.
     */
    public function test_le_bouton_verifier_sur_vies_n_apparait_que_pour_un_professionnel_de_l_union(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        $particulier = Client::factory()->create();
        $suisse = Client::factory()->etranger('CH', 'CHE123456789')->create();

        Livewire::test(ListClients::class)
            ->assertTableActionVisible('verifier_sur_vies', $allemand)
            ->assertTableActionHidden('verifier_sur_vies', $particulier)
            ->assertTableActionHidden('verifier_sur_vies', $suisse);
        Livewire::test(ViewClient::class, ['record' => $allemand->getRouteKey()])->assertActionVisible('verifier_sur_vies');
        Livewire::test(ViewClient::class, ['record' => $particulier->getRouteKey()])->assertActionHidden('verifier_sur_vies');
        Livewire::test(EditClient::class, ['record' => $allemand->getRouteKey()])->assertActionVisible('verifier_sur_vies');
        Livewire::test(EditClient::class, ['record' => $particulier->getRouteKey()])->assertActionHidden('verifier_sur_vies');
    }

    /**
     * Vérifier conserve une preuve dans la fiche du client : sans le droit
     * de le modifier, l'observateur n'a pas le bouton, et une requête
     * forgée n'interroge pas VIES.
     */
    public function test_un_observateur_ne_verifie_pas_sur_vies(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->membre($magasin, RoleMagasin::Observateur));
        Filament::setTenant($magasin);
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['valid' => true])]);

        Livewire::test(ListClients::class)
            ->assertTableActionHidden('verifier_sur_vies', $allemand)
            ->call('mountTableAction', 'verifier_sur_vies', (string) $allemand->getKey())
            ->call('callMountedTableAction');

        Http::assertNothingSent();
        $this->assertSame(0, ConsultationVies::query()->count());
    }

    public function test_un_numero_valide_sur_vies_est_annonce_et_apparait_dans_les_verifications_de_la_fiche(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->travelTo('2026-10-09 14:32:10');
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['countryCode' => 'DE', 'vatNumber' => '121212120', 'valid' => true, 'requestIdentifier' => 'WAPIAAAAW1234567', 'name' => 'ACME GMBH', 'address' => '---'])]);

        Livewire::test(EditClient::class, ['record' => $allemand->getRouteKey()])
            ->callAction('verifier_sur_vies')
            ->assertNotified('N° de TVA DE123456789 valide sur VIES (consultation WAPIAAAAW1234567 du 09/10/2026 à 14:32).')
            ->assertSeeText('Le n° DE123456789 est valide sur VIES selon la consultation WAPIAAAAW1234567 du 09/10/2026 à 14:32.')
            ->assertSeeText('ACME GMBH');

        $this->assertSame(1, $allemand->consultationsVies()->count());
    }

    public function test_un_numero_inconnu_de_vies_laisse_la_vente_a_la_tva_francaise(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE999999999')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['countryCode' => 'DE', 'vatNumber' => '999999999', 'valid' => false])]);

        Livewire::test(ListClients::class)
            ->callTableAction('verifier_sur_vies', $allemand)
            ->assertNotified('N° de TVA DE999999999 inconnu de VIES : la vente reste à la TVA française.');

        $this->assertFalse($allemand->consultationsVies()->sole()->valide);
    }

    public function test_vies_indisponible_est_annonce_sans_rien_conserver(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]], 500)]);

        Livewire::test(ListClients::class)
            ->callTableAction('verifier_sur_vies', $allemand)
            ->assertNotified('Le service européen VIES ne répond pas pour ce pays : réessayez dans quelques minutes. Sans n° de TVA vérifié, la vente reste à la TVA française.');

        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * VIES vérifie le n° enregistré sur la fiche : tant que le formulaire
     * en montre un autre, rien n'est vérifié, et l'écran demande de
     * l'enregistrer d'abord.
     */
    public function test_un_numero_modifie_sans_etre_enregistre_n_est_pas_verifie_sur_vies(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['countryCode' => 'DE', 'vatNumber' => '121212120', 'valid' => true])]);

        Livewire::test(EditClient::class, ['record' => $allemand->getRouteKey()])
            ->fillForm(['numero_tva' => 'DE 999 999 999'])
            ->callAction('verifier_sur_vies')
            ->assertNotified('Enregistrez d\'abord le n° de TVA modifié : VIES vérifie le n° enregistré sur la fiche, DE123456789.')
            ->assertFormSet(['numero_tva' => 'DE 999 999 999']);

        Http::assertNothingSent();
        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * Le n° du formulaire se compare tel qu'il s'enregistre : ressaisi
     * avec ses séparateurs, c'est le n° enregistré, qui se vérifie.
     */
    public function test_le_numero_enregistre_ressaisi_avec_ses_separateurs_se_verifie_sur_vies(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->travelTo('2026-10-09 14:32:10');
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['countryCode' => 'DE', 'vatNumber' => '121212120', 'valid' => true, 'requestIdentifier' => 'WAPI1'])]);

        Livewire::test(EditClient::class, ['record' => $allemand->getRouteKey()])
            ->fillForm(['numero_tva' => 'de 123.456.789'])
            ->callAction('verifier_sur_vies')
            ->assertNotified('N° de TVA DE123456789 valide sur VIES (consultation WAPI1 du 09/10/2026 à 14:32).');

        $this->assertSame('DE123456789', $allemand->consultationsVies()->sole()->numero_tva);
    }

    /**
     * Une vérification VIES est une preuve fiscale : qui voit les clients
     * la consulte sur leur fiche, même sans le droit de les modifier, donc
     * sans « Modifier » ni « Vérifier sur VIES ». L'expert-comptable, sans
     * rôle de magasin, la consulte de même.
     *
     * @param  list<string>|null  $autorisations
     */
    #[DataProvider('rolesQuiVoientLesClientsSansLesModifier')]
    public function test_un_membre_qui_voit_les_clients_sans_les_modifier_consulte_leurs_verifications_vies(?RoleMagasin $role, ?array $autorisations): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $role === null ? $this->expertComptable($magasin) : $this->membre($magasin, $role, $autorisations);
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->consultationValide($allemand);

        $this->actingAs($membre)
            ->get(ClientResource::getUrl('view', ['record' => $allemand, 'tenant' => $magasin]))
            ->assertOk()
            ->assertSeeText(self::PREUVE_VIES);
        Filament::setTenant($magasin);
        Livewire::test(ViewClient::class, ['record' => $allemand->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('verifier_sur_vies');
    }

    /**
     * @return array<string, array{0: RoleMagasin|null, 1: list<string>|null}>
     */
    public static function rolesQuiVoientLesClientsSansLesModifier(): array
    {
        return [
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable()],
            'observateur' => [RoleMagasin::Observateur, null],
            'expert-comptable' => [null, null],
        ];
    }

    /**
     * Un magasin suspendu ou fermé reste consultable : ses vérifications
     * VIES aussi, mais il n'en fait plus, même par une requête forgée.
     *
     * @param  array<string, mixed>  $situation
     */
    #[DataProvider('situationsEnLectureSeule')]
    public function test_les_verifications_vies_restent_consultables_dans_un_magasin_en_lecture_seule(array $situation): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->consultationValide($allemand);
        $magasin->forceFill($situation)->save();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['countryCode' => 'DE', 'vatNumber' => '121212120', 'valid' => true])]);

        $this->actingAs($proprietaire)
            ->get(ClientResource::getUrl('view', ['record' => $allemand, 'tenant' => $magasin]))
            ->assertOk()
            ->assertSeeText(self::PREUVE_VIES);
        Filament::setTenant($magasin);
        Livewire::test(ViewClient::class, ['record' => $allemand->getRouteKey()])
            ->assertActionHidden('verifier_sur_vies')
            ->call('mountAction', 'verifier_sur_vies')
            ->call('callMountedAction');

        Http::assertNothingSent();
        $this->assertSame(1, ConsultationVies::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function situationsEnLectureSeule(): array
    {
        return [
            'suspendu' => [['suspendu_le' => '2026-10-01 00:00:00']],
            'fermé' => [['statut' => StatutMagasin::Ferme]],
        ];
    }

    /**
     * Une fiche archivée reste lisible : dans la liste des archivés, sa
     * ligne propose « Voir », sans « Modifier » ni « Vérifier sur VIES »,
     * et sa fiche, qui se dit archivée, montre ses vérifications VIES.
     */
    public function test_les_verifications_vies_d_un_client_archive_restent_consultables(): void
    {
        $this->connecterLeProprietaire();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->trashed()->create();
        $this->consultationValide($allemand);

        Livewire::test(ListClients::class)
            ->filterTable('trashed', false)
            ->assertTableActionVisible('view', $allemand)
            ->assertTableActionHidden('edit', $allemand)
            ->assertTableActionHidden('verifier_sur_vies', $allemand);
        $this->get(ClientResource::getUrl('view', ['record' => $allemand]))
            ->assertOk()
            ->assertSeeText('Client archivé : il n\'est plus proposé pour une nouvelle vente. Restaurez-le depuis la liste des clients archivés pour le modifier.')
            ->assertSeeText(self::PREUVE_VIES);
    }

    /**
     * La fiche en lecture seule trouve un client archivé, jamais celui
     * d'un autre magasin : 404, sans rien dire de son existence.
     */
    public function test_la_fiche_du_client_archive_d_un_autre_magasin_est_introuvable(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $clientA = Client::factory()->trashed()->create();
        $proprietaireB = User::factory()->create();
        $magasinB = $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertSame($magasinA->id, $clientA->magasin_id);
        $this->actingAs($proprietaireB)
            ->get(ClientResource::getUrl('view', ['record' => $clientA, 'tenant' => $magasinB]))
            ->assertNotFound();
    }

    /**
     * Le nom que renvoie VIES vient de l'extérieur : la fiche l'affiche
     * comme du texte, jamais comme du HTML.
     */
    public function test_le_nom_renvoye_par_vies_s_affiche_comme_du_texte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create();
        ConsultationVies::forceCreate([
            'client_id' => $allemand->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'nom' => '<script>alert(1)</script>',
            'consultee_le' => CarbonImmutable::parse('2026-10-09 12:32:10', 'UTC'),
            'reponse' => '{"valid":true}',
        ]);

        $reponse = $this->actingAs($proprietaire)->get(ClientResource::getUrl('edit', ['record' => $allemand, 'tenant' => $magasin]));

        $reponse->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
