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
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\AvoirResource\Pages\CreateAvoir;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class AvoirResourceTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureDepuisLePanneau(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10]);

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $ligneFacture = $facture->lignes->firstOrFail();

        // La recherche par numéro ne trouve jamais un brouillon, qui n'a pas
        // de numéro : cette ligne ne pourrait normalement jamais être
        // proposée depuis l'UI -- on simule ici une soumission avec un
        // identifiant resté en mémoire sur une page restée ouverte.
        Livewire::test(CreateAvoir::class)
            ->fillForm([
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $ligneFacture = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        Event::listen(AvoirEmis::class, fn () => throw new LogicException('Émission impossible.'));

        Livewire::test(CreateAvoir::class)
            ->fillForm([
                'lignes' => [['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1]],
                'motif' => 'Retour client',
            ])
            ->call('create')
            ->assertNotified('Émission impossible.');

        $this->assertSame(0, Avoir::query()->count());
    }

    public function test_un_refus_a_l_emission_depuis_la_liste_des_factures_ne_laisse_aucun_brouillon_d_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client); // 180 TTC (3 x 50 HT, 20%)
        $factureB = $this->emettreFactureDepuisLePanneau($client); // 180 TTC

        $ligneA = $factureA->lignes->firstOrFail();
        $ligneB = $factureB->lignes->firstOrFail();
        $formulaire = $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), $factureA->numero), $factureB->numero);
        $this->assertSame([[$ligneA->id, 3], [$ligneB->id, 3]], $this->lignesProposees($formulaire));

        $formulaire
            ->set('data.lignes', [
                ['ligne_facture_id' => $ligneA->id, 'quantite' => 1],
                ['ligne_facture_id' => $ligneB->id, 'quantite' => 2],
            ])
            ->fillForm(['motif' => 'Retour de deux achats différents'])
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
     * acheté se retourne toujours, sur deux de ses factures à la fois :
     * leurs numéros les retrouvent, avec le nom qu'elles ont figé.
     */
    public function test_un_client_archive_recoit_un_avoir_couvrant_deux_de_ses_factures(): void
    {
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Société Archivée']);
        $ligneA = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        $ligneB = $this->emettreFactureDepuisLePanneau($client)->lignes->firstOrFail();
        $client->delete();

        $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), 'TK-MABOUT-2026-000001'), 'TK-MABOUT-2026-000002')
            ->assertSee('Société Archivée — ticket TK-MABOUT-2026-000001 du 17/10/2026 à 10 h 32, ticket TK-MABOUT-2026-000002 du 17/10/2026 à 10 h 32')
            ->set('data.lignes', [
                ['ligne_facture_id' => $ligneA->id, 'quantite' => 1],
                ['ligne_facture_id' => $ligneB->id, 'quantite' => 2],
            ])
            ->fillForm(['motif' => 'Retour de deux achats'])
            ->call('create')
            ->assertHasNoFormErrors();

        $avoir = Avoir::query()->sole();
        $this->assertSame([$client->id, StatutAvoir::Emise], [$avoir->client_id, $avoir->statut]);
        $this->assertSame(['150.00', '30.00', '180.00'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertCount(2, $avoir->facturesConcernees());
    }

    /**
     * Le document d'un client archivé dont tout a déjà été crédité n'est
     * ni proposé ni accepté, même par une requête forgée.
     */
    public function test_un_client_archive_sans_rien_a_crediter_n_est_ni_propose_ni_accepte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);
        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour complet']);
        $client->delete();
        $ligne = $facture->lignes->firstOrFail();

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $facture->numero);
        $this->assertSame(["Le ticket {$facture->numero} est déjà entièrement crédité : il n'a plus rien à rendre."], $this->refusSousLeChamp($formulaire));
        $formulaire
            ->assertSet('data.lignes', [])
            ->fillForm(['lignes' => [['ligne_facture_id' => $ligne->id, 'quantite' => 1]], 'motif' => 'Second retour'])
            ->call('create')
            ->assertHasFormErrors(['lignes.0.quantite' => 'max']);

        $this->assertSame(1, Avoir::query()->count());
    }

    /**
     * Seules les lignes du document cherché sont proposées, pas celles des
     * autres documents de son client ni d'un autre client ; l'action
     * refuse en plus toute incohérence -- voir AvoirActionsTest pour le
     * test équivalent au niveau de l'action.
     */
    public function test_le_formulaire_ne_propose_que_les_lignes_du_document_cherche(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $factureDeA = $this->emettreFactureDepuisLePanneau($clientA);
        $this->emettreFactureDepuisLePanneau($clientA);
        $this->emettreFactureDepuisLePanneau($clientB);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $factureDeA->numero);

        $this->assertSame([[$factureDeA->lignes->firstOrFail()->id, 3]], $this->lignesProposees($formulaire), 'Seule la ligne du document cherché doit être proposée.');
    }

    /**
     * Un client passé professionnel après un ticket a des lignes
     * créditables dans les deux séries : la facture cherchée après son
     * ticket est refusée sous le champ, avec le message de
     * CreerAvoirAction, et le formulaire ne propose donc jamais le mélange
     * que l'action refuse.
     */
    public function test_ajouter_une_facture_a_un_avoir_de_tickets_est_refuse(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $ticket = $this->emettreFactureDepuisLePanneau($client);
        $client->update(['type_client' => TypeClient::Professionnel, 'siret' => '10000000900025']);
        $facture = $this->emettreFactureDepuisLePanneau($client);

        $formulaire = $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), $ticket->numero), $facture->numero);

        $this->assertSame(['Un avoir ne mélange jamais des tickets de caisse et des factures : faites un avoir pour les tickets, et un autre pour les factures.'], $this->refusSousLeChamp($formulaire));
        $formulaire->assertSet('data.recherche', $facture->numero);
        $this->assertSame([[$ticket->lignes->firstOrFail()->id, 3]], $this->lignesProposees($formulaire));
        $formulaire->fillForm(['motif' => 'Retour du ticket'])->call('create')->assertHasNoFormErrors();
        $this->assertSame([$ticket->id], Avoir::query()->firstOrFail()->facturesConcernees()->pluck('id')->all());
    }

    /**
     * Sans champ client, un autre magasin ne s'atteint plus que par une
     * ligne forgée : introuvable dans le magasin actif, elle est refusée
     * avant toute écriture.
     */
    public function test_la_ligne_d_un_autre_magasin_forgee_dans_le_formulaire_est_refusee(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $ligneDUnAutreMagasin = $this->emettreUnTicketDansLeMagasin($autreMagasin)->lignes->sole();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        Livewire::test(CreateAvoir::class)
            ->fillForm(['lignes' => [['ligne_facture_id' => $ligneDUnAutreMagasin->id, 'quantite' => 1]], 'motif' => 'Retour'])
            ->call('create')
            ->assertNotified('Cette ligne de facture est introuvable.');

        $this->assertSame(0, Avoir::withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * Même confidentialité que les factures : sans facture.view_toutes, la
     * liste ne montre que les avoirs que l'on a soi-même créés.
     */
    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_avoirs(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeurA = $this->vendeurQuiFaitDesAvoirs($magasin, 'paul@example.test');
        $vendeurB = $this->vendeurQuiFaitDesAvoirs($magasin, 'jean@example.test');
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
        ['vendeurB' => $vendeurB, 'venteDeB' => $venteDeB] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $venteDeB->numero);

        $this->assertSame([[$venteDeB->lignes->sole()->id, 1]], $this->lignesProposees($formulaire));
    }

    /**
     * La ligne du vendeur A, forgée dans le formulaire du vendeur B, est
     * refusée (403) : aucun avoir, et la ligne reste entièrement
     * créditable.
     */
    public function test_la_ligne_d_un_collegue_forgee_dans_le_formulaire_est_refusee(): void
    {
        ['vendeurB' => $vendeurB, 'venteDeA' => $venteDeA] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::test(CreateAvoir::class)
            ->fillForm(['lignes' => [['ligne_facture_id' => $venteDeA->lignes->sole()->id, 'quantite' => 1]], 'motif' => 'Retour'])
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

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function emettreUnAvoirEnTantQue(User $auteur): Avoir
    {
        $this->actingAs($auteur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]);
    }

    /**
     * Un vendeur qui voit les avoirs et en fait, sans voir les ventes de
     * toute l'équipe.
     */
    private function vendeurQuiFaitDesAvoirs(Magasin $magasin, string $email): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_VIEW, PermissionRegistry::AVOIR_CREER], ['name' => $email, 'email' => $email]);
    }

    /**
     * B6 et K6 : avant son émission, le magasin lit ce que deviendra le
     * crédit de l'avoir, selon son client.
     */
    public function test_le_formulaire_annonce_ce_que_devient_le_credit_de_l_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $venteAuClient = $this->emettreFactureDepuisLePanneau(Client::factory()->create());
        $venteAuComptoir = $this->emettreFactureDepuisLePanneau(Client::query()->where('est_comptoir', true)->sole());

        $avoirAuClient = $this->chercher(Livewire::test(CreateAvoir::class), $venteAuClient->numero);
        $avoirAuComptoir = $this->chercher(Livewire::test(CreateAvoir::class), $venteAuComptoir->numero);

        $avoirAuClient->assertSee('L\'avoir réduit d\'abord ce qui reste dû des documents qu\'il corrige. Le crédit restant est imputé sur les factures impayées du client, de la plus ancienne à la plus récente.');
        $avoirAuComptoir->assertSee('L\'avoir réduit d\'abord ce qui reste dû des tickets qu\'il corrige ; son crédit restant vaut bon d\'avoir : son numéro le fait utiliser lors d\'une prochaine vente à la caisse, quel qu\'en soit le client, ou, avec au moins une part payée, sur un ticket du client comptoir resté dû.');
    }

    /**
     * Point 2 : le retour part du numéro du document, saisi ici en
     * minuscules avec des espaces autour ; le client s'affiche tel que le
     * ticket l'a figé à sa vente, même renommé depuis, et l'avoir lui est
     * fait.
     */
    public function test_le_retour_se_cherche_par_le_numero_du_document_et_affiche_son_client(): void
    {
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $magasin = $this->ouvrirLeMagasinDuProprietaire();
        $client = Client::factory()->create(['nom' => 'Mme Durand']);
        $ticket = $this->emettreFactureDepuisLePanneau($client);
        $client->update(['nom' => 'Mme Durand-Martin']);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), '  tk-mabout-2026-000001 ');

        $formulaire
            ->assertHasNoFormErrors()
            ->assertSee('Mme Durand — ticket TK-MABOUT-2026-000001 du 17/10/2026 à 10 h 32')
            ->assertDontSee('Mme Durand-Martin')
            ->assertSet('data.recherche', null)
            ->assertFormFieldExists('recherche', fn (TextInput $champ): bool => $champ->getLabel() === 'Ajouter un autre ticket ou une autre facture');
        $this->assertSame([[$ticket->lignes->sole()->id, 3]], $this->lignesProposees($formulaire));

        $formulaire->fillForm(['motif' => 'Retour'])->call('create')->assertHasNoFormErrors();

        $avoir = Avoir::query()->sole();
        $this->assertSame([$magasin->id, $client->id, 'Mme Durand', '180.00'], [$avoir->magasin_id, $avoir->client_id, $avoir->client_nom, $avoir->total_ttc]);
    }

    /**
     * Le client comptoir a acheté deux fois : seul le ticket cherché est
     * proposé, jamais toutes les lignes de tous ses tickets.
     */
    public function test_avec_le_client_comptoir_seul_le_ticket_cherche_est_propose(): void
    {
        $this->travelTo(self::aParis('2026-10-17 09:15:00'));
        $magasin = $this->ouvrirLeMagasinDuProprietaire();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $this->emettreUnTicketDansLeMagasin($magasin, $comptoir);
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $ticketCherche = $this->emettreUnTicketDansLeMagasin($magasin, $comptoir);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $ticketCherche->numero);

        $formulaire->assertSee('Client comptoir — ticket TK-MABOUT-2026-000002 du 17/10/2026 à 10 h 32');
        $this->assertSame([[$ticketCherche->lignes->sole()->id, 1]], $this->lignesProposees($formulaire));
    }

    public function test_un_client_sans_achat_n_est_jamais_propose(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        Client::factory()->create(['nom' => 'Jeanne Sansachat']);
        $this->emettreFactureDepuisLePanneau(Client::factory()->create(['nom' => 'Marc Acheteur']));

        $formulaire = Livewire::test(CreateAvoir::class);

        $formulaire->assertDontSee('Jeanne Sansachat')->assertDontSee('Marc Acheteur');
        $this->chercher($formulaire, 'Jeanne Sansachat')->assertSet('data.lignes', []);
        $this->assertSame([AvoirResource::DOCUMENT_INTROUVABLE], $this->refusSousLeChamp($formulaire));
    }

    public function test_l_ecran_n_a_plus_de_champ_client(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();

        $formulaire = Livewire::test(CreateAvoir::class);

        $formulaire
            ->assertFormFieldDoesNotExist('client_id')
            ->assertFormFieldDoesNotExist('serie')
            ->assertFormFieldExists('recherche', fn (TextInput $champ): bool => $champ->getLabel() === 'N° du ticket ou de la facture');
    }

    public function test_ajouter_un_document_d_un_autre_client_est_refuse(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $ticketDeA = $this->emettreFactureDepuisLePanneau(Client::factory()->create());
        $ticketDeB = $this->emettreFactureDepuisLePanneau(Client::factory()->create());

        $formulaire = $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), $ticketDeA->numero), $ticketDeB->numero);

        $this->assertSame(["Le ticket {$ticketDeB->numero} est celui d'un autre client : un avoir ne corrige que les documents d'un même client."], $this->refusSousLeChamp($formulaire));
        $this->assertSame([[$ticketDeA->lignes->sole()->id, 3]], $this->lignesProposees($formulaire));
    }

    public function test_un_document_deja_dans_l_avoir_n_y_entre_pas_deux_fois(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreFactureDepuisLePanneau(Client::factory()->create());

        $formulaire = $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), $ticket->numero), $ticket->numero);

        $this->assertSame(["Le ticket {$ticket->numero} est déjà dans l'avoir, avec toutes ses lignes à rendre."], $this->refusSousLeChamp($formulaire));
        $this->assertSame([[$ticket->lignes->sole()->id, 3]], $this->lignesProposees($formulaire));
    }

    /**
     * Une ligne retirée par erreur revient en cherchant de nouveau son
     * document, à la quantité qui en reste, sans doubler la ligne restée
     * dans l'avoir ni changer la quantité qui y a été baissée.
     */
    public function test_une_ligne_retiree_revient_en_cherchant_de_nouveau_son_document(): void
    {
        $magasin = $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreUnTicketDeDeuxLignesDansLeMagasin($magasin);
        [$premiere, $seconde] = $ticket->lignes->all();
        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $ticket->numero);
        $lignes = $formulaire->get('data.lignes');
        $cleDeLaPremiere = collect($lignes)->search(fn (array $ligne): bool => $ligne['ligne_facture_id'] === $premiere->id);
        $formulaire->set('data.lignes', [$cleDeLaPremiere => [...$lignes[$cleDeLaPremiere], 'quantite' => 1]]);

        $this->chercher($formulaire, $ticket->numero);

        $formulaire->assertHasNoFormErrors();
        $this->assertSame([[$premiere->id, 1], [$seconde->id, 1]], $this->lignesProposees($formulaire));
    }

    /**
     * Le rang d'un numéro se saisit sans ses zéros de tête : « tk-mabout-2026-1 »
     * retrouve TK-MABOUT-2026-000001.
     */
    public function test_un_numero_saisi_sans_les_zeros_de_son_rang_est_retrouve(): void
    {
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreFactureDepuisLePanneau(Client::factory()->create());

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), 'tk-mabout-2026-1');

        $formulaire->assertHasNoFormErrors();
        $this->assertSame('TK-MABOUT-2026-000001', $ticket->numero);
        $this->assertSame([[$ticket->lignes->sole()->id, 3]], $this->lignesProposees($formulaire));
    }

    /**
     * Un client renommé entre deux achats : l'avoir qui corrige les deux
     * tickets fige le nom du plus récent, et c'est ce nom que l'écran
     * annonce avant l'émission, quel que soit le ticket cherché d'abord.
     */
    public function test_le_client_affiche_est_celui_que_l_avoir_fige_quand_il_corrige_plusieurs_documents(): void
    {
        $this->travelTo(self::aParis('2026-10-10 09:00:00'));
        $this->ouvrirLeMagasinDuProprietaire();
        $client = Client::factory()->create(['nom' => 'Mme Durand']);
        $ancien = $this->emettreFactureDepuisLePanneau($client);
        $client->update(['nom' => 'Mme Martin']);
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $recent = $this->emettreFactureDepuisLePanneau($client);

        $formulaire = $this->chercher($this->chercher(Livewire::test(CreateAvoir::class), $ancien->numero), $recent->numero);

        $formulaire
            ->assertHasNoFormErrors()
            ->assertSee('Mme Martin — ticket TK-MABOUT-2026-000001 du 10/10/2026 à 09 h 00, ticket TK-MABOUT-2026-000002 du 17/10/2026 à 10 h 32')
            ->assertDontSee('Mme Durand');
        $formulaire->fillForm(['motif' => 'Retour'])->call('create')->assertHasNoFormErrors();
        $this->assertSame('Mme Martin', Avoir::query()->sole()->client_nom);
    }

    /**
     * B16 : le numéro de la vente d'un collègue est introuvable pour un
     * vendeur qui ne voit que ses ventes, comme un numéro inconnu ; le sien
     * se retrouve, et le refus précédent disparaît.
     */
    public function test_un_vendeur_ne_retrouve_que_ses_documents(): void
    {
        ['vendeurB' => $vendeurB, 'venteDeA' => $venteDeA, 'venteDeB' => $venteDeB] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $venteDeA->numero);

        $this->assertSame([AvoirResource::DOCUMENT_INTROUVABLE], $this->refusSousLeChamp($formulaire));
        $formulaire->assertSet('data.lignes', []);
        $this->chercher($formulaire, $venteDeB->numero)->assertHasNoFormErrors();
        $this->assertSame([[$venteDeB->lignes->sole()->id, 1]], $this->lignesProposees($formulaire));
    }

    /**
     * Le propriétaire a aussi accès à l'autre magasin de sa société : le
     * numéro d'un ticket de celui-ci reste introuvable dans le magasin
     * ouvert, comme celui d'une autre société, même identique au numéro
     * que porterait le premier ticket du magasin ouvert.
     */
    public function test_un_numero_d_un_autre_magasin_est_introuvable(): void
    {
        $this->travelTo(self::aParis('2026-10-17 10:32:00'));
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON1']);
        $ticketDeVilleurbanne = $this->emettreUnTicketDansLeMagasin($this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'code' => 'VILL1']));
        $ticketDUneAutreSociete = $this->emettreUnTicketDansLeMagasin($this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON1']));
        $this->dansLeMagasin($lyon, $this->proprietaireDe($lyon));
        $formulaire = Livewire::test(CreateAvoir::class);

        $refusPourVilleurbanne = $this->refusSousLeChamp($this->chercher($formulaire, $ticketDeVilleurbanne->numero));
        $refusPourLAutreSociete = $this->refusSousLeChamp($this->chercher($formulaire, $ticketDUneAutreSociete->numero));

        $this->assertSame(['TK-VILL1-2026-000001', 'TK-LYON1-2026-000001'], [$ticketDeVilleurbanne->numero, $ticketDUneAutreSociete->numero]);
        $this->assertSame([[AvoirResource::DOCUMENT_INTROUVABLE], [AvoirResource::DOCUMENT_INTROUVABLE]], [$refusPourVilleurbanne, $refusPourLAutreSociete]);
        $formulaire->assertSet('data.lignes', []);
    }

    /**
     * La facture d'un professionnel entièrement reprise par un avoir n'a
     * plus rien à rendre : elle n'est plus proposée, et le champ le dit.
     */
    public function test_un_document_entierement_credite_n_est_plus_propose(): void
    {
        $magasin = $this->ouvrirLeMagasinDuProprietaire();
        $this->completerIdentiteLegale($magasin);
        $facture = $this->emettreFactureDepuisLePanneau(Client::factory()->professionnel()->create());
        Livewire::test(ListFactures::class)->callTableAction('avoir', $facture, data: ['motif' => 'Retour complet']);

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $facture->numero);

        $this->assertSame(["La facture {$facture->numero} est déjà entièrement créditée : elle n'a plus rien à rendre."], $this->refusSousLeChamp($formulaire));
        $formulaire->assertSet('data.lignes', []);
    }

    /**
     * Le client de l'avoir n'est jamais lu dans la requête : un client
     * glissé dans les données est ignoré, et une ligne d'un autre client
     * ajoutée aux lignes du document cherché fait refuser l'avoir. Seul
     * l'avoir des lignes du premier document s'émet, à son client.
     */
    public function test_un_client_forge_dans_la_requete_est_refuse(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $ticketDeA = $this->emettreFactureDepuisLePanneau($clientA);
        $ticketDeB = $this->emettreFactureDepuisLePanneau($clientB);
        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $ticketDeA->numero)->fillForm(['motif' => 'Retour']);

        $formulaire
            ->set('data.client_id', $clientB->id)
            ->set('data.lignes', [
                ['ligne_facture_id' => $ticketDeA->lignes->sole()->id, 'quantite' => 1],
                ['ligne_facture_id' => $ticketDeB->lignes->sole()->id, 'quantite' => 1],
            ])
            ->call('create')
            ->assertNotified("Cette ligne appartient à une facture d'un autre client.");
        $this->assertSame(0, Avoir::query()->count());

        $formulaire
            ->set('data.lignes', [['ligne_facture_id' => $ticketDeA->lignes->sole()->id, 'quantite' => 1]])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame($clientA->id, Avoir::query()->sole()->client_id);
    }

    /**
     * Le nom figé du client, rendu tel quel dans la page, n'y injecte
     * aucune balise.
     */
    public function test_le_nom_du_client_s_affiche_echappe(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreFactureDepuisLePanneau(Client::factory()->create(['nom' => "<script>alert('x')</script> Dupont"]));

        $formulaire = $this->chercher(Livewire::test(CreateAvoir::class), $ticket->numero);

        $formulaire
            ->assertSeeHtml('&lt;script&gt;')
            ->assertDontSeeHtml("<script>alert('x')</script>");
    }

    /**
     * « Avoir » sur la fiche d'un ticket ouvre « Nouvel avoir » avec son
     * numéro, que la page cherche aussitôt : ses lignes sont proposées.
     */
    public function test_l_action_avoir_de_la_fiche_ouvre_la_recherche_sur_son_document(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreFactureDepuisLePanneau(Client::factory()->create());
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertActionHasUrl('avoir', AvoirResource::getUrl('create', ['document' => $ticket->numero]));

        $formulaire = Livewire::withQueryParams(['document' => $ticket->numero])->test(CreateAvoir::class);

        $formulaire->assertHasNoFormErrors();
        $this->assertSame([[$ticket->lignes->sole()->id, 3]], $this->lignesProposees($formulaire));
    }

    /**
     * « Avoir » sur la fiche ouvre « Nouvel avoir », qu'un vendeur qui crée
     * des avoirs sans pouvoir les voir n'ouvre pas (erreur 403) : il n'a
     * pas le bouton sur la fiche de son propre ticket ; celui qui les voit
     * l'a.
     */
    public function test_un_vendeur_qui_cree_des_avoirs_sans_pouvoir_les_voir_n_a_pas_le_bouton_avoir_de_la_fiche(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $sansVoirLesAvoirs = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_CREER]);
        $avecLesAvoirs = $this->vendeurQuiFaitDesAvoirs($magasin, 'vendeur@example.com');
        $ticketSansVoirLesAvoirs = $this->emettreUnTicketEnTantQue($sansVoirLesAvoirs, Client::factory()->create());
        $ticketAvecLesAvoirs = $this->emettreUnTicketEnTantQue($avecLesAvoirs, Client::factory()->create());

        $this->dansLeMagasin($magasin, $sansVoirLesAvoirs);
        Livewire::test(ViewFacture::class, ['record' => $ticketSansVoirLesAvoirs->getRouteKey()])->assertActionHidden('avoir');
        $this->get(AvoirResource::getUrl('create', ['document' => $ticketSansVoirLesAvoirs->numero]))->assertForbidden();
        $this->dansLeMagasin($magasin, $avecLesAvoirs);
        Livewire::test(ViewFacture::class, ['record' => $ticketAvecLesAvoirs->getRouteKey()])->assertActionVisible('avoir');
    }

    public function test_l_action_avoir_de_la_fiche_disparait_quand_rien_ne_reste_a_crediter(): void
    {
        $this->ouvrirLeMagasinDuProprietaire();
        $ticket = $this->emettreFactureDepuisLePanneau(Client::factory()->create());
        Livewire::test(ListFactures::class)->callTableAction('avoir', $ticket, data: ['motif' => 'Retour complet']);

        $fiche = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()]);

        $fiche->assertActionHidden('avoir');
    }

    /**
     * Un numéro passé dans l'adresse de la page suit la même recherche :
     * la vente d'un collègue reste introuvable pour un vendeur qui ne voit
     * que les siennes, et aucune ligne n'est proposée.
     */
    public function test_un_numero_passe_dans_l_adresse_que_la_personne_ne_peut_pas_corriger_est_refuse_sous_le_champ(): void
    {
        ['vendeurB' => $vendeurB, 'venteDeA' => $venteDeA] = $this->clientDesDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::withQueryParams(['document' => $venteDeA->numero])->test(CreateAvoir::class);

        $this->assertSame([AvoirResource::DOCUMENT_INTROUVABLE], $this->refusSousLeChamp($formulaire));
        $formulaire
            ->assertSet('data.recherche', $venteDeA->numero)
            ->assertSet('data.lignes', []);
    }

    /**
     * Le propriétaire connecté dans le magasin de sa société, qui devient
     * le magasin du panneau.
     */
    private function ouvrirLeMagasinDuProprietaire(): Magasin
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * « Chercher » le numéro saisi dans « N° du ticket ou de la facture ».
     */
    private function chercher(Testable $formulaire, string $numero): Testable
    {
        return $formulaire
            ->fillForm(['recherche' => $numero])
            ->callFormComponentAction('recherche', 'chercher');
    }

    /**
     * Le refus affiché sous « N° du ticket ou de la facture », lu tel quel
     * (un message qui contient « : » ne se compare pas par
     * assertHasFormErrors(), qui le prendrait pour une règle).
     *
     * @return list<string>
     */
    private function refusSousLeChamp(Testable $formulaire): array
    {
        return $formulaire->errors()->get('data.recherche');
    }

    /**
     * Les lignes que propose le formulaire : chacune avec sa quantité.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function lignesProposees(Testable $formulaire): array
    {
        return array_map(fn (array $ligne): array => [$ligne['ligne_facture_id'], $ligne['quantite']], array_values($formulaire->get('data.lignes')));
    }

    /**
     * Un ticket émis dans ce magasin par son responsable de test à un
     * nouveau client particulier, de deux lignes : deux articles à
     * 12,00 € TTC, puis un article à 30,00 € TTC.
     */
    private function emettreUnTicketDeDeuxLignesDansLeMagasin(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin): Facture {
            $vendeur = $this->responsableDeTest($magasin);
            $article = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            $autreArticle = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [
                ['produit_id' => $article->id, 'quantite' => 2],
                ['produit_id' => $autreArticle->id, 'quantite' => 1],
            ]));
        });
    }

    /**
     * Un ticket d'un article à 12,00 € TTC émis dans ce magasin par son
     * responsable de test, au client donné ou à un nouveau client
     * particulier.
     */
    private function emettreUnTicketDansLeMagasin(Magasin $magasin, ?Client $client = null): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin, $client): Facture {
            $vendeur = $this->responsableDeTest($magasin);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client ?? Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        });
    }
}
