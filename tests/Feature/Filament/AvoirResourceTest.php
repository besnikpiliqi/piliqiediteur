<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\AvoirResource\Pages\CreateAvoir;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class AvoirResourceTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureDepuisLePanneau(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_laction_avoir_depuis_la_liste_des_factures_cree_et_emet_un_avoir_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $this->assertSame(7, $facture->lignes->first()->produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);

        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertNotNull($avoir->numero);
        $this->assertSame($facture->total_ttc, $avoir->total_ttc);
        $this->assertSame(10, $facture->lignes->first()->produit->fresh()->stock_quantite);
    }

    public function test_laction_avoir_nest_plus_visible_une_fois_toutes_les_lignes_integralement_creditees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);

        Livewire::test(ListFactures::class)
            ->assertTableActionHidden('avoir', $facture->fresh());
    }

    /**
     * Un avoir partiel (voir CreerAvoirAction) laisse un reliquat
     * créditable : l'action doit donc rester visible pour permettre un
     * second avoir sur la quantité restante.
     */
    public function test_laction_avoir_reste_visible_apres_un_avoir_partiel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $ligneFacture = $facture->lignes->firstOrFail();

        // Un remplissage complet du formulaire de l'action (plutôt qu'un
        // "data" partiel fusionné sur l'état par défaut du Repeater, voir
        // callTableAction) est nécessaire ici : ce dernier ajouterait un
        // second item à côté de l'item pré-rempli par défaut au lieu de le
        // remplacer, et créditerait donc une quantité involontairement
        // cumulée.
        Livewire::test(ListFactures::class)
            ->mountTableAction('avoir', $facture)
            ->fillForm([
                'lignes' => [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1]],
                'motif' => 'Retour partiel',
            ], 'mountedTableActionForm')
            ->callMountedTableAction();

        Livewire::test(ListFactures::class)
            ->assertTableActionVisible('avoir', $facture->fresh());

        $this->assertSame(2, $ligneFacture->fresh()->quantiteCreditableRestante());
        $this->assertSame(8, $facture->lignes->first()->produit->fresh()->stock_quantite);
    }

    /**
     * CreerAvoirAction refuse de créditer une ligne d'une facture encore en
     * brouillon (voir son docblock) : avant la correction, cette
     * LogicException remontait telle quelle jusqu'à une page d'erreur brute
     * au lieu d'une notification -- inacceptable pour un magasin en
     * production (voir CreateAvoir::handleRecordCreation()).
     */
    public function test_creer_un_avoir_sur_une_ligne_dune_facture_brouillon_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $ligneFacture = $facture->lignes->firstOrFail();

        // lignesCreditablesPourClient() exclut déjà les brouillons du champ
        // par défaut : cette ligne ne pourrait normalement jamais être
        // sélectionnée depuis l'UI -- on simule ici une soumission avec un
        // identifiant resté en mémoire sur une page restée ouverte.
        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['ligne_facture_id' => $ligneFacture->id, 'facture_numero' => $facture->numero, 'description' => $ligneFacture->description, 'quantite' => 1],
                ],
                'motif' => 'Tentative sur brouillon',
            ])
            ->call('create')
            ->assertNotified("Impossible de créditer une ligne d'une facture au statut [brouillon].");

        $this->assertSame(0, Avoir::query()->count());
    }

    public function test_telecharger_le_pdf_dun_avoir_emis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour client']);
        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();

        $telechargement = Livewire::test(ListAvoirs::class)
            ->callTableAction('telecharger_pdf', $avoir)
            ->assertFileDownloaded("avoir-{$avoir->numero}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
    }

    /**
     * Création et émission ne font qu'un (voir EnregistrerAvoirAction) :
     * un refus pendant l'émission, simulé ici après la création de
     * l'avoir, ne laisse aucun brouillon d'avoir, et le magasin voit
     * pourquoi.
     */
    public function test_un_refus_a_l_emission_depuis_le_formulaire_ne_laisse_aucun_brouillon_d_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $ligneFacture = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        Event::listen(AvoirEmis::class, fn () => throw new LogicException('Émission impossible.'));

        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1]],
                'motif' => 'Retour client',
            ])
            ->call('create')
            ->assertNotified('Émission impossible.');

        $this->assertSame(0, Avoir::query()->count());
    }

    public function test_un_refus_a_l_emission_depuis_la_liste_des_factures_ne_laisse_aucun_brouillon_d_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $facture = $this->emettreFactureDepuisLePanneau(Client::factory()->create());
        Event::listen(AvoirEmis::class, fn () => throw new LogicException('Émission impossible.'));

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture, data: ['motif' => 'Retour client'])
            ->assertNotified('Émission impossible.');

        $this->assertSame(0, Avoir::query()->count());
        $this->assertSame(7, $facture->lignes->firstOrFail()->produit->fresh()->stock_quantite);
    }

    /**
     * Exigence explicite : un avoir doit pouvoir regrouper des lignes de
     * plusieurs factures DIFFÉRENTES du même client, pas seulement d'une
     * seule facture -- voir le docblock de CreerAvoirAction.
     */
    public function test_creer_un_avoir_couvrant_deux_factures_differentes_du_meme_client(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client); // 180 TTC (3 x 50 HT, 20%)
        $factureB = $this->emettreFactureDepuisLePanneau($client); // 180 TTC

        $ligneA = $factureA->lignes->firstOrFail();
        $ligneB = $factureB->lignes->firstOrFail();

        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['ligne_facture_id' => $ligneA->id, 'quantite' => 1],
                    ['ligne_facture_id' => $ligneB->id, 'quantite' => 2],
                ],
                'motif' => 'Retour de deux achats différents',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $avoir = Avoir::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(StatutAvoir::Emise, $avoir->statut);
        $this->assertSame(2, $avoir->lignes()->count());
        $this->assertSame('150.00', $avoir->total_ht); // 1x50 + 2x50
        $this->assertCount(2, $avoir->facturesConcernees());
    }

    /**
     * Archivé, un client n'est plus proposé pour une vente, mais ce qu'il a
     * acheté se retourne toujours, sur deux de ses factures à la fois (voir
     * Client::scopeQuiPeuventEtreCredites()).
     */
    public function test_un_client_archive_recoit_un_avoir_couvrant_deux_de_ses_factures(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Société Archivée']);
        $ligneA = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        $ligneB = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        $client->delete();

        Livewire::test(CreateAvoir::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => ($champ->getOptions()[$client->id] ?? null) === 'Société Archivée')
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['ligne_facture_id' => $ligneA->id, 'quantite' => 1],
                    ['ligne_facture_id' => $ligneB->id, 'quantite' => 2],
                ],
                'motif' => 'Retour de deux achats',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $avoir = Avoir::query()->sole();
        $this->assertSame([$client->id, StatutAvoir::Emise], [$avoir->client_id, $avoir->statut]);
        $this->assertSame(['150.00', '30.00', '180.00'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertCount(2, $avoir->facturesConcernees());
    }

    /**
     * Un client archivé dont tout a déjà été crédité n'est ni proposé ni
     * accepté, même par une requête forgée.
     */
    public function test_un_client_archive_sans_rien_a_crediter_n_est_ni_propose_ni_accepte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour complet']);
        $client->delete();

        Livewire::test(CreateAvoir::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => ! array_key_exists($client->id, $champ->getOptions()))
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1]],
                'motif' => 'Second retour',
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(1, Avoir::query()->count());
    }

    /**
     * Défense en profondeur : même si l'UI ne propose normalement que les
     * lignes du client sélectionné (voir AvoirResource::lignesCreditablesPourClient()),
     * l'action elle-même doit aussi refuser toute incohérence -- voir
     * AvoirActionsTest pour le test équivalent au niveau de l'action.
     */
    public function test_le_formulaire_ne_propose_que_les_lignes_du_client_selectionne(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $factureDeA = $this->emettreFactureDepuisLePanneau($clientA);
        $this->emettreFactureDepuisLePanneau($clientB);

        $livewire = Livewire::test(CreateAvoir::class)
            ->fillForm(['client_id' => $clientA->id]);

        $lignes = $livewire->instance()->form->getRawState()['lignes'];
        $this->assertCount(1, $lignes, 'Seule la ligne du client sélectionné doit être proposée.');
        $this->assertSame($factureDeA->lignes->firstOrFail()->id, $lignes[0]['ligne_facture_id']);
    }

    /**
     * Un client passé professionnel après un ticket a des lignes
     * créditables dans les deux séries : le formulaire fait choisir la
     * série avant de proposer des lignes, et ne préremplit donc jamais le
     * mélange que CreerAvoirAction refuse.
     */
    public function test_le_formulaire_fait_choisir_tickets_ou_factures_avant_de_proposer_les_lignes(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire));
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $ticket = $this->emettreFactureDepuisLePanneau($client);
        $client->update(['type_client' => TypeClient::Professionnel, 'siret' => '10000000900025']);
        $this->emettreFactureDepuisLePanneau($client);

        $formulaire = Livewire::test(CreateAvoir::class)
            ->fillForm(['client_id' => $client->id, 'motif' => 'Retour du ticket']);

        $this->assertSame([], $formulaire->instance()->form->getRawState()['lignes']);
        $formulaire->call('create')->assertHasFormErrors(['serie' => 'required']);

        $formulaire->fillForm(['serie' => TypeDocument::TicketCaisse->value]);

        $lignesProposees = array_values($formulaire->instance()->form->getRawState()['lignes']);
        $this->assertSame([$ticket->lignes->firstOrFail()->id], array_column($lignesProposees, 'ligne_facture_id'));
        $formulaire->call('create')->assertHasNoFormErrors();
        $this->assertSame([$ticket->id], Avoir::query()->firstOrFail()->facturesConcernees()->pluck('id')->all());
    }

    public function test_le_client_d_un_autre_magasin_est_refuse(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $clientDUnAutreMagasin = Client::factory()->create();
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateAvoir::class)
            ->fillForm(['client_id' => $clientDUnAutreMagasin->id, 'motif' => 'Retour'])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Avoir::query()->count());
    }

    /**
     * Même confidentialité que les factures : sans facture.view_toutes, la
     * liste ne montre que les avoirs que l'on a soi-même créés.
     */
    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_avoirs(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeurA = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $vendeurB = $this->vendeurQuiVoitLesAvoirs($magasin, 'jean@example.test');
        $avoirDeA = $this->emettreUnAvoirEnTantQue($vendeurA);
        $avoirDeB = $this->emettreUnAvoirEnTantQue($vendeurB);
        Filament::setTenant($magasin);

        $liste = Livewire::test(ListAvoirs::class);

        $liste->assertCanSeeTableRecords([$avoirDeB])
            ->assertCanNotSeeTableRecords([$avoirDeA]);
    }

    /**
     * B16 : un vendeur ne crédite que ses ventes. Le client a acheté au
     * vendeur A et au vendeur B : le formulaire de B ne lui propose que la
     * ligne de son propre ticket.
     */
    public function test_le_formulaire_d_un_vendeur_ne_propose_que_les_lignes_de_ses_ventes(): void
    {
        ['vendeurB' => $vendeurB, 'client' => $client, 'venteDeB' => $venteDeB] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::test(CreateAvoir::class)->fillForm(['client_id' => $client->id]);

        $this->assertSame([$venteDeB->lignes->sole()->id], array_column(array_values($formulaire->instance()->form->getRawState()['lignes']), 'ligne_facture_id'));
    }

    /**
     * La ligne du vendeur A, forgée dans le formulaire du vendeur B, est
     * refusée (403) : aucun avoir, et la ligne reste entièrement
     * créditable.
     */
    public function test_la_ligne_d_un_collegue_forgee_dans_le_formulaire_est_refusee(): void
    {
        ['vendeurB' => $vendeurB, 'client' => $client, 'venteDeA' => $venteDeA] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::test(CreateAvoir::class)
            ->fillForm(['client_id' => $client->id, 'motif' => 'Retour'])
            ->fillForm(['lignes' => [['ligne_facture_id' => $venteDeA->lignes->sole()->id, 'quantite' => 1]]])
            ->call('create');

        $formulaire->assertForbidden();
        $this->assertSame(0, Avoir::query()->count());
        $this->assertSame(1, $venteDeA->lignes->sole()->quantiteCreditableRestante());
    }

    /**
     * Même refus depuis l'action « Avoir » de sa propre facture, où la
     * ligne du collègue est forgée parmi les lignes à créditer.
     */
    public function test_la_ligne_d_un_collegue_forgee_dans_l_action_avoir_est_refusee(): void
    {
        ['vendeurB' => $vendeurB, 'venteDeA' => $venteDeA, 'venteDeB' => $venteDeB] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $liste = Livewire::test(ListFactures::class)
            ->mountTableAction('avoir', $venteDeB)
            ->setTableActionData([
                'lignes' => [['ligne_facture_id' => $venteDeA->lignes->sole()->id, 'quantite' => 1]],
                'motif' => 'Retour',
            ])
            ->call('callMountedTableAction');

        $liste->assertForbidden();
        $this->assertSame(0, Avoir::query()->count());
    }

    /**
     * Un client qui a acheté un article au vendeur A et un au vendeur B,
     * deux vendeurs autorisés à voir et créer les avoirs.
     *
     * @return array{vendeurB: User, client: Client, venteDeA: Facture, venteDeB: Facture}
     */
    private function clientDesDeuxVendeurs(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autorisations = [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_VIEW, PermissionRegistry::AVOIR_CREER];
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $client = Client::factory()->create();
        $venteDeA = $this->emettreUnTicketEnTantQue($vendeurA, $client);
        $venteDeB = $this->emettreUnTicketEnTantQue($vendeurB, $client);
        Filament::setTenant($magasin);

        return ['vendeurB' => $vendeurB, 'client' => $client, 'venteDeA' => $venteDeA, 'venteDeB' => $venteDeB];
    }

    private function emettreUnTicketEnTantQue(User $vendeur, Client $client): Facture
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function emettreUnAvoirEnTantQue(User $auteur): Avoir
    {
        $this->actingAs($auteur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(EnregistrerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]);
    }

    private function vendeurQuiVoitLesAvoirs(Magasin $magasin, string $email): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_VIEW], ['name' => $email, 'email' => $email]);
    }

    /**
     * B6 et K6 : avant son émission, le magasin lit ce que deviendra le
     * crédit de l'avoir, selon son client.
     */
    public function test_le_formulaire_annonce_ce_que_devient_le_credit_de_l_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $this->emettreFactureDepuisLePanneau($client);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $this->emettreFactureDepuisLePanneau($comptoir);

        $formulaire = Livewire::test(CreateAvoir::class);

        $formulaire
            ->fillForm(['client_id' => $client->id])
            ->assertSee('L\'avoir réduit d\'abord ce qui reste dû des documents qu\'il corrige. Le crédit restant est imputé sur les factures impayées du client, de la plus ancienne à la plus récente.')
            ->fillForm(['client_id' => $comptoir->id])
            ->assertSee('L\'avoir réduit d\'abord ce qui reste dû des tickets qu\'il corrige ; son crédit restant vaut bon d\'avoir : son numéro le fait utiliser lors d\'un prochain achat au comptoir.');
    }
}
