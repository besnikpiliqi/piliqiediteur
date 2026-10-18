<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le tableau des clients (point 22), qu'ouvre la loupe du champ « Client »
 * à la caisse, sur une facture ou un devis.
 */
class SelecteurDeClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_recherche_trouve_un_client_par_siren_email_ou_telephone_sans_accents(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $elephant = Client::factory()->create([
            'nom' => 'Société Éléphant', 'type_client' => TypeClient::Professionnel, 'siren' => '732829320', 'numero_tva' => 'FR44732829320',
            'email' => 'compta@elephant.fr', 'telephone' => '01 23 45 67 89', 'ville' => 'Besançon', 'code_postal' => '25000',
        ]);
        $dupont = Client::factory()->create([
            'nom' => 'Jean Dupont', 'email' => 'jean.dupont@exemple.fr', 'telephone' => '06 12 34 56 78', 'ville' => 'Lyon', 'code_postal' => '69001',
        ]);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();

        $tableau = Livewire::test(SelecteurDeClients::class);

        foreach (['elephant', '732829320', 'fr44732829320', 'COMPTA@', '01 23 45', 'besancon', '25000'] as $recherche) {
            $tableau->searchTable($recherche)
                ->assertCanSeeTableRecords([$elephant])
                ->assertCanNotSeeTableRecords([$dupont, $comptoir]);
        }

        foreach (['dupont', 'JEAN.DUPONT@exemple', '06 12 34', '69001'] as $recherche) {
            $tableau->searchTable($recherche)
                ->assertCanSeeTableRecords([$dupont])
                ->assertCanNotSeeTableRecords([$elephant, $comptoir]);
        }
    }

    /**
     * Du plus récent au plus ancien par défaut, et dans l'autre sens à la
     * demande ; le client comptoir reste toujours en tête.
     */
    public function test_les_clients_se_trient_du_plus_recent_au_plus_ancien(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00'));
        $ancien = Client::factory()->create(['nom' => 'Albert Ancien']);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
        $recent = Client::factory()->create(['nom' => 'Zoé Récente']);
        $this->travelBack();

        Livewire::test(SelecteurDeClients::class)
            ->assertCanSeeTableRecords([$comptoir, $recent, $ancien], inOrder: true)
            ->sortTable('created_at', 'asc')
            ->assertCanSeeTableRecords([$comptoir, $ancien, $recent], inOrder: true);
    }

    /**
     * Un clic sur un client l'envoie à la page : la caisse le pose dans
     * son champ « Client », présélectionne son mode de paiement préféré et
     * ferme la fenêtre de la loupe.
     */
    public function test_choisir_un_client_le_pose_dans_la_caisse_avec_son_mode_prefere(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $client = Client::factory()->create(['nom' => 'Lucie Bernard', 'mode_paiement_prefere' => ModePaiement::CarteBancaire]);

        Livewire::test(SelecteurDeClients::class)
            ->call('choisir', $client->getKey())
            ->assertDispatched(SelecteurDeClients::EVENEMENT, clientId: $client->getKey());

        $caisse = Livewire::test(Caisse::class)
            ->mountFormComponentAction('client_id', 'chercherUnClient')
            ->assertSeeLivewire(SelecteurDeClients::class)
            ->call('recevoirLeClientChoisi', $client->getKey())
            ->assertFormComponentActionNotMounted('client_id', 'chercherUnClient')
            ->assertSet('data.client_id', $client->getKey());

        $this->assertSame([ModePaiement::CarteBancaire->value], array_column($caisse->get('data.parts'), 'mode'));
    }

    /**
     * Le client comptoir reste choisi à l'ouverture de la caisse, apparaît
     * en tête du tableau devant des clients plus récents, et reste choisi
     * quand la loupe se ferme sans choix.
     */
    public function test_le_client_comptoir_reste_choisi_par_defaut(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $this->travelTo(now()->addDay());
        $recent = Client::factory()->create(['nom' => 'Lucie Bernard']);

        Livewire::test(SelecteurDeClients::class)
            ->assertCanSeeTableRecords([$comptoir, $recent], inOrder: true)
            ->assertSee('Vente anonyme');

        Livewire::test(Caisse::class)
            ->assertSet('data.client_id', $comptoir->getKey())
            ->mountFormComponentAction('client_id', 'chercherUnClient')
            ->unmountFormComponentAction()
            ->assertSet('data.client_id', $comptoir->getKey());
    }

    /**
     * Le même e-mail dans deux magasins : la recherche, la liste du champ,
     * le clic sur une rangée et la page ne connaissent que le client du
     * magasin, jamais un client archivé ni celui de l'autre magasin.
     */
    public function test_un_client_archive_ou_d_un_autre_magasin_n_apparait_jamais(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $autreMagasin = Client::factory()->create(['nom' => 'Martin Concurrence', 'email' => 'contact@martin.fr']);
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $actif = Client::factory()->create(['nom' => 'Martin Boutique', 'email' => 'contact@martin.fr']);
        $archive = Client::factory()->create(['nom' => 'Martin Ancien', 'email' => 'contact@martin.fr']);
        $archive->delete();

        Livewire::test(SelecteurDeClients::class)
            ->assertCanNotSeeTableRecords([$archive, $autreMagasin])
            ->searchTable('contact@martin.fr')
            ->assertCanSeeTableRecords([$actif])
            ->assertCanNotSeeTableRecords([$archive, $autreMagasin])
            ->call('choisir', $autreMagasin->getKey())
            ->assertNotified(ClientResource::CLIENT_INTROUVABLE)
            ->call('choisir', $archive->getKey())
            ->assertNotified(ClientResource::CLIENT_INTROUVABLE)
            ->assertNotDispatched(SelecteurDeClients::EVENEMENT);

        $caisse = Livewire::test(Caisse::class)
            ->call('recevoirLeClientChoisi', $autreMagasin->getKey())
            ->assertNotified(ClientResource::CLIENT_INTROUVABLE)
            ->call('recevoirLeClientChoisi', $archive->getKey())
            ->assertNotified(ClientResource::CLIENT_INTROUVABLE)
            ->assertSet('data.client_id', $comptoir->getKey());

        /** @var Select $champ */
        $champ = $caisse->instance()->form->getComponent('data.client_id');
        $this->assertSame([$actif->getKey() => 'Martin Boutique'], $champ->getSearchResults('CONTACT@MARTIN'));
    }

    /**
     * « Nouveau client » crée la fiche dans le magasin et la choisit
     * aussitôt ; il n'est proposé qu'à qui peut créer un client.
     */
    public function test_nouveau_client_cree_le_client_dans_le_magasin_et_le_choisit(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();

        $tableau = Livewire::test(SelecteurDeClients::class)
            ->callTableAction('nouveauClient', data: ['nom' => 'Lucie Bernard', 'type_client' => TypeClient::Particulier->value, 'email' => 'lucie@exemple.fr'])
            ->assertHasNoTableActionErrors();

        $client = Client::query()->where('nom', 'Lucie Bernard')->sole();
        $this->assertSame($magasin->getKey(), $client->magasin_id);
        $tableau->assertDispatched(SelecteurDeClients::EVENEMENT, clientId: $client->getKey());

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::CLIENT_CREATE]))));

        Livewire::test(SelecteurDeClients::class)->assertTableActionHidden('nouveauClient');
    }

    /**
     * La colonne et le filtre du crédit d'avoir : 60,00 € pour le client
     * dont l'avoir n'a encore rien réglé, rien pour celui dont le crédit
     * a réglé un autre achat, ni pour celui qui n'a aucun avoir.
     */
    public function test_le_filtre_avec_un_credit_d_avoir_ne_garde_que_les_clients_qui_en_ont_un(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $avecCredit = Client::factory()->create(['nom' => 'Lucie Bernard']);
        $creditUtilise = Client::factory()->create(['nom' => 'Paul Martin']);
        $sansAvoir = Client::factory()->create(['nom' => 'Jean Dupont']);
        $this->retournerUnAchatEntier($avecCredit, $produit);
        $this->retournerUnAchatEntier($creditUtilise, $produit);
        self::vendreAuComptant(self::auteur(), $creditUtilise, [['produit_id' => $produit->getKey(), 'quantite' => 1]]);

        Livewire::test(SelecteurDeClients::class)
            ->assertTableColumnFormattedStateSet('credit_d_avoir', '60,00 €', $avecCredit)
            ->assertTableColumnFormattedStateNotSet('credit_d_avoir', '60,00 €', $creditUtilise)
            ->filterTable('avec_credit')
            ->assertCanSeeTableRecords([$avecCredit])
            ->assertCanNotSeeTableRecords([$creditUtilise, $sansAvoir]);
    }

    /**
     * La loupe du champ « Client » d'une facture et d'un devis ouvre le
     * même tableau, et sa liste cherche aussi par SIREN.
     */
    public function test_la_loupe_sert_aussi_aux_factures_et_aux_devis(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $client = Client::factory()->create(['nom' => 'Société Éléphant', 'type_client' => TypeClient::Professionnel, 'siren' => '732829320']);

        foreach ([CreateFacture::class, CreateDevis::class] as $page) {
            $formulaire = Livewire::test($page)
                ->mountFormComponentAction('client_id', 'chercherUnClient')
                ->assertSeeLivewire(SelecteurDeClients::class)
                ->call('recevoirLeClientChoisi', $client->getKey())
                ->assertFormComponentActionNotMounted('client_id', 'chercherUnClient')
                ->assertSet('data.client_id', $client->getKey());

            /** @var Select $champ */
            $champ = $formulaire->instance()->form->getComponent('data.client_id');
            $this->assertSame([$client->getKey() => 'Société Éléphant'], $champ->getSearchResults('732829320'));
        }
    }

    private function ouvrirLeMagasinEnTantQueProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Un achat de ce produit payé à la caisse, puis entièrement retourné :
     * son avoir émis laisse au client un crédit du prix du produit.
     */
    private function retournerUnAchatEntier(Client $client, Produit $produit): void
    {
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->getKey(), 'quantite' => 1]]);
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ticket->lignes->sole()->getKey(), 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);
    }
}
