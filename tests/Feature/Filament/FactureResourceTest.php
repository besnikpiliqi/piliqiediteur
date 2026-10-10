<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Tests\TestCase;

class FactureResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le résumé d'un avoir (numéro/motif/total) ne dit pas CE QUI a été
     * retourné : le détail des lignes créditées (produit, quantité,
     * montant) doit être visible directement sur la page de la facture
     * créditée, pas seulement sur la page dédiée de l'avoir.
     */
    public function test_la_facture_affiche_le_detail_des_produits_credites_par_un_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Écran iPhone 17 Pro', 'prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $facture = $facture->fresh();

        $ligneFacture = $facture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ], 'Produit défectueux');
        app(EmettreAvoirAction::class)->handle($avoir);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Avoirs émis')
            ->assertSee($avoir->fresh()->numero)
            ->assertSee('Produit défectueux')
            ->assertSee('Écran iPhone 17 Pro');
    }

    /**
     * Le Repeater de lignes à créditer de l'action "Avoir" est
     * volontairement "deletable(true)" (voir son docblock) pour permettre
     * de retirer une ligne à ne pas rembourser : un vendeur peut donc, par
     * erreur, les retirer toutes avant de valider. CreerAvoirAction refuse
     * alors de créer un avoir sans aucune ligne -- avant la correction,
     * cette LogicException remontait telle quelle jusqu'à une page
     * d'erreur brute au lieu d'une notification.
     */
    public function test_laction_avoir_sans_aucune_ligne_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture->fresh(), data: ['lignes' => [], 'motif' => 'Oups'])
            ->assertNotified('Un avoir doit porter sur au moins une ligne.');

        $this->assertSame(0, Avoir::query()->count());
    }

    /**
     * Le crédit appliqué automatiquement sur une facture (voir
     * AppliquerAvoirsDisponiblesAction) doit être visible et déclaré
     * directement sur cette facture, pas seulement déductible du calcul.
     */
    public function test_la_facture_affiche_le_credit_applique_automatiquement_par_un_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        // Nouvel achat du même montant : couvert automatiquement par le crédit.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::Payee, $nouvelleFacture->statut);

        Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Crédité par avoir')
            ->assertSee($avoir->fresh()->numero)
            // Filament insère une espace insécable (U+00A0) entre le montant
            // et le symbole monétaire : on vérifie juste le montant.
            ->assertSee('60,00');

        // Le numéro d'avoir doit permettre d'ouvrir sa fiche (date, motif,
        // produits retournés), pas rester un simple texte.
        $html = Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])->html();
        $this->assertStringContainsString(AvoirResource::getUrl('view', ['record' => $avoir->fresh()]), $html);
    }

    /**
     * Une vente choisit toujours un produit existant (ou un service, par son
     * bouton), jamais une ligne libre.
     */
    public function test_une_ligne_sans_produit_est_refusee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['quantite' => 1, 'prix_unitaire_ttc' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['lignes.0.produit_id' => 'required']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le nom vient du catalogue et le prix d'une vente se saisit en TTC :
     * ni la description ni le PU HT d'une ligne ne se modifient ici.
     */
    public function test_la_description_et_le_pu_ht_dune_ligne_ne_se_modifient_pas(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateFacture::class);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertFormFieldIsDisabled("lignes.{$cle}.description")
            ->assertFormFieldIsDisabled("lignes.{$cle}.prix_unitaire_ht")
            ->assertFormFieldIsEnabled("lignes.{$cle}.prix_unitaire_ttc")
            ->assertFormComponentActionDoesNotExist("lignes.{$cle}.produit_id", 'createOption');
    }

    /**
     * Un avoir n'est jamais remboursé par ce logiciel : son crédit réduit le
     * solde dû exactement comme un encaissement, et c'est lui qui explique
     * le statut "Partiellement payée" d'une facture jamais réellement
     * encaissée. La colonne "Payé" de la liste doit donc refléter ce
     * crédit -- sans quoi elle affiche "0,00 €" à côté d'un statut
     * "Partiellement payée", un résultat qui se lit comme une
     * contradiction/un bug plutôt que comme un règlement par avoir.
     */
    public function test_la_colonne_paye_de_la_liste_inclut_le_credit_applique_par_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produitRembourse = Produit::factory()->create(['prix_vente_ht' => 8.33, 'taux_tva' => 20, 'suivi_stock' => false]);
        $produitFacture = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 10 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produitRembourse->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        // Nouvel achat d'un montant supérieur : seulement couvert en partie
        // par le crédit, aucun encaissement réel.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produitFacture->id, 'description' => $produitFacture->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::PartiellementPayee, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantPaye());
        $this->assertSame(10.0, $nouvelleFacture->montantCrediteParAvoir());

        // On passe la clé, pas le modèle déjà chargé : assertTableColumnStateSet
        // ne re-résout la ligne via la requête de la table (et donc ses
        // withSum) que si on lui laisse le faire elle-même -- un modèle
        // déjà en main (même fresh()) n'aurait pas ces agrégats et
        // fausserait le test.
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('montant_paye_somme', 10.0, $nouvelleFacture->getKey());
    }

    /**
     * Même exigence que pour la colonne "Payé" de la liste
     * (test_la_colonne_paye_de_la_liste_inclut_le_credit_applique_par_avoir),
     * mais pour le champ "Payé" de la fiche détail : il doit lui aussi
     * inclure le crédit appliqué par avoir, sans quoi il affiche "0,00 €"
     * à côté d'un statut "Partiellement payée" -- une contradiction.
     */
    public function test_la_fiche_facture_affiche_le_paye_incluant_le_credit_applique_par_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        // Montants choisis pour qu'aucun total légitime de la page ne se
        // termine par "0,00" (voir plus bas pourquoi) : 10,83 € HT + 20 %
        // de TVA = 13,00 € TTC de crédit disponible.
        $produitRembourse = Produit::factory()->create(['prix_vente_ht' => 10.83, 'taux_tva' => 20, 'suivi_stock' => false]);
        $produitFacture = Produit::factory()->create(['prix_vente_ht' => 51, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 13 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produitRembourse->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        // Nouvel achat d'un montant supérieur (61,20 € TTC) : seulement
        // couvert en partie par le crédit, aucun encaissement réel.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produitFacture->id, 'description' => $produitFacture->nom, 'quantite' => 1, 'prix_unitaire_ht' => 51, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::PartiellementPayee, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantPaye());
        $this->assertSame(13.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(48.2, $nouvelleFacture->montantRestant());

        // "0,00 €" ne doit apparaître nulle part sur la fiche : avant la
        // correction, c'est précisément la valeur affichée par le champ
        // "Payé" (montantPaye() seul, sans le crédit), alors que le statut
        // "Partiellement payée" prouve qu'une partie du montant est bien
        // réglée. Aucun des autres montants de cette fiche (13,00 / 10,83 /
        // 2,17 / 51,00 / 10,20 / 61,20 / 48,20) ne se termine par "0,00",
        // ce qui permet de n'assertDontSee que la vraie valeur à exclure.
        Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('13,00')
            ->assertDontSee('0,00');
    }

    /**
     * La main-d'œuvre se facture comme à la caisse : par le bouton
     * « Ajouter un service ».
     */
    public function test_creer_une_facture_avec_une_ligne_de_service(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $installation = Service::factory()->create(['nom' => 'Installation']);

        $component = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id, 'lignes' => []])
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$installation->id]]);
        $cle = array_key_first($component->get('data.lignes'));
        // 72 € TTC à 20 % de TVA = 60 € HT, par 2.
        $component
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '72')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();
        $this->assertSame($installation->id, $ligne->service_id);
        $this->assertSame('Installation', $ligne->description);
        $this->assertSame('120.00', $facture->total_ht);
    }

    /**
     * Ferme la boucle du cas signalé par un magasin en passant par le vrai
     * champ "PU TTC" tapé en direct (comme un vendeur), pas par une valeur
     * HT précalculée dans le test : 40 € TTC à 20 % de TVA et quantité 2
     * doit totaliser exactement 80,00 € TTC, jamais 79,99 €.
     */
    public function test_saisir_le_prix_ttc_dune_ligne_totalise_exactement_a_quantite_2(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 35, 'taux_tva' => 20]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '40');

        $component->call('create')->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();
        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Bug rapporté par un magasin : en tapant "5.5" dans le taux de TVA (ou
     * "12.5" dans un prix), le champ redevenait "5" -- une réponse du
     * serveur arrivée en pleine frappe réécrivait la case. Les champs
     * calculés en direct ne doivent jamais être réécrits tant qu'ils ont le
     * focus (modificateur "unintrusive"), et le script du panneau renvoie
     * ce qu'ils affichent si une réponse l'a fait diverger. Vérifié aussi
     * dans un vrai navigateur, serveur ralenti : voir le message de commit.
     */
    public function test_les_champs_calcules_en_direct_ne_sont_jamais_reecrits_pendant_la_frappe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $html = Livewire::test(CreateFacture::class)->html();

        foreach (['quantite', 'prix_unitaire_ttc', 'taux_tva', 'remise_pourcentage'] as $champ) {
            $this->assertMatchesRegularExpression(
                '/wire:model\.live\.unintrusive="data\.lignes\.[^"]+\.'.$champ.'"/',
                $html,
                "Le champ {$champ} doit rester tel que tapé pendant la frappe.",
            );
        }

        $this->get(FactureResource::getUrl('create'))
            ->assertSuccessful()
            ->assertSee("Livewire.hook('commit'", false)
            ->assertSee("includes('unintrusive')", false);
    }

    /**
     * "5," ou "5." en route vers "5,5" n'est pas encore un nombre : le
     * navigateur l'envoie vide. Un taux vide ne doit rien recalculer, sans
     * quoi le PU HT sautait à la valeur du TTC (TVA à 0 %) en pleine frappe.
     */
    public function test_un_taux_de_tva_en_cours_de_frappe_ne_recalcule_rien(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => Client::factory()->create()->id]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '12')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '10.00')
            ->set("data.lignes.{$cle}.taux_tva", '')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '10.00')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '12')
            ->set("data.lignes.{$cle}.taux_tva", '5.5')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '11.37');
    }

    /**
     * Facture signalée par le magasin : 2 écrans à 40 € TTC (20 %), un câble
     * à 10 € sans TVA, une coque à 10 € TTC (20 %). Chaque article a son HT,
     * sa TVA et son TTC, et chaque ligne les multiplie par la quantité : le
     * total HT est la somme visible des lignes (84,99 €, pas 85,00 €), la
     * TVA la somme des TVA des lignes (15,01 €), et HT + TVA = TTC. La fiche
     * et le PDF montrent le HT et la TVA de chaque ligne, et le récapitulatif
     * par taux.
     */
    public function test_la_facture_du_magasin_totalise_84_99_ht_et_le_montre_ligne_par_ligne(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro Max', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Cable ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $coque = Produit::factory()->create(['nom' => 'Coque ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
            ['produit_id' => $coque->id, 'quantite' => 1],
        ]);

        $this->assertSame(['84.99', '15.01', '100.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 74.99, 'montant_tva' => 15.01, 'montant_ttc' => 90.0],
            ['taux_tva' => '0.00', 'montant_ht' => 10.0, 'montant_tva' => 0.0, 'montant_ttc' => 10.0],
        ], $facture->ventilation_tva);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('66,66')
            ->assertSee('13,34')
            ->assertSee('84,99')
            ->assertSee('15,01')
            ->assertSee('TVA par taux')
            ->assertSee('74,99')
            ->assertDontSee('85,00');

        $pdf = view('factures.pdf', ['facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir')])->render();
        $this->assertStringContainsString('66,66', $pdf);
        $this->assertStringContainsString('74,99', $pdf);
        $this->assertStringContainsString('84,99', $pdf);
        $this->assertStringNotContainsString('85,00', $pdf);
    }

    /**
     * Capture d'écran du magasin : une quantité s'écrit "2", jamais
     * "2.000", et un taux "20 %" ou "0 %", jamais "20.00 %" -- sur la
     * fiche, sur le PDF remis au client, et dans le formulaire quand on
     * rouvre le brouillon.
     */
    public function test_quantites_et_taux_saffichent_sans_zeros_inutiles_partout(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro Max', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Cable ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]);

        $this->assertSame(2, $facture->lignes->firstOrFail()->quantite);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('20 %')
            ->assertSee('0 %')
            ->assertDontSee('2.000')
            ->assertDontSee('1.000')
            ->assertDontSee('20.00 %')
            ->assertDontSee('0.00 %');

        $pdf = view('factures.pdf', ['facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir')])->render();
        $this->assertStringContainsString('<td class="montant">2</td>', $pdf);
        $this->assertStringContainsString('<td class="montant">20 %</td>', $pdf);
        $this->assertStringContainsString('<td class="montant">0 %</td>', $pdf);
        $this->assertStringNotContainsString('2.000', $pdf);

        $component = Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertSet("data.lignes.{$cle}.quantite", 2)
            ->assertSet("data.lignes.{$cle}.taux_tva", '20')
            ->assertSet("data.lignes.{$cle}.remise_pourcentage", '0');
    }

    public function test_une_quantite_non_entiere_est_refusee_par_le_formulaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '1.5')
            ->call('create')
            ->assertHasFormErrors(["lignes.{$cle}.quantite" => 'integer']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le PU TTC tapé est gardé tel quel, le PU HT en est la conversion au
     * centime, et la ligne multiplie séparément HT et TTC par la quantité.
     */
    public function test_le_pu_ttc_saisi_est_garde_tel_quel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));

        // 10,05 € TTC / 1,20 = 8,375 -> 8,38 € HT.
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '3')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '10.05')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '8.38')
            ->assertSee('30,15 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(['25.14', '5.01', '30.15'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Reproduit le cas signalé par un magasin : un produit visé à 40 € TTC,
     * vendu par 2, affichait 79,99 € (33,33 € HT x 2 x 1,20). Le PU TTC de
     * la ligne fait foi : 40 x 2 = 80,00 €.
     */
    public function test_un_produit_vise_a_un_prix_ttc_rond_totalise_exactement_a_quantite_2(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Un client archivé garde ses factures (voir Facture::client()), mais
     * n'est plus proposé pour une nouvelle.
     */
    public function test_un_client_archive_n_est_plus_propose_pour_une_nouvelle_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $proposable = Client::factory()->create(['nom' => 'Client proposé']);
        $archive = Client::factory()->create(['nom' => 'Client archivé']);
        $archive->delete();

        Livewire::test(CreateFacture::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => array_key_exists($proposable->id, $champ->getOptions())
                && ! array_key_exists($archive->id, $champ->getOptions()));
    }

    /**
     * Le client d'un brouillon archivé depuis reste affiché sur sa
     * modification, sans être proposé aux autres.
     */
    public function test_le_client_archive_d_un_brouillon_reste_affiche_a_sa_modification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Client archivé']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->delete();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getOptionLabel() === 'Client archivé');
    }

    public function test_lecheance_est_prereplie_a_aujourdhui_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateFacture::class)
            ->assertFormSet(['date_echeance' => now()->toDateString()]);
    }

    /**
     * Une échéance dans le passé n'a pas de sens pour un document qui vient
     * d'être créé : seule une date à partir d'aujourd'hui est acceptée, et
     * le message écrit la date comme on l'écrit en français.
     */
    public function test_impossible_de_choisir_une_echeance_dans_le_passe(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'date_echeance' => '2026-10-08',
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['date_echeance' => 'Le champ échéance doit être une date postérieure ou égale au 09/10/2026.']);

        $this->assertSame(0, Facture::query()->count());
    }

    public function test_creer_et_emettre_une_facture_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertSame($proprietaire->id, $facture->cree_par);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        $facture->refresh();
        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertNotNull($facture->numero);
        $this->assertSame(8, $produit->fresh()->stock_quantite);
    }

    /**
     * Sans clé de signature, le registre fiscal refuse l'émission : la
     * facture reste un brouillon sans numéro, l'utilisateur en lit la
     * raison au lieu d'une erreur serveur, et l'incident est signalé au
     * journal.
     */
    public function test_sans_cle_de_signature_l_emission_est_refusee_avec_un_message(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableAction('emettre', $facture)
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
        $this->assertSame([StatutFacture::Brouillon, null], [$facture->fresh()->statut, $facture->fresh()->numero]);
    }

    /**
     * Même le propriétaire, qui a toutes les permissions, n'a aucune action
     * pour annuler une facture émise : il la corrige par un avoir, qui rend
     * le stock, et la facture reste émise avec son numéro.
     */
    public function test_une_facture_emise_ne_s_annule_pas_depuis_le_panneau_elle_se_corrige_par_un_avoir(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $numero = $facture->fresh()->numero;
        $this->assertSame(7, $produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)
            ->assertTableActionDoesNotExist('annuler')
            ->assertTableActionVisible('avoir', $facture->fresh())
            ->callTableAction('avoir', $facture->fresh(), data: ['motif' => 'Erreur de saisie']);

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, $numero], [$facture->statut, $facture->numero]);
        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    public function test_telecharger_le_pdf_dune_facture_emise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        $telechargement = Livewire::test(ListFactures::class)
            ->callTableAction('telecharger_pdf', $facture->fresh())
            ->assertFileDownloaded("ticket-{$facture->fresh()->numero}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
    }

    public function test_un_brouillon_est_supprimable_mais_plus_une_fois_emis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();

        Livewire::test(ListFactures::class)->assertTableActionVisible('delete', $facture);

        Livewire::test(ListFactures::class)->callTableAction('delete', $facture);
        $this->assertModelMissing($facture);
    }

    /**
     * Supprimer un brouillon inscrit l'abandon de la vente au journal des
     * événements : si le journal ne s'écrit pas, le magasin en lit la
     * raison et le brouillon reste.
     */
    public function test_sans_cle_de_signature_la_suppression_d_un_brouillon_est_refusee_avec_un_message(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableAction('delete', $brouillon)
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertModelExists($brouillon);
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    public function test_sans_cle_de_signature_la_suppression_groupee_garde_le_brouillon_et_le_signale(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableBulkAction('delete', [$brouillon])
            ->assertNotified('1 ligne cochée n\'a pas été supprimée');

        $this->assertModelExists($brouillon);
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    /**
     * Un brouillon doit rester modifiable sans devoir être recréé (lignes
     * comprises), pour ne pas obliger l'utilisateur à tout ressaisir en cas
     * d'erreur de saisie -- voir ModifierFactureAction.
     */
    public function test_modifier_un_brouillon_depuis_le_panneau_remplace_les_lignes_et_recalcule_les_totaux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm([
                'client_id' => $autreClient->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            // Filament reste par défaut sur le formulaire après sauvegarde :
            // une fois le brouillon corrigé, le vendeur n'a plus rien à y
            // faire et doit revenir directement à la liste.
            ->assertRedirect(FactureResource::getUrl('index'));

        $facture->refresh();
        $this->assertSame($autreClient->id, $facture->client_id);
        $this->assertSame(1, $facture->lignes()->count());
        $this->assertSame('150.00', $facture->total_ht);
    }

    /**
     * Rouvrir un brouillon puis l'enregistrer sans rien toucher ne doit
     * jamais faire bouger son total : le PU TTC enregistré (qui fait
     * référence) est rechargé tel quel dans le formulaire, pas reconstruit
     * à partir du HT.
     */
    public function test_reenregistrer_un_brouillon_sans_modification_garde_exactement_son_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $this->assertSame('80.00', $facture->total_ttc);

        $component = Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '40.00')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '33.33')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('80.00', $facture->fresh()->total_ttc);
    }

    public function test_laction_modifier_disparait_des_quune_facture_est_emise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->assertTableActionVisible('edit', $facture);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListFactures::class)->assertTableActionHidden('edit', $facture->fresh());
    }

    public function test_la_suppression_en_masse_ignore_les_factures_deja_emises(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $factureBrouillon = Facture::query()->latest('id')->firstOrFail();

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $factureEmise = Facture::query()->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $factureEmise);

        Livewire::test(ListFactures::class)
            ->callTableBulkAction('delete', [$factureBrouillon->fresh(), $factureEmise->fresh()]);

        $this->assertModelMissing($factureBrouillon);
        $this->assertModelExists($factureEmise);
    }

    public function test_la_page_de_detail_affiche_leg_reporting_pour_un_client_particulier(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(['type_client' => TypeClient::Particulier]);
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($facture->numero)
            ->assertSee('e-reporting agrégé');
    }

    public function test_la_page_de_detail_affiche_le_statut_pdp_pour_un_client_professionnel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        MandatPdp::factory()->create(['magasin_id' => $magasin->id]);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(['type_client' => TypeClient::Professionnel]);
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Acceptée par le PDP');
    }

    /**
     * @return Facture
     */
    public function test_enregistrer_un_brouillon_garde_le_service_de_ses_lignes_de_service(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $ligne = $facture->fresh()->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertSame('15.00', $ligne->montant_ttc);
    }

    private function emettreFactureDepuisLePanneau(Client $client)
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_la_liste_ne_montre_que_les_factures_du_vendeur_sans_vue_totale(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20]);

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        Livewire::test(ListFactures::class)
            ->assertCanSeeTableRecords(Facture::where('cree_par', $vendeurB->id)->get())
            ->assertCanNotSeeTableRecords(Facture::where('cree_par', $vendeurA->id)->get());
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateFacture::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client Créé En Direct',
                'type_client' => 'professionnel',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Client Créé En Direct', 'type_client' => 'professionnel']);
    }

    /**
     * Un avoir peut couvrir plusieurs factures : la fiche d'une facture ne
     * montre que ce qu'il crédite sur elle (30,00 €), jamais le total de
     * l'avoir (100,00 €) ni les produits d'une autre facture.
     */
    public function test_un_avoir_sur_plusieurs_factures_ne_montre_que_ce_qui_credite_cette_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['nom' => 'Coque bleue', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $casque = Produit::factory()->create(['nom' => 'Casque sans fil', 'prix_vente_ttc' => 70, 'taux_tva' => 20, 'suivi_stock' => false]);
        $premiere = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [['produit_id' => $coque->id, 'quantite' => 1]]));
        $seconde = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [['produit_id' => $casque->id, 'quantite' => 1]]));

        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $premiere->lignes->firstOrFail()->id, 'quantite' => 1],
            ['ligne_facture_id' => $seconde->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $this->assertSame('100.00', $avoir->total_ttc);

        Livewire::test(ViewFacture::class, ['record' => $premiere->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Crédité sur cette facture (TTC)')
            ->assertSee('Coque bleue')
            ->assertDontSee('Casque sans fil')
            ->assertDontSee('100,00');
    }

    /**
     * Un taux de TVA de -100 % diviserait par zéro en passant du TTC au
     * HT : pendant la frappe rien ne plante, et le formulaire le refuse.
     */
    public function test_un_taux_de_tva_hors_bornes_ne_fait_rien_planter_et_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => Client::factory()->create()->id]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.description", 'Réparation')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '12')
            ->set("data.lignes.{$cle}.taux_tva", '-100')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '15')
            ->set("data.lignes.{$cle}.remise_pourcentage", '150')
            ->assertSuccessful()
            ->call('create')
            ->assertHasFormErrors([
                "lignes.{$cle}.taux_tva" => 'min',
                "lignes.{$cle}.remise_pourcentage" => 'max',
            ]);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * La remise est une mention obligatoire et explique pourquoi PU x
     * quantité diffère du total : elle apparaît sur le PDF dès qu'une ligne
     * en a une, et seulement dans ce cas.
     */
    public function test_la_remise_apparait_sur_le_pdf_seulement_si_une_ligne_en_a_une(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $avecRemise = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 2, 'remise_pourcentage' => 12.5]]);
        $sansRemise = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 2]]);

        $rendre = fn (Facture $facture): string => view('factures.pdf', ['facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir')])->render();

        $pdf = $rendre($avecRemise);
        $this->assertStringContainsString('<th class="montant">Remise</th>', $pdf);
        $this->assertStringContainsString('<td class="montant">12,5 %</td>', $pdf);
        $this->assertStringNotContainsString('<th class="montant">Remise</th>', $rendre($sansRemise));

        Livewire::test(ViewFacture::class, ['record' => $avecRemise->getRouteKey()])->assertSee('12,5 %');
    }

    /**
     * La règle « exists » qu'un Select relationship() ajoute seule
     * interroge la table entière : le client d'un autre magasin, envoyé par
     * une requête forgée, doit être refusé par la limite au magasin.
     */
    public function test_le_client_d_un_autre_magasin_est_refuse_a_la_modification_d_un_brouillon(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $clientDUnAutreMagasin = Client::factory()->create();
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['client_id' => $clientDUnAutreMagasin->id])
            ->call('save')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame($client->id, $facture->fresh()->client_id);
    }

    public function test_un_client_archive_ne_peut_pas_etre_choisi_pour_un_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $clientArchive = Client::factory()->create();
        $clientArchive->delete();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['client_id' => $clientArchive->id])
            ->call('save')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame($client->id, $facture->fresh()->client_id);
    }

    /**
     * Le client déjà rattaché n'est pas choisi dans le formulaire : archivé
     * depuis, il reste celui du brouillon, que l'on peut toujours corriger.
     */
    public function test_le_client_archive_deja_rattache_reste_celui_du_brouillon_modifie(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->delete();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'quantite' => 3, 'taux_tva' => 20, 'remise_pourcentage' => 0]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $facture->refresh();
        $this->assertSame($client->id, $facture->client_id);
        $this->assertSame('120.00', $facture->total_ttc);
    }

    /**
     * Une requête forgée qui envoie malgré tout un client archivé est
     * refusée, et aucune facture n'est créée.
     */
    public function test_un_client_archive_est_refuse_a_la_creation_d_une_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $archive = Client::factory()->trashed()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $archive->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Facture::query()->count());
    }
}
