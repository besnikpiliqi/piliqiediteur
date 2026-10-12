<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CaisseTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_formulaire_propose_le_client_comptoir_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->assertFormSet(['client_id' => $clientComptoir->id]);
    }

    public function test_un_vendeur_encaisse_une_vente_comptoir_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('48.00', $facture->total_ttc); // 2 x 20 HT, TVA 20%
        $this->assertSame(8, $produit->fresh()->stock_quantite);

        $this->assertDatabaseHas('paiements', [
            'client_id' => $clientComptoir->id,
            'mode_paiement' => 'especes',
            'montant' => 48,
        ]);
    }

    /**
     * Exigence explicite : chaque vendeur a sa propre caisse -- un vendeur
     * avec les permissions par défaut (donc sans aucune permission de
     * clôture de la journée du magasin, voir
     * RoleMagasin::permissionsParDefaut()) doit tout de même pouvoir
     * encaisser une vente comptoir.
     */
    public function test_un_vendeur_avec_les_permissions_par_defaut_peut_encaisser_une_vente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertTrue(Caisse::canAccess());

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 30, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('cree_par', $vendeur->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function permissionsDeLaCaisse(): array
    {
        return [
            'sans créer de facture' => [PermissionRegistry::FACTURE_CREATE],
            'sans émettre de facture' => [PermissionRegistry::FACTURE_EMETTRE],
            'sans enregistrer de paiement' => [PermissionRegistry::PAIEMENT_CREER],
        ];
    }

    /**
     * Encaisser crée, émet et règle une facture : la caisse demande les
     * trois permissions, pas seulement celle de créer une facture.
     */
    #[DataProvider('permissionsDeLaCaisse')]
    public function test_la_caisse_est_refusee_sans_l_une_de_ses_trois_permissions(string $permissionManquante): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, [
            ...RoleMagasin::Observateur->permissionsParDefaut(),
            ...array_diff([
                PermissionRegistry::FACTURE_CREATE,
                PermissionRegistry::FACTURE_EMETTRE,
                PermissionRegistry::PAIEMENT_CREER,
            ], [$permissionManquante]),
        ], [
            'name' => 'Léa', 'email' => 'lea@example.test',
        ]);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)->assertForbidden();
    }

    /**
     * La caisse ouverte avant le retrait d'une permission n'encaisse plus
     * rien après : Filament revérifie canAccess() à chaque requête Livewire
     * de la page, avant même d'appeler encaisser().
     */
    public function test_encaisser_est_refuse_des_que_le_vendeur_perd_une_permission_de_la_caisse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm([
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            'mode_paiement' => ModePaiement::CarteBancaire->value,
        ]);
        $this->retirerUneCase($magasin, $vendeur, PermissionRegistry::FACTURE_EMETTRE);

        $caisse->call('encaisser')->assertForbidden();

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * encaisser() revérifie elle-même les permissions de la caisse, sans
     * dépendre de la vérification de Filament à chaque requête Livewire :
     * appelée directement sur la caisse déjà remplie, elle refuse sans rien
     * émettre ni encaisser.
     */
    public function test_encaisser_reverifie_les_permissions_de_la_caisse_sans_dependre_de_filament(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm([
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            'mode_paiement' => ModePaiement::CarteBancaire->value,
        ])->instance();
        $this->retirerUneCase($magasin, $vendeur, PermissionRegistry::FACTURE_EMETTRE);

        $this->assertThrows(
            fn () => $caisse->encaisser(),
            fn (HttpException $exception): bool => $exception->getStatusCode() === 403,
        );

        $this->assertSame(0, Facture::query()->count());
        $this->assertSame(0, Paiement::query()->count());
    }

    /**
     * L'horloge du serveur a reculé d'une seconde depuis la dernière vente :
     * le registre fiscal refuse la suivante. Le vendeur en lit la raison au
     * lieu d'une erreur serveur, l'incident est signalé au journal, et rien
     * n'est enregistré.
     */
    public function test_une_horloge_qui_recule_refuse_la_vente_avec_un_message_sans_rien_enregistrer(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:05', 'UTC'));
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $clientComptoir, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:04', 'UTC'));
        Exceptions::fake([HorlogeRegressiveException::class]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser')
            ->assertNotified("L'horloge du serveur a reculé : il est 09/10/2026 08:00:04 UTC, mais le registre fiscal du magasin n°{$magasin->id} a déjà été verrouillé à 09/10/2026 08:00:05 UTC. Aucune opération fiscale n'est enregistrée tant que l'horloge n'est pas corrigée.");

        Exceptions::assertReported(HorlogeRegressiveException::class);
        $this->assertSame([1, 1], [Facture::query()->count(), Paiement::query()->count()]);
    }

    public function test_encaisser_sans_aucune_ligne_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [],
            ])
            ->call('encaisser')
            ->assertHasFormErrors(['lignes']);

        $this->assertDatabaseCount('factures', 0);
    }

    /**
     * Bug rapporté : deux lignes à 8,33 € HT (TVA 20 %) affichent chacune
     * 10,00 € TTC, donc 20,00 € au total -- mais le "Total à payer" et la
     * "Monnaie à rendre" affichaient 19,99 € / 5,01 € au lieu de 20,00 € /
     * 5,00 €, car ces deux aperçus sommaient les lignes en flottant non
     * arrondi au lieu de passer par CalculateurLigne comme la facture
     * réellement enregistrée. Voir CalculateurLigneTest pour le test unitaire
     * du calcul lui-même.
     */
    public function test_le_total_a_payer_correspond_exactement_a_la_somme_des_lignes_affichees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 8.33, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => '8.33', 'prix_unitaire_ttc' => '10.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => '8.33', 'prix_unitaire_ttc' => '10.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
                'montant_recu' => 25,
            ])
            ->assertSeeHtml('20,00 €')
            ->assertSeeHtml('5,00 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame('20.00', $facture->total_ttc);
    }

    /**
     * Même cas que la capture d'écran du magasin, côté caisse : un article
     * à 40 € TTC vendu par 2 doit faire payer exactement 80,00 €.
     */
    public function test_un_article_a_40_euros_ttc_vendu_par_2_encaisse_exactement_80_euros(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', Client::NOM_COMPTOIR)->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set('data.montant_recu', '100')
            ->assertSeeHtml('80,00 €')
            ->assertSeeHtml('20,00 €')
            ->assertDontSeeHtml('79,99 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Le crédit d'avoir du client est déduit automatiquement à l'émission
     * (voir AppliquerAvoirsDisponiblesAction) : la caisse doit donc
     * annoncer ce qui reste vraiment à payer, et rendre la monnaie sur ce
     * reste -- 50 € moins 10 € de crédit = 40 € ; 60 € reçus, 20 € rendus
     * (et non 10 €, ce qui laisserait la caisse avec 10 € de trop).
     */
    public function test_la_caisse_deduit_le_credit_davoir_du_client_du_total_et_de_la_monnaie_a_rendre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(['nom' => 'Mme Martin']);
        $produitRendu = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $venteInitiale = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $produitRendu->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteInitiale->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $this->assertSame(10.0, $client->soldeCrediteur());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)->set('data.client_id', $client->id);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '60')
            ->assertSeeHtml('40,00 €')
            ->assertSeeHtml('Total 50,00 € moins 10,00 € de crédit d&#039;avoir du client.')
            ->assertSeeHtml('20,00 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame(10.0, $facture->montantCrediteParAvoir());
        $this->assertSame(40.0, $facture->montantPaye());
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * Un vendeur pense souvent en prix affiché/TTC (ex: viser pile 12 €)
     * plutôt qu'en HT : saisir le PU TTC (qui fait référence pour le calcul)
     * doit afficher le PU HT correspondant à sa place, sans qu'il ait à
     * faire le calcul lui-même. Voir DevisResource::ligneSchema().
     */
    public function test_saisir_le_prix_ttc_dune_ligne_calcule_automatiquement_le_prix_ht(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 9, 'taux_tva' => 20]);

        $component = Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 7.5, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ]);

        $cle = array_key_first($component->get('data.lignes'));

        $component->set("data.lignes.{$cle}.prix_unitaire_ttc", '12');

        $this->assertSame(10.0, (float) $component->get("data.lignes.{$cle}.prix_unitaire_ht"));
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client fidèle',
                'type_client' => 'particulier',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Client fidèle']);
    }

    /**
     * La fenêtre « Nouveau client » de la Caisse applique les règles de la
     * fiche : un professionnel établi en France a un SIREN.
     */
    public function test_la_caisse_refuse_de_creer_un_professionnel_etabli_en_france_sans_siren(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Garage Martin',
                'type_client' => 'professionnel',
            ])
            ->assertHasFormComponentActionErrors(['siren' => 'Le SIREN (ou le SIRET) est obligatoire pour un client professionnel établi en France.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Garage Martin']);
    }

    /**
     * En espèces, un montant reçu saisi qui ne couvre pas ce qui reste à
     * payer est refusé : sinon la vente serait enregistrée payée avec un
     * tiroir-caisse en manque.
     */
    public function test_un_montant_recu_insuffisant_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '30')
            ->call('encaisser')
            ->assertHasFormErrors(['montant_recu' => 'min']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le formulaire montre le HT et la TVA de chaque ligne et les totaux de
     * la vente, calculés comme la facture qui sera enregistrée.
     */
    public function test_la_caisse_montre_le_ht_et_la_tva_de_chaque_ligne_et_les_totaux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->assertSee('66,66 € HT + 13,34 € de TVA')
            ->assertSee('Total HT 66,66 € · TVA 13,34 € · Total TTC 80,00 €');
    }

    /**
     * Pour un client professionnel, chaque ligne affiche son HT, quantité x
     * PU TTC ÷ (1 + taux) arrondi une fois, et la TVA se calcule sur le
     * total de chaque taux (E1) : 2 x 49,90 € à 20 % font 83,17 € HT, 3 x
     * 4,20 € à 5,5 % 11,94 € HT. La caisse affiche et encaisse le total
     * qu'enregistre la facture, 112,40 €, et rend 7,60 € sur 120,00 €.
     */
    public function test_une_vente_a_un_professionnel_affiche_et_encaisse_le_total_calcule_depuis_le_ht_des_lignes(): void
    {
        $this->completerIdentiteLegale($this->ouvrirLaCaisseDUnMagasin());
        $professionnel = Client::factory()->professionnel()->create();
        $sacoche = Produit::factory()->create(['prix_vente_ttc' => '49.90', 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['prix_vente_ttc' => '4.20', 'taux_tva' => 5.5, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $professionnel->id,
                'lignes' => [
                    ['produit_id' => $sacoche->id, 'description' => $sacoche->nom, 'quantite' => 2, 'prix_unitaire_ttc' => '49.90', 'remise_pourcentage' => 0],
                    ['produit_id' => $livre->id, 'description' => $livre->nom, 'quantite' => 3, 'prix_unitaire_ttc' => '4.20', 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
                'montant_recu' => 120,
            ])
            ->assertSee('83,17 €')
            ->assertSee('11,94 €')
            ->assertSee('TVA calculée sur le total')
            ->assertSee('Total HT 95,11 € · TVA 17,29 € · Total TTC 112,40 €')
            ->assertSee('7,60 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $professionnel->id)->sole();
        $this->assertSame(['95.11', '17.29', '112.40'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame(112.4, $facture->montantPaye());
    }

    /**
     * Un montant reçu absurde (des milliards de milliards) ne fait jamais
     * planter la caisse pendant la frappe, et il est refusé à l'encaissement.
     */
    public function test_un_montant_recu_absurde_ne_fait_pas_planter_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set('data.montant_recu', '100000000000000000')
            ->assertSuccessful()
            ->call('encaisser')
            ->assertHasFormErrors(['montant_recu' => 'max']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un PU TTC négatif ferait émettre un ticket de montant négatif, sans
     * rien encaisser : la caisse le refuse.
     */
    public function test_un_pu_ttc_negatif_est_refuse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '-50')
            ->call('encaisser')
            ->assertHasFormErrors(["lignes.{$cle}.prix_unitaire_ttc" => 'min'])
            ->assertSee('Le champ PU TTC doit être supérieur ou égal à 0.');

        $this->assertSame(0, Facture::query()->count());
    }

    public function test_ajouter_un_produit_ajoute_une_ligne_vide_avec_les_valeurs_par_defaut(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit');

        $ligne = $this->seuleLigne($component);
        $this->assertNull($ligne['service_id']);
        $this->assertNull($ligne['produit_id']);
        $this->assertSame(1, $ligne['quantite']);
        $this->assertSame('20', $ligne['taux_tva']);
        $component->assertFormFieldIsVisible('lignes.'.array_key_first($component->get('data.lignes')).'.produit_id');
    }

    /**
     * En franchise en base, toute vente est à 0 % au même prix TTC (B10) :
     * le taux d'une ligne de produit est désactivé, vaut 0 et le dit, et
     * le ticket encaissé n'a aucune TVA.
     */
    public function test_en_franchise_le_taux_d_un_produit_est_desactive_et_vaut_zero(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit');
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->assertFormFieldIsDisabled("lignes.{$cle}.taux_tva")
            ->assertSet("data.lignes.{$cle}.taux_tva", '0')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '12.00')
            ->assertSee('TVA non applicable')
            ->call('encaisser')
            ->assertHasNoFormErrors();

        $ticket = Facture::query()->sole();
        $this->assertSame(['12.00', '0.00', '12.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertSame('0.00', $ticket->lignes->sole()->taux_tva);
    }

    public function test_un_service_coche_devient_une_ligne_de_service_sans_produit_ni_pu_ht(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]])
            ->assertHasNoFormComponentActionErrors();

        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Remplacement écran', $ligne['description']);
        $this->assertSame(1, $ligne['quantite']);
        $this->assertNull($ligne['prix_unitaire_ttc']);
        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->assertFormFieldIsHidden("lignes.{$cle}.produit_id")
            ->assertFormFieldIsHidden("lignes.{$cle}.prix_unitaire_ht")
            ->assertFormFieldIsVisible("lignes.{$cle}.prix_unitaire_ttc")
            ->assertFormFieldIsVisible("lignes.{$cle}.taux_tva");
    }

    /**
     * L'exemple du magasin : écran 50 € + main-d'œuvre, payés ensemble. La
     * TVA du service se déduit du TTC saisi, comme pour un produit : 15,03 €
     * TTC donnent 12,53 € HT, et non le TTC recalculé depuis ce HT
     * (15,04 €).
     */
    public function test_une_vente_avec_un_service_enregistre_sa_ligne_depuis_le_ttc_saisi(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 3]);
        $mainDOeuvre = Service::factory()->create(['nom' => 'Main-d\'œuvre']);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit');
        $component->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$mainDOeuvre->id]]);
        [$cleProduit, $cleService] = array_keys($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cleProduit}.produit_id", $ecran->id)
            ->set("data.lignes.{$cleService}.prix_unitaire_ttc", '15.03')
            ->assertSee('12,53 € HT + 2,50 € de TVA')
            ->assertSee('Total HT 54,20 € · TVA 10,83 € · Total TTC 65,03 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('65.03', $facture->total_ttc);
        $ligneService = $facture->lignes->firstWhere('service_id', $mainDOeuvre->id);
        $this->assertSame(NatureLigne::Bien, $facture->lignes->firstWhere('produit_id', $ecran->id)->nature);
        $this->assertSame(NatureLigne::Service, $ligneService->nature);
        $this->assertNull($ligneService->produit_id);
        $this->assertSame('Main-d\'œuvre', $ligneService->description);
        $this->assertSame('12.53', $ligneService->montant_ht);
        $this->assertSame('2.50', $ligneService->montant_tva);
        $this->assertSame('15.03', $ligneService->montant_ttc);
        $this->assertSame(2, $ecran->fresh()->stock_quantite);
        $this->assertSame($magasin->id, $ligneService->magasin_id);
    }

    public function test_un_service_absent_de_la_liste_est_cree_et_propose_en_case_a_cocher_la_fois_suivante(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => '  Remplacement   batterie '])
            ->assertHasNoFormComponentActionErrors()
            ->assertNotified('Service « Remplacement batterie » ajouté à la liste');

        $service = Service::query()->sole();
        $this->assertSame('Remplacement batterie', $service->nom);
        $this->assertTrue($service->actif);
        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Remplacement batterie', $ligne['description']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Remplacement batterie');
    }

    public function test_un_service_deja_cree_sous_une_autre_ecriture_n_est_pas_cree_en_double(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Main d\'œuvre']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: [
                'services' => [$service->id],
                'nouveau_service' => ' MAIN  d’oeuvre',
            ])
            ->assertNotNotified('Service « Main d\'œuvre » ajouté à la liste');

        $this->assertSame(1, Service::query()->count());
        $ligne = $this->seuleLigne($component);
        $this->assertSame($service->id, $ligne['service_id']);
        $this->assertSame('Main d\'œuvre', $ligne['description']);
    }

    public function test_chaque_service_coche_devient_une_ligne_dans_l_ordre_des_cases_cochees(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $nettoyage = Service::factory()->create(['nom' => 'Nettoyage']);
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$diagnostic->id, $nettoyage->id]]);

        $this->assertSame(
            ['Diagnostic', 'Nettoyage'],
            array_values(array_column($component->get('data.lignes'), 'description')),
        );
    }

    public function test_la_fenetre_refuse_de_valider_sans_service_coche_ni_nouveau_nom(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [], 'nouveau_service' => ''])
            ->assertHasFormComponentActionErrors(['services' => ['Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']]);

        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_sans_aucun_service_cree_le_nom_du_premier_service_est_demande(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => ''])
            ->assertHasFormComponentActionErrors(['nouveau_service' => 'required']);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_un_service_archive_n_est_plus_propose_a_la_caisse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Désoxydation']);
        Service::factory()->archive()->create(['nom' => 'Flashage ancien modèle']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Désoxydation')
            ->assertDontSee('Flashage ancien modèle');
    }

    public function test_les_services_d_un_autre_magasin_ne_sont_jamais_proposes(): void
    {
        $autreProprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique'], $autreProprietaire);
        Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->ouvrirLaCaisseDUnMagasin();

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertDontSee('Service de la concurrence')
            ->assertSee('Nom du service');
    }

    /**
     * La recherche des services passe par le serveur, sans casse ni
     * accents : « ECRAN » propose « Remplacement écran », jamais le
     * service d'un autre magasin, même quand il correspond.
     */
    public function test_la_recherche_d_un_service_ignore_la_casse_et_les_accents(): void
    {
        $autreProprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique'], $autreProprietaire);
        Service::factory()->create(['nom' => 'Écran de la concurrence']);
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Remplacement écran']);
        Service::factory()->create(['nom' => 'Diagnostic']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->setFormComponentActionData(['recherche' => 'ECRAN'])
            ->assertSee('Remplacement écran')
            ->assertDontSee('Diagnostic')
            ->assertDontSee('Écran de la concurrence')
            ->assertDontSee('Aucun service ne correspond.');
    }

    /**
     * Un service déjà coché reste proposé, et ajouté, quand la recherche
     * ne le trouve plus ; une recherche sans résultat le dit.
     */
    public function test_un_service_coche_reste_propose_quand_la_recherche_ne_le_trouve_plus(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);
        Service::factory()->create(['nom' => 'Nettoyage']);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->setFormComponentActionData(['recherche' => 'batterie'])
            ->assertSee('Aucun service ne correspond.')
            ->assertDontSee('Diagnostic')
            ->assertDontSee('Nettoyage');

        $component = Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->setFormComponentActionData(['services' => [$diagnostic->id], 'recherche' => 'nettoy'])
            ->assertSee('Diagnostic')
            ->assertSee('Nettoyage')
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame($diagnostic->id, $this->seuleLigne($component)['service_id']);
    }

    /**
     * Créer un service, même depuis la caisse, demande l'autorisation de
     * créer des produits et des services : le vendeur aux autorisations par
     * défaut coche les services du magasin, sans champ pour en créer un, et
     * l'action appelée directement le refuse.
     */
    public function test_un_vendeur_avec_les_permissions_par_defaut_ne_cree_pas_de_service_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        Service::factory()->create(['nom' => 'Diagnostic']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Diagnostic')
            ->assertDontSee('Le service n\'est pas dans la liste ?');
        $this->assertThrows(fn () => app(TrouverOuCreerServiceAction::class)->handle($vendeur, 'Pose de verre trempé'), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame(['Diagnostic'], Service::query()->pluck('nom')->all());
    }

    public function test_un_vendeur_autorise_a_creer_des_produits_et_des_services_cree_un_service_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PRODUIT_CREATE], [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => 'Pose de verre trempé'])
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame('Pose de verre trempé', Service::query()->sole()->nom);
    }

    public function test_le_nouveau_service_tape_s_ajoute_apres_les_services_coches(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $nettoyage = Service::factory()->create(['nom' => 'Nettoyage']);
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: [
                'services' => [$diagnostic->id],
                'nouveau_service' => 'nettoyage',
            ]);

        $this->assertSame(
            ['Diagnostic', 'Nettoyage'],
            array_values(array_column($component->get('data.lignes'), 'description')),
        );
        $this->assertSame($nettoyage->id, array_values($component->get('data.lignes'))[1]['service_id']);
    }

    public function test_retaper_un_service_archive_le_repropose_a_la_caisse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->archive()->create(['nom' => 'Désoxydation']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => 'desoxydation'])
            ->assertNotified('Service « Désoxydation » de nouveau proposé à la caisse')
            ->assertNotNotified('Service « Désoxydation » ajouté à la liste');

        $this->assertTrue($service->fresh()->actif);
        $this->assertSame(1, Service::query()->count());
        $this->assertSame($service->id, $this->seuleLigne($component)['service_id']);
    }

    /**
     * Un clavier de téléphone insère facilement des espaces insécables :
     * « required » les laisse passer, le nom serait vide une fois nettoyé.
     */
    public function test_un_nom_de_service_fait_seulement_d_espaces_insecables_est_refuse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['nouveau_service' => "\u{00A0}\u{00A0}"])
            ->assertHasFormComponentActionErrors(['nouveau_service' => ['Le nom du service ne peut pas être fait seulement d\'espaces.']]);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_un_service_d_un_autre_magasin_envoye_par_la_fenetre_n_ajoute_aucune_ligne(): void
    {
        $autreProprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique'], $autreProprietaire);
        $serviceAutreMagasin = Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->ouvrirLaCaisseDUnMagasin();
        Service::factory()->create(['nom' => 'Diagnostic']);

        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$serviceAutreMagasin->id]]);

        $this->assertSame([], $component->get('data.lignes'));
    }

    public function test_effacer_le_prix_ttc_d_un_service_remet_son_total_a_zero(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $service = Service::factory()->create(['nom' => 'Diagnostic']);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '15.03')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '')
            ->assertSee('0,00 € HT + 0,00 € de TVA')
            ->assertSee('Total HT 0,00 € · TVA 0,00 € · Total TTC 0,00 €');
    }

    public function test_le_client_d_un_autre_magasin_est_refuse_a_l_encaissement(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $clientDUnAutreMagasin = Client::factory()->create();
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientDUnAutreMagasin->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
                'mode_paiement' => ModePaiement::CarteBancaire->value,
            ])
            ->call('encaisser')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un client archivé n'est plus proposé pour une vente, ni accepté par
     * une requête forgée.
     */
    public function test_un_client_archive_n_est_plus_propose_a_la_caisse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $proposable = Client::factory()->create(['nom' => 'Martin proposé']);
        Client::factory()->trashed()->create(['nom' => 'Martin archivé']);

        Livewire::test(Caisse::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getSearchResults('Martin') === [$proposable->id => 'Martin proposé']);
    }

    /**
     * Le client d'un panier archivé depuis reste affiché le temps d'en
     * choisir un autre : son crédit d'avoir aussi, annoncé sous le client
     * et déduit du reste à payer, comme pour tout client (voir
     * ClientResource::soldeCrediteurHelperText()).
     */
    public function test_le_credit_d_un_client_archive_reste_annonce_et_deduit_du_reste_a_payer(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $client = Client::factory()->create();
        $produitRendu = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $venteInitiale = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $produitRendu->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteInitiale->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client->delete();
        $component = Livewire::test(Caisse::class)->set('data.client_id', $client->id);
        $component->set('data.lignes', [['quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]]);

        $component->set('data.lignes.'.array_key_first($component->get('data.lignes')).'.produit_id', $produit->id);

        $component
            ->assertSee('Crédit disponible pour ce client : 10,00 € (sera appliqué automatiquement).')
            ->assertSeeHtml('Total 50,00 € moins 10,00 € de crédit d&#039;avoir du client.');
    }

    public function test_un_client_archive_est_refuse_a_l_encaissement(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $archive = Client::factory()->trashed()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $archive->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
                'mode_paiement' => ModePaiement::CarteBancaire->value,
            ])
            ->call('encaisser')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le client comptoir se reconnaît à son marqueur (voir
     * Client::estComptoir()) et ne s'archive jamais : il reste proposé par
     * défaut, même renommé en base et après une tentative d'archivage.
     */
    public function test_le_client_comptoir_reste_propose_par_defaut_car_il_ne_s_archive_jamais(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        Client::query()->whereKey($comptoir->id)->update(['nom' => 'Ventes anonymes']);

        $this->assertThrows(fn () => $comptoir->delete(), LogicException::class, 'Le client comptoir ne s\'archive jamais.');

        Livewire::test(Caisse::class)
            ->assertFormSet(['client_id' => $comptoir->id]);
    }

    /**
     * Une fois la journée clôturée, la caisse le dit avant même
     * d'encaisser, désactive « Encaisser », et le domaine refuse le ticket
     * ; le lendemain, elle encaisse de nouveau.
     */
    public function test_apres_la_cloture_de_la_journee_la_caisse_n_encaisse_plus_jusqu_a_minuit(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $vente = ['lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0]], 'mode_paiement' => 'especes'];
        $this->travelTo('2026-10-09 19:30:00');

        Livewire::test(Caisse::class)
            ->assertSee('La journée du 09/10/2026 est clôturée : les ventes reprennent demain.')
            ->assertSeeHtml('disabled="disabled" type="submit"')
            ->fillForm($vente)
            ->call('encaisser')
            ->assertNotified('La journée du 09/10/2026 est clôturée : les ventes reprennent demain.');

        $this->assertSame(0, Facture::query()->count());

        $this->travelTo('2026-10-10 00:01:00');

        Livewire::test(Caisse::class)
            ->assertDontSee('est clôturée')
            ->assertDontSeeHtml('disabled="disabled" type="submit"')
            ->fillForm($vente)
            ->call('encaisser');

        $this->assertSame('TK-MABOUT-2026-000001', Facture::query()->sole()->numero);
    }

    /**
     * Tant que le magasin ne peut pas vendre, sa société étant encore en
     * cours de vérification, la caisse ne s'ouvre pas, et le domaine refuse
     * le ticket, sans rien enregistrer.
     */
    public function test_la_caisse_d_un_magasin_qui_ne_peut_pas_vendre_ne_s_ouvre_pas_et_n_encaisse_pas(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true], $proprietaire);
        $this->actingAs($proprietaire);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();
        $this->assertThrows(
            fn () => app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes),
            VenteNonAutoriseeException::class,
            'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation.',
        );

        $this->assertSame([0, 0], [Facture::query()->count(), Paiement::query()->count()]);
    }

    /**
     * Une vente abandonnée avant l'encaissement s'inscrit au journal des
     * événements, avec son nombre de lignes et son total en centimes, et
     * le panier se vide ; le journal reste intègre.
     */
    public function test_abandonner_la_vente_l_inscrit_au_journal_et_vide_le_panier(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ['produit_id' => $cable->id, 'description' => $cable->nom, 'quantite' => 2, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);

        $caisse->callAction('abandonnerLaVente');

        $this->assertSame([], $caisse->get('data.lignes'));
        $this->assertSame([
            ['260', []],
            ['320', ['origine' => 'caisse', 'nombre_lignes' => 2, 'total_ttc' => 7200]],
        ], $this->journalDesEvenements($magasin));
        $this->assertSame(0, Facture::query()->count());
        $this->assertTrue(app(VerificateurIntegrite::class)->verifier($magasin)->estConforme());
    }

    /**
     * Un produit archivé pendant la vente ne s'encaisse plus, mais l'abandon
     * le compte : le journal inscrit le total des deux lignes, pas celui de
     * la seule qui reste vendable.
     */
    public function test_l_abandon_compte_une_ligne_dont_le_produit_a_ete_archive_depuis(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ['produit_id' => $cable->id, 'description' => $cable->nom, 'quantite' => 2, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);
        $cable->delete();

        $caisse->callAction('abandonnerLaVente');

        $this->assertSame([
            ['260', []],
            ['320', ['origine' => 'caisse', 'nombre_lignes' => 2, 'total_ttc' => 7200]],
        ], $this->journalDesEvenements($magasin));
        $this->assertTrue(app(VerificateurIntegrite::class)->verifier($magasin)->estConforme());
    }

    public function test_retirer_une_ligne_renseignee_du_panier_l_inscrit_au_journal(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 12.5, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 2, 'prix_unitaire_ttc' => '12.50', 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);

        $caisse->callFormComponentAction('lignes', 'delete', arguments: ['item' => array_key_first($caisse->get('data.lignes'))]);

        $this->assertSame([], $caisse->get('data.lignes'));
        $this->assertSame([
            ['260', []],
            ['323', ['origine' => 'caisse', 'produit_id' => $ecran->id, 'service_id' => null, 'description' => 'Écran iPhone 11', 'quantite' => 2, 'prix_unitaire_ttc' => 1250]],
        ], $this->journalDesEvenements($magasin));
    }

    /**
     * Une ligne encore vide n'est pas une vente commencée : la retirer
     * n'inscrit rien, et il n'y a pas de vente à abandonner.
     */
    public function test_un_panier_vide_n_inscrit_rien_au_journal(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $caisse = Livewire::test(Caisse::class)
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->assertActionHidden('abandonnerLaVente');

        $caisse->callFormComponentAction('lignes', 'delete', arguments: ['item' => array_key_first($caisse->get('data.lignes'))]);

        $this->assertSame([], $caisse->get('data.lignes'));
        $this->assertSame([], $this->journalDesEvenements($magasin));
    }

    /**
     * Si le journal des événements ne s'écrit pas, la ligne reste dans le
     * panier : aucun retrait n'échappe au journal.
     */
    public function test_sans_cle_de_signature_la_ligne_reste_dans_le_panier(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        $caisse->callFormComponentAction('lignes', 'delete', arguments: ['item' => array_key_first($caisse->get('data.lignes'))])
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertCount(1, $caisse->get('data.lignes'));
        $this->assertSame([], $this->journalDesEvenements($magasin));
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    /**
     * Le journal des événements de la caisse appartient à son magasin :
     * l'abandon d'une vente ne s'inscrit jamais dans celui d'un autre.
     */
    public function test_l_abandon_d_une_vente_ne_s_inscrit_que_dans_le_journal_du_magasin(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm(['lignes' => [['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0]]])
            ->callAction('abandonnerLaVente');

        $this->assertSame(['260', '320'], array_column($this->journalDesEvenements($magasin), 0));
        $this->assertSame([], $this->journalDesEvenements($autreMagasin));
    }

    /**
     * Le panier se garde côté serveur : recharger la page (une nouvelle
     * instance de la caisse) reprend la vente commencée au lieu de
     * l'effacer sans trace.
     */
    public function test_recharger_la_page_reprend_la_vente_commencee(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 3, 'prix_unitaire_ttc' => '120.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);

        $reprise = Livewire::test(Caisse::class);

        $reprise->assertNotified('Vente en cours reprise');
        $ligne = $this->seuleLigne($reprise);
        $this->assertSame([$ecran->id, 3, '120.00'], [$ligne['produit_id'], $ligne['quantite'], $ligne['prix_unitaire_ttc']]);
        $this->assertSame([], $this->journalDesEvenements($magasin));
    }

    /**
     * Une vente encaissée n'est plus en cours : son panier se vide avec
     * l'encaissement, sans qu'aucune ligne retirée ne s'inscrive au
     * journal, et la caisse rouverte est vide.
     */
    public function test_une_vente_encaissee_ne_se_reprend_pas(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        Livewire::test(Caisse::class)
            ->fillForm([
                'lignes' => [['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $reprise = Livewire::test(Caisse::class);

        $reprise->assertNotNotified('Vente en cours reprise');
        $this->assertSame([], $reprise->get('data.lignes'));
        $this->assertSame('TK-MABOUT-2026-000001', Facture::query()->sole()->numero);
        $this->assertSame([['260', []]], $this->journalDesEvenements($magasin));
    }

    /**
     * Remplacer le produit d'une ligne déjà renseignée retire le premier :
     * le journal l'inscrit avec sa quantité et son prix.
     */
    public function test_remplacer_le_produit_d_une_ligne_inscrit_le_produit_retire_au_journal(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'prix_vente_ttc' => 5, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 3, 'prix_unitaire_ttc' => '120.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);

        $caisse->fillForm(['lignes' => [
            ['produit_id' => $cable->id, 'description' => $cable->nom, 'quantite' => 1, 'prix_unitaire_ttc' => '5.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);

        $this->assertSame([
            ['260', []],
            ['323', ['origine' => 'caisse', 'produit_id' => $ecran->id, 'service_id' => null, 'description' => 'Écran iPhone 11', 'quantite' => 3, 'prix_unitaire_ttc' => 12000]],
        ], $this->journalDesEvenements($magasin));
    }

    /**
     * Baisser la quantité d'une ligne retire des articles : le journal les
     * inscrit une fois la nouvelle quantité saisie, et une quantité en
     * cours de saisie (champ vidé) ne retire encore rien.
     */
    public function test_baisser_la_quantite_d_une_ligne_inscrit_les_articles_retires_au_journal(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)->fillForm(['lignes' => [
            ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 3, 'prix_unitaire_ttc' => '120.00', 'taux_tva' => 20, 'remise_pourcentage' => 0],
        ]]);
        $quantite = 'data.lignes.'.array_key_first($caisse->get('data.lignes')).'.quantite';
        $caisse->set($quantite, '');
        $this->assertSame([], $this->journalDesEvenements($magasin));

        $caisse->set($quantite, '1');

        $this->assertSame([
            ['260', []],
            ['323', ['origine' => 'caisse', 'produit_id' => $ecran->id, 'service_id' => null, 'description' => 'Écran iPhone 11', 'quantite' => 2, 'prix_unitaire_ttc' => 12000]],
        ], $this->journalDesEvenements($magasin));
    }

    /**
     * « Encaisser » désactivé ne suffit pas : un appel direct à encaisser()
     * après la clôture de la journée est refusé, même pour une vente à un
     * professionnel.
     */
    public function test_apres_la_cloture_un_appel_direct_a_encaisser_est_refuse_meme_pour_un_professionnel(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());
        $professionnel = Client::factory()->professionnel()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->travelTo('2026-10-09 19:30:00');

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $professionnel->id,
                'lignes' => [['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 120, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser')
            ->assertNotified('La journée du 09/10/2026 est clôturée : les ventes reprennent demain.');

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Une vente à un professionnel est une facture : tant que l'identité
     * légale de la société est incomplète (sans capital ni immatriculation
     * après son inscription), la caisse le dit dès le choix du client, et
     * refuse l'encaissement sans rien enregistrer, ni facture ni paiement
     * (B11). Un particulier n'est pas concerné.
     */
    public function test_une_vente_a_un_professionnel_est_refusee_tant_que_l_identite_de_la_societe_est_incomplete(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $professionnel = Client::factory()->professionnel()->create();
        $particulier = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $refus = 'Facture impossible : identité de la société incomplète (capital social, immatriculation).';

        Livewire::test(Caisse::class)
            ->fillForm(['client_id' => $particulier->id])
            ->assertDontSee($refus, escape: true)
            ->fillForm([
                'client_id' => $professionnel->id,
                'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => '12.00', 'remise_pourcentage' => 0]],
                'mode_paiement' => 'carte_bancaire',
            ])
            ->assertSee($refus, escape: true)
            ->call('encaisser')
            ->assertNotified('Pour émettre une facture à un professionnel, complétez d\'abord l\'identité de la société (Administration de la société > Identité) : capital social, immatriculation.');

        $this->assertSame([0, 0], [Facture::query()->count(), Paiement::query()->count()]);
    }

    /**
     * B8 : choisir un client présélectionne son mode de paiement préféré,
     * et les espèces pour un client qui n'en a pas.
     */
    public function test_choisir_un_client_preselectionne_son_mode_de_paiement_prefere(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $carte = Client::factory()->create(['mode_paiement_prefere' => ModePaiement::CarteBancaire]);
        $sansPreference = Client::factory()->create(['mode_paiement_prefere' => null]);

        Livewire::test(Caisse::class)
            ->assertFormSet(['mode_paiement' => ModePaiement::Especes->value])
            ->fillForm(['client_id' => $carte->id])
            ->assertFormSet(['mode_paiement' => ModePaiement::CarteBancaire->value])
            ->fillForm(['client_id' => $sansPreference->id])
            ->assertFormSet(['mode_paiement' => ModePaiement::Especes->value]);
    }

    /**
     * Le formulaire vide de la caisse part du mode de paiement préféré du
     * client comptoir.
     */
    public function test_la_caisse_s_ouvre_sur_le_mode_de_paiement_prefere_du_client_comptoir(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        Client::query()->where('est_comptoir', true)->sole()->update(['mode_paiement_prefere' => ModePaiement::CarteBancaire]);

        Livewire::test(Caisse::class)
            ->assertFormSet(['mode_paiement' => ModePaiement::CarteBancaire->value]);
    }

    /**
     * K6 : le ticket comptoir de 12,00 € repris par l'avoir
     * AV-MABOUT-2026-000001 ; à la vente comptoir suivante, de 20,00 €, le
     * n° de cet avoir, saisi tel que le client le présente, règle 12,00 €,
     * et le reste, 8,00 €, se paie en espèces. Le ticket signe le bon
     * imputé, et l'avoir est épuisé.
     */
    public function test_le_bon_d_avoir_d_un_ticket_comptoir_regle_une_partie_de_la_vente_comptoir_suivante(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $bon = $this->bonDAvoirDeDouzeEuros();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0]], 'mode_paiement' => 'especes']);

        $component
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => ' av-mabout-2026-000001 '])
            ->assertHasNoFormComponentActionErrors()
            ->assertSeeHtml('Total 20,00 € moins 12,00 € du bon d&#039;avoir AV-MABOUT-2026-000001.')
            ->set('data.montant_recu', '10')
            ->assertFormFieldExists('montant_recu', fn (TextInput $champ): bool => $champ->getMinValue() === 8.0)
            ->call('encaisser')
            ->assertNotified('Vente encaissée : facture TK-MABOUT-2026-000002');

        $ticket = Facture::query()->where('numero', 'TK-MABOUT-2026-000002')->sole();
        $this->assertSame([StatutFacture::Payee, 12.0, 8.0, 0.0], [$ticket->statut, $ticket->montantCrediteParAvoir(), $ticket->montantPaye(), $ticket->montantRestant()]);
        $this->assertSame([[ModePaiement::Especes, '8.00']], $ticket->allocationsPaiement->map(fn ($allocation): array => [$allocation->paiement->mode_paiement, $allocation->montant_alloue])->all());
        $this->assertSame(0.0, $bon->fresh()->montantNonAlloue());
        $this->assertSame([['avoir_id' => $bon->id, 'numero' => 'AV-MABOUT-2026-000001', 'montant' => 1200]], $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['credits_avoir']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_un_bon_d_avoir_deja_utilise_est_refuse(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $bon = $this->bonDAvoirDeDouzeEuros();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes, bonDAvoir: $bon->numero);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001'])
            ->assertHasFormComponentActionErrors(['numero' => ['Ce bon d\'avoir est introuvable ou déjà utilisé.']])
            ->assertFormSet(['bon_d_avoir' => null]);
    }

    /**
     * Le crédit de l'avoir d'un client nommé lui appartient : son numéro
     * ne vaut pas bon d'avoir au comptoir.
     */
    public function test_l_avoir_d_un_client_nomme_ne_vaut_pas_bon_d_avoir(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $vente = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $martin, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $vente->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->assertSame(12.0, $martin->soldeCrediteur());

        Livewire::test(Caisse::class)
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001'])
            ->assertHasFormComponentActionErrors(['numero' => ['Ce bon d\'avoir est introuvable ou déjà utilisé.']]);
    }

    public function test_le_bon_d_avoir_d_un_autre_magasin_est_introuvable(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $bonDeLAutreMagasin = app(TenantContext::class)->runAs($autreMagasin, fn (): string => $this->bonDAvoirDeDouzeEuros()->numero);
        $this->ouvrirLaCaisseDUnMagasin();

        Livewire::test(Caisse::class)
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => $bonDeLAutreMagasin])
            ->assertHasFormComponentActionErrors(['numero' => ['Ce bon d\'avoir est introuvable ou déjà utilisé.']]);
    }

    public function test_retirer_le_bon_d_avoir_rend_tout_le_total_a_payer(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $this->bonDAvoirDeDouzeEuros();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0]]])
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001']);

        $component->callFormComponentAction('total_a_payer', 'retirerLeBonDAvoir');

        $component
            ->assertFormSet(['bon_d_avoir' => null])
            ->assertDontSeeHtml('du bon d&#039;avoir')
            ->assertFormFieldExists('montant_recu', fn (TextInput $champ): bool => $champ->getMinValue() === 20.0)
            ->assertFormComponentActionVisible('total_a_payer', 'utiliserUnBonDAvoir');
    }

    /**
     * Un bon saisi puis utilisé par une autre caisse : la caisse le dit
     * sous le total à payer, et l'encaissement le refuse sans rien
     * enregistrer.
     */
    public function test_un_bon_d_avoir_utilise_entre_temps_par_une_autre_caisse_est_refuse_a_l_encaissement(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $bon = $this->bonDAvoirDeDouzeEuros();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0]], 'mode_paiement' => 'especes'])
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001']);
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::CarteBancaire, bonDAvoir: $bon->numero);

        $component
            ->set('data.reference', null)
            ->assertSee('Ce bon d\'avoir est introuvable ou déjà utilisé.')
            ->call('encaisser')
            ->assertNotified('Ce bon d\'avoir est introuvable ou déjà utilisé.');

        $this->assertSame([2, 2], [Facture::query()->count(), Paiement::query()->count()]);
    }

    /**
     * K6 : le bon d'avoir règle aussi la vente d'un client nommé. Mme
     * Martin a 10,00 € de crédit d'avoir à elle et présente le bon de
     * 12,00 € pour un achat de 15,00 € : son crédit s'impute d'abord,
     * 10,00 €, puis le bon sur les 5,00 € qui restent ; le ticket, à son
     * nom, est réglé sans encaissement, et le bon garde 7,00 €.
     */
    public function test_le_bon_d_avoir_regle_la_vente_d_un_client_nomme_apres_son_propre_credit(): void
    {
        $magasin = $this->ouvrirLaCaisseDUnMagasin();
        $bon = $this->bonDAvoirDeDouzeEuros();
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $achatRendu = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $martin, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $avoirDeMartin = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $produit = Produit::factory()->create(['prix_vente_ttc' => 15, 'taux_tva' => 20, 'suivi_stock' => false]);
        $component = Livewire::test(Caisse::class)
            ->fillForm(['client_id' => $martin->id, 'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'remise_pourcentage' => 0]], 'mode_paiement' => 'especes'])
            ->assertFormComponentActionVisible('total_a_payer', 'utiliserUnBonDAvoir')
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001'])
            ->assertHasNoFormComponentActionErrors()
            ->assertSeeHtml('Total 15,00 € moins 10,00 € de crédit d&#039;avoir du client et 5,00 € du bon d&#039;avoir AV-MABOUT-2026-000001.')
            ->assertFormFieldExists('montant_recu', fn (TextInput $champ): bool => $champ->getMinValue() === 0.0);

        $component
            ->call('encaisser')
            ->assertNotified(Notification::make()->title('Vente encaissée : facture TK-MABOUT-2026-000003')->body('Réglée intégralement par avoir (15,00 €, AV-MABOUT-2026-000002, AV-MABOUT-2026-000001).')->success());

        $ticket = Facture::query()->where('numero', 'TK-MABOUT-2026-000003')->sole();
        $this->assertSame([$martin->id, 'Mme Martin'], [(int) $ticket->client_id, $ticket->client_nom]);
        $this->assertSame([StatutFacture::Payee, 15.0, 0.0, 0.0], [$ticket->statut, $ticket->montantCrediteParAvoir(), $ticket->montantPaye(), $ticket->montantRestant()]);
        $this->assertSame([7.0, 0.0], [$bon->fresh()->montantNonAlloue(), $martin->soldeCrediteur()]);
        $this->assertSame(2, Paiement::query()->count());
        $this->assertSame(
            [['avoir_id' => $avoirDeMartin->id, 'numero' => 'AV-MABOUT-2026-000002', 'montant' => 1000], ['avoir_id' => $bon->id, 'numero' => 'AV-MABOUT-2026-000001', 'montant' => 500]],
            $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['credits_avoir'],
        );
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Le bon d'avoir saisi règle la vente de tout client : choisir un
     * autre client le garde.
     */
    public function test_changer_de_client_garde_le_bon_d_avoir_saisi(): void
    {
        $this->ouvrirLaCaisseDUnMagasin();
        $this->bonDAvoirDeDouzeEuros();
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $component = Livewire::test(Caisse::class)
            ->callFormComponentAction('total_a_payer', 'utiliserUnBonDAvoir', data: ['numero' => 'AV-MABOUT-2026-000001']);

        $component->fillForm(['client_id' => $martin->id]);

        $component->assertFormSet(['bon_d_avoir' => 'AV-MABOUT-2026-000001']);
    }

    /**
     * Le propriétaire décoche cette autorisation du vendeur dans le
     * magasin (voir ModifierMembreAction).
     */
    private function retirerUneCase(Magasin $magasin, User $vendeur, string $autorisation): void
    {
        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, [
            $magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [$autorisation]))],
        ]);
    }

    private function ouvrirLaCaisseDUnMagasin(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Un ticket comptoir de 12,00 € repris en entier par un avoir dans le
     * magasin actif : son numéro vaut bon d'avoir de 12,00 €.
     */
    private function bonDAvoirDeDouzeEuros(): Avoir
    {
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
    }

    /**
     * @return array<string, mixed>
     */
    private function seuleLigne(Testable $component): array
    {
        $lignes = $component->get('data.lignes');
        $this->assertCount(1, $lignes);

        return array_values($lignes)[0];
    }

    /**
     * Le journal des événements du magasin, dans l'ordre de sa chaîne : le
     * code et les détails de chaque événement.
     *
     * @return list<array{0: string, 1: array<array-key, mixed>}>
     */
    private function journalDesEvenements(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => [$evenement->type, $evenement->contenuSigne()['donnees']['details']])
            ->all());
    }
}
