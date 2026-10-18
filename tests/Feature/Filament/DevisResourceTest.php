<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
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

    /**
     * Les conditions de paiement d'un devis s'impriment, comme celles d'une
     * facture, dans le pied de chaque page : 500 caractères au plus.
     */
    public function test_des_conditions_de_paiement_de_plus_de_500_caracteres_sont_refusees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'conditions_paiement' => str_repeat('a', 501),
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['conditions_paiement' => 'max']);

        $this->assertSame(0, Devis::query()->count());
    }

    /**
     * La nature d'un devis et sa livraison s'enregistrent avec lui, et ses
     * lignes se calculent sans TVA, au HT de leur prix catalogue : 3 x
     * 49,90 € TTC à 20 % font 149,70 ÷ 1,2 = 124,75 €. La facture qui en
     * sera tirée les reprend (voir CreerFactureDepuisDevisAction).
     */
    public function test_creer_un_devis_de_livraison_vers_un_dom_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '49.90', 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'nature_operation' => NatureOperation::LivraisonDom->value,
                'livraison_adresse' => '1 rue Victor Hugo',
                'livraison_code_postal' => '97200',
                'livraison_ville' => 'Fort-de-France',
                'livraison_pays' => 'FR',
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 3, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->sole();
        $this->assertSame([NatureOperation::LivraisonDom, '97200', TransportPar::Magasin], [$devis->nature_operation, $devis->livraison_code_postal, $devis->transport_par]);
        $this->assertSame(['124.75', '0.00', '124.75'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
    }

    public function test_creer_un_devis_avec_des_lignes_via_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
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

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $client->id])
            ->assertSee('Crédit disponible pour ce client : 60,00');

        $autreClient = Client::factory()->create();

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $autreClient->id])
            ->assertDontSee('Crédit disponible');
    }

    /**
     * La main-d'œuvre d'un devis s'ajoute comme à la caisse, par le bouton
     * « Ajouter un service ».
     */
    public function test_creer_un_devis_avec_une_ligne_de_service(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $pose = Service::factory()->create(['nom' => 'Pose']);

        $component = Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $client->id, 'lignes' => []])
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$pose->id]]);
        $cle = array_key_first($component->get('data.lignes'));
        // 54 € TTC à 20 % de TVA = 45 € HT, par 3.
        $component
            ->set("data.lignes.{$cle}.quantite", '3')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '54')
            ->call('create')
            ->assertHasNoFormErrors();

        $ligne = Devis::query()->firstOrFail()->lignes->firstOrFail();
        $this->assertSame($pose->id, $ligne->service_id);
        $this->assertSame('Pose', $ligne->description);
        $this->assertSame(NatureLigne::Service, $ligne->nature);
        $this->assertSame('135.00', $ligne->montant_ht);
    }

    public function test_envoyer_un_devis_brouillon_depuis_la_liste_lui_attribue_un_numero(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);

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

    /**
     * Un client archivé n'est plus proposé pour un nouveau devis.
     */
    public function test_un_client_archive_n_est_plus_propose_pour_un_nouveau_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $proposable = Client::factory()->create(['nom' => 'Client proposé']);
        $archive = Client::factory()->trashed()->create(['nom' => 'Client archivé']);

        Livewire::test(CreateDevis::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => array_key_exists($proposable->id, $champ->getOptions())
                && ! array_key_exists($archive->id, $champ->getOptions()));
    }

    /**
     * Une requête forgée qui envoie malgré tout un client archivé est
     * refusée, et aucun devis n'est créé.
     */
    public function test_un_client_archive_est_refuse_a_la_creation_d_un_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $archive = Client::factory()->trashed()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);

        Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $archive->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Devis::query()->count());
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    public function test_refuser_un_devis_envoye_depuis_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->callTableAction('refuser', $devis);

        $this->assertSame(StatutDevis::Refuse, $devis->fresh()->statut);
    }

    public function test_convertir_un_devis_accepte_en_facture_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);
        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }

    public function test_impossible_de_convertir_un_devis_pas_encore_accepte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);

        Livewire::test(ListDevis::class)->assertTableActionHidden('convertir', $devis->fresh());
    }

    /**
     * Un devis accepté pas encore transformé en facture se modifie, lignes
     * comprises (K8) : il repasse en brouillon, son acceptation effacée, et
     * garde son numéro.
     */
    public function test_modifier_un_devis_accepte_remplace_les_lignes_recalcule_les_totaux_et_le_repasse_en_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
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

        $numero = $devis->numero;
        $devis->refresh();
        $this->assertSame($autreClient->id, $devis->client_id);
        $this->assertSame(1, $devis->lignes()->count());
        $this->assertSame('250.00', $devis->total_ht);
        $this->assertSame([StatutDevis::Brouillon, $numero], [$devis->statut, $devis->numero]);
    }

    /**
     * La page prévient, avant l'enregistrement, qu'un devis envoyé ou
     * accepté repasse en brouillon ; un brouillon n'a rien à annoncer.
     */
    public function test_la_page_de_modification_annonce_le_retour_en_brouillon_d_un_devis_envoye_ou_accepte(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $brouillon = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $envoye = $this->envoyerDevis(Client::factory()->create(), $produit);
        $accepte = $this->envoyerDevis(Client::factory()->create(), $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $accepte);

        Livewire::test(EditDevis::class, ['record' => $envoye->getRouteKey()])
            ->assertSee('Modifier un devis envoyé le repasse en brouillon : renvoyez-le ensuite à votre client.');
        Livewire::test(EditDevis::class, ['record' => $accepte->getRouteKey()])
            ->assertSee('Modifier un devis accepté le repasse en brouillon et efface son acceptation : renvoyez-le ensuite à votre client.');
        Livewire::test(EditDevis::class, ['record' => $brouillon->getRouteKey()])
            ->assertDontSee('le repasse en brouillon');
    }

    /**
     * Refusé, un devis reste tel quel : la liste ne propose plus de le
     * modifier, et sa page de modification ne s'ouvre pas.
     */
    public function test_un_devis_refuse_ne_se_modifie_plus(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $devis = $this->envoyerDevis(Client::factory()->create(), Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]));
        Livewire::test(ListDevis::class)->callTableAction('refuser', $devis);

        Livewire::test(ListDevis::class)->assertTableActionHidden('edit', $devis->fresh());
        $this->get(DevisResource::getUrl('edit', ['record' => $devis], tenant: $magasin))->assertForbidden();
    }

    /**
     * Le lendemain du dernier jour de sa validité, un devis envoyé ne
     * propose plus « Accepter », même avant son passage à « Expiré » dans
     * la nuit ; « Refuser » reste possible.
     */
    public function test_accepter_n_est_plus_propose_une_fois_la_validite_depassee(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => '2026-10-08']));
        $this->travelTo('2026-10-09 10:00:00');

        Livewire::test(ListDevis::class)
            ->assertTableActionHidden('accepter', $devis)
            ->assertTableActionVisible('refuser', $devis);
    }

    public function test_enregistrer_un_devis_garde_le_service_de_ses_lignes_de_service(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $ligne = $devis->fresh()->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertSame('15.00', $ligne->montant_ttc);
    }

    /**
     * Enregistré en franchise en base, un service est à 0 % sans que ce
     * taux ait été saisi : le devis rouvert après la sortie de la
     * franchise propose le taux du territoire du magasin, et réenregistré,
     * il se recalcule avec sa TVA au même prix TTC.
     */
    public function test_un_service_enregistre_en_franchise_reprend_le_taux_du_territoire_apres_la_sortie_de_la_franchise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => Service::factory()->create(['nom' => 'Pose écran'])->id, 'prix_unitaire_ttc' => '120.00', 'taux_tva' => 20, 'quantite' => 1],
        ]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::ReelNormalMensuel]);

        $component = Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()]);
        $component
            ->assertSet('data.lignes.'.array_key_first($component->get('data.lignes')).'.taux_tva', '20')
            ->call('save')
            ->assertHasNoFormErrors();

        $devis->refresh();
        $this->assertFalse($devis->franchise_en_base);
        $this->assertSame('20.00', $devis->lignes->sole()->taux_tva);
        $this->assertSame(['100.00', '20.00', '120.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
    }

    public function test_laction_modifier_disparait_des_quun_devis_est_converti_en_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis);

        Livewire::test(ListDevis::class)->assertTableActionVisible('edit', $devis->fresh());

        Livewire::test(ListDevis::class)->callTableAction('convertir', $devis->fresh());

        Livewire::test(ListDevis::class)->assertTableActionHidden('edit', $devis->fresh());
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateDevis::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Acme Corp',
                'type_client' => 'professionnel',
                'siret' => '12121212000012',
                'numero_tva' => 'FR12345678900',
                'adresse' => '10 rue de la Paix',
                'code_postal' => '75002',
                'ville' => 'Paris',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', [
            'nom' => 'Acme Corp',
            'siret' => '12121212000012',
            'numero_tva' => 'FR12345678900',
            'adresse' => '10 rue de la Paix',
            'code_postal' => '75002',
            'ville' => 'Paris',
        ]);
    }

    /**
     * Exigence explicite : le PU HT reste visible sur une ligne (mention
     * nécessaire), à côté du PU TTC qui, lui, se saisit et fait référence
     * pour le calcul.
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
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
     * Changer le taux de TVA d'un service -- seul taux qui se saisit, celui
     * d'un produit étant celui de sa fiche (C15) -- recalcule son HT à
     * partir du TTC (qui fait référence), jamais l'inverse : sans quoi le
     * prix TTC visé changerait sous les yeux du vendeur au seul changement
     * de taux.
     */
    public function test_changer_le_taux_de_tva_d_un_service_recalcule_le_ht_sans_changer_le_ttc_affiche(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Pose']);

        $component = Livewire::test(CreateDevis::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['service_id' => $service->id, 'description' => 'Pose', 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ]);

        $cle = array_key_first($component->get('data.lignes'));
        $component->set("data.lignes.{$cle}.prix_unitaire_ttc", '12');
        $component->set("data.lignes.{$cle}.taux_tva", '5.5');

        $this->assertSame(12.0, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ttc"));
        // 12,00 / 1,055 = 11,374... -> 11,37 € HT, au centime.
        $component->assertSee('Total HT 11,37 € · TVA 0,63 € · Total TTC 12,00 €');
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]);

        $this->assertSame(['76.67', '13.33', '90.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('66,67')
            ->assertSee('13,33')
            ->assertSee('76,67')
            ->assertSee('TVA par taux')
            ->assertSee('20 %')
            ->assertSee('0 %');
    }

    public function test_le_client_d_un_autre_magasin_est_refuse_a_la_modification_d_un_devis(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $clientDUnAutreMagasin = Client::factory()->create();
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()])
            ->fillForm(['client_id' => $clientDUnAutreMagasin->id])
            ->call('save')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame($client->id, $devis->fresh()->client_id);
    }

    /**
     * L'observateur, en lecture seule, n'a pas le bouton de suppression en
     * masse, et une requête forgée ne supprime aucun devis.
     */
    public function test_un_observateur_ne_supprime_pas_de_devis_en_masse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, null, [
            'name' => 'Léa', 'email' => 'lea@example.test',
        ]);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($this->proprietaireDe($magasin), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);

        Livewire::test(ListDevis::class)
            ->assertTableBulkActionHidden('delete')
            ->call('mountTableBulkAction', 'delete', [$devis->getKey()])
            ->call('callMountedTableBulkAction');

        $this->assertNotSoftDeleted($devis);
    }

    /**
     * Chaque devis coché repasse par la policy : celui déjà converti en
     * facture reste, et l'utilisateur est prévenu.
     */
    public function test_la_suppression_en_masse_garde_le_devis_deja_converti_en_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devisBrouillon = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $devisConverti = $this->envoyerDevis($client, $produit);
        Livewire::test(ListDevis::class)->callTableAction('accepter', $devisConverti);
        Livewire::test(ListDevis::class)->callTableAction('convertir', $devisConverti->fresh());

        Livewire::test(ListDevis::class)
            ->callTableBulkAction('delete', [$devisBrouillon, $devisConverti->fresh()])
            ->assertNotified('1 ligne cochée n\'a pas été supprimée');

        $this->assertSoftDeleted($devisBrouillon);
        $this->assertNotSoftDeleted($devisConverti);
        $this->assertSame($devisConverti->id, Facture::query()->sole()->devis_id);
    }

    /**
     * Le propriétaire connecté d'un magasin de Lyon au n° de TVA
     * FR88100000009, qui vend hors de France.
     */
    private function magasinQuiVendHorsDeFrance(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire));
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Un devis de conseil autoliquidé pour un professionnel allemand, rouvert
     * après que sa fiche est devenue celle d'un particulier : il repasse en
     * vente en France, son prix sans TVA devient un prix TTC (500,00 € à
     * 20 % : 600,00 €), et la page le dit. Réenregistré, il porte la TVA
     * française et plus la mention « Autoliquidation ».
     */
    public function test_un_devis_rouvert_apres_que_son_client_est_devenu_particulier_repasse_en_vente_en_france(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['service_id' => Service::factory()->create(['nom' => 'Conseil'])->id, 'prix_unitaire_ttc' => '500.00', 'quantite' => 1]], ['nature_operation' => NatureOperation::PrestationUeAutoliquidation]);
        $client->update(['type_client' => TypeClient::Particulier, 'numero_tva' => null]);

        $page = Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()]);
        $page->assertSet('data.nature_operation', NatureOperation::VenteEnFrance->value)
            ->assertSet('data.lignes.'.array_key_first($page->get('data.lignes')).'.prix_unitaire_ttc', '600.00')
            ->assertNotified('« Prestation à un professionnel de l\'UE (autoliquidation) » n\'est plus possible')
            ->call('save')
            ->assertHasNoFormErrors();

        $devis->refresh();
        $this->assertSame(NatureOperation::VenteEnFrance, $devis->nature_operation);
        $this->assertSame(['500.00', '100.00', '600.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertStringNotContainsString('Autoliquidation', app(DevisPdfGenerator::class)->html($devis));
    }

    /**
     * Un devis d'export rouvert après l'entrée du magasin en franchise en
     * base repasse en vente en France : réenregistré, il ne cite plus
     * l'exonération de l'export, seulement la franchise.
     */
    public function test_un_devis_rouvert_apres_l_entree_du_magasin_en_franchise_repasse_en_vente_en_france(): void
    {
        $magasin = $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '1.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1000]], [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street',
            'livraison_code_postal' => '10001',
            'livraison_ville' => 'New York',
            'livraison_pays' => 'US',
        ]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);

        Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSet('data.nature_operation', NatureOperation::VenteEnFrance->value)
            ->assertNotified('« Export hors UE expédié par le magasin » n\'est plus possible')
            ->call('save')
            ->assertHasNoFormErrors();

        $devis->refresh();
        $pdf = app(DevisPdfGenerator::class)->html($devis);
        $this->assertSame([NatureOperation::VenteEnFrance, true, null], [$devis->nature_operation, $devis->franchise_en_base, $devis->livraison_pays]);
        $this->assertSame(['1000.00', '0.00', '1000.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertStringContainsString('TVA non applicable, art. 293 B du CGI', $pdf);
        $this->assertStringNotContainsString('262 I', $pdf);
    }

    /**
     * La liste des natures ne restreint ses choix qu'à l'affichage : une
     * nature réservée aux professionnels envoyée directement pour un
     * particulier est refusée, et aucun devis ne s'enregistre.
     */
    public function test_une_nature_que_le_client_ne_peut_pas_choisir_est_refusee_meme_envoyee_directement(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $service = Service::factory()->create(['nom' => 'Conseil']);

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => Client::factory()->create()->id, 'lignes' => [['service_id' => $service->id, 'description' => 'Conseil', 'quantite' => 1, 'prix_unitaire_ttc' => '500.00']]])
            ->set('data.nature_operation', NatureOperation::PrestationUeAutoliquidation->value)
            ->call('create')
            ->assertHasFormErrors(['nature_operation' => 'Choisissez une nature proposée pour ce client.']);

        $this->assertSame(0, Devis::query()->count());
    }
}
