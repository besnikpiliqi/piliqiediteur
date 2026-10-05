<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        // Premier achat intégralement retourné : 60 € de crédit disponible.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $premiereFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $premiereFacture);

        $ligneFacture = $premiereFacture->fresh()->lignes->firstOrFail();
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

        // Premier achat intégralement retourné : 10 € de crédit disponible.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produitRembourse->id, 'description' => $produitRembourse->nom, 'quantite' => 1, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $premiereFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $premiereFacture);

        $ligneFacture = $premiereFacture->fresh()->lignes->firstOrFail();
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
     * Même besoin que pour les devis : un magasin de services (pas de
     * stock) doit pouvoir facturer de la main d'œuvre ou une prestation
     * jamais enregistrée au catalogue, en remplissant la ligne à la main.
     */
    public function test_creer_une_facture_avec_une_ligne_entierement_libre_sans_produit(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['description' => 'Main d\'œuvre - installation', 'quantite' => 2, 'prix_unitaire_ht' => 60, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main d\'œuvre - installation', $ligne->description);
        $this->assertSame('120.00', $facture->total_ht);
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
     * d'être créé : seule une date à partir d'aujourd'hui est acceptée.
     */
    public function test_impossible_de_choisir_une_echeance_dans_le_passe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'date_echeance' => now()->subDay()->toDateString(),
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['date_echeance']);

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
        $this->assertSame('8.000', $produit->fresh()->stock_quantite);
    }

    public function test_annuler_une_facture_emise_depuis_le_panneau_restaure_le_stock(): void
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
        $this->assertSame('7.000', $produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)->callTableAction('annuler', $facture->fresh(), data: ['motif' => 'Erreur de saisie']);

        $facture->refresh();
        $this->assertSame(StatutFacture::Annulee, $facture->statut);
        $this->assertSame('10.000', $produit->fresh()->stock_quantite);
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

        Livewire::test(ListFactures::class)
            ->callTableAction('telecharger_pdf', $facture->fresh())
            ->assertSuccessful();
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
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::FACTURE_CREATE, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);

        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $vendeurA->assignRole($roleVendeur);
        $vendeurB->assignRole($roleVendeur);
        foreach ([$vendeurA, $vendeurB] as $vendeur) {
            $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);
        }

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
}
