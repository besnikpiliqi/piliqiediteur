<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Tests\TestCase;

class PaiementResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée une facture émise (total TTC 120, via un produit à 100 € HT et
     * 20 % de TVA) depuis le panneau, pour servir de base aux tests
     * d'encaissement.
     */
    private function emettreFactureDepuisLePanneau(Client $client, float $prixUnitaireHt = 100): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_enregistrer_un_paiement_complet_depuis_le_panneau_passe_la_facture_a_payee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Paiement::query()->count());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    /**
     * L'horloge du serveur a reculé depuis l'émission de la facture : le
     * registre fiscal refuse le paiement. Le formulaire reste ouvert avec
     * la raison au lieu d'une erreur serveur, l'incident est signalé au
     * journal, et rien n'est enregistré.
     */
    public function test_une_horloge_qui_recule_refuse_le_paiement_avec_un_message(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:05', 'UTC'));
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:04', 'UTC'));
        Exceptions::fake([HorlogeRegressiveException::class]);

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => '2026-10-09',
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertNotified("L'horloge du serveur a reculé : il est 09/10/2026 08:00:04 UTC, mais le registre fiscal du magasin n°{$magasin->id} a déjà été verrouillé à 09/10/2026 08:00:05 UTC. Aucune opération fiscale n'est enregistrée tant que l'horloge n'est pas corrigée.");

        Exceptions::assertReported(HorlogeRegressiveException::class);
        $this->assertSame([0, StatutFacture::Emise], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    /**
     * Le navigateur envoie les identifiants choisis sous forme de chaînes
     * ("5") : le paiement est enregistré pour ce client et règle sa
     * facture, au lieu d'être refusé comme s'il visait la facture d'un
     * autre client.
     */
    public function test_un_paiement_aux_identifiants_envoyes_en_chaines_regle_la_facture_du_client(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => (string) $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => '120',
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => (string) $facture->id, 'montant' => '120'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotNotified();

        $this->assertSame($client->id, Paiement::query()->sole()->client_id);
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_le_client_d_un_autre_magasin_est_refuse(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $clientDUnAutreMagasin = Client::factory()->create();
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $clientDUnAutreMagasin->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_repartir_un_paiement_sur_plusieurs_factures_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client, 100); // total 120
        $factureB = $this->emettreFactureDepuisLePanneau($client, 50 / 1.2); // total 50

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 170,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $factureA->id, 'montant' => 120],
                    ['facture_id' => $factureB->id, 'montant' => 50],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(StatutFacture::Payee, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::Payee, $factureB->fresh()->statut);
    }

    public function test_laction_rapide_encaisser_depuis_la_liste_des_factures(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::CarteBancaire->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
            ]);

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame(1, Paiement::query()->count());
    }

    public function test_laction_rapide_encaisser_accepte_un_montant_partiel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 50,
                'recu_le' => now()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatutFacture::PartiellementPayee, $facture->fresh()->statut);
        $this->assertSame(70.0, $facture->fresh()->montantRestant());
    }

    /**
     * Le client a explicitement refusé qu'un trop-perçu devienne un crédit
     * (contrairement à un avoir) : la saisie d'un montant supérieur au
     * restant dû doit être bloquée proprement par la validation du
     * formulaire, sans créer de paiement ni faire planter la page.
     */
    public function test_laction_rapide_encaisser_bloque_un_montant_superieur_au_restant_du(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 150,
                'recu_le' => now()->toDateString(),
            ])
            ->assertHasTableActionErrors(['montant']);

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Un virement vu hier sur le relevé s'enregistre aujourd'hui avec sa
     * vraie date de réception.
     */
    public function test_laction_rapide_encaisser_accepte_une_date_de_reception_passee(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => '2026-10-08',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-10-08', Paiement::query()->sole()->recu_le->toDateString());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    /**
     * Aucun paiement ne se reçoit dans le futur.
     */
    public function test_laction_rapide_encaisser_refuse_une_date_de_reception_future(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 120,
                'recu_le' => '2026-10-10',
            ])
            ->assertHasTableActionErrors(['recu_le' => 'before_or_equal'])
            ->assertSee('Un paiement ne peut pas être reçu à une date future.');

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * La référence tient en 100 caractères, comme sa colonne : au-delà, la
     * saisie est refusée au lieu d'échouer en base.
     */
    public function test_laction_rapide_encaisser_refuse_une_reference_de_plus_de_100_caracteres(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::CarteBancaire->value,
                'montant' => 120,
                'reference' => str_repeat('A', 101),
                'recu_le' => now()->toDateString(),
            ])
            ->assertHasTableActionErrors(['reference' => 'max'])
            ->assertSee('Le champ référence ne doit pas dépasser 100 caractères.');

        $this->assertSame(0, Paiement::query()->count());
    }

    /**
     * Même garde-fou que l'action rapide "Encaisser" (voir
     * test_laction_rapide_encaisser_bloque_un_montant_superieur_au_restant_du),
     * appliqué cette fois au formulaire multi-factures de PaiementResource :
     * une allocation ne doit jamais pouvoir dépasser le restant dû de la
     * facture choisie.
     */
    public function test_impossible_dallouer_plus_que_le_restant_du_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 150,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 150],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['allocations.0.montant']);

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Mêmes règles de date que l'action rapide « Encaisser » (voir
     * test_laction_rapide_encaisser_accepte_une_date_de_reception_passee).
     */
    public function test_le_formulaire_des_paiements_accepte_une_date_de_reception_passee(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => '2026-10-08',
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-10-08', Paiement::query()->sole()->recu_le->toDateString());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_le_formulaire_des_paiements_refuse_une_date_de_reception_future(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => '2026-10-10',
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['recu_le' => 'before_or_equal'])
            ->assertSee('Un paiement ne peut pas être reçu à une date future.');

        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_le_formulaire_des_paiements_refuse_une_reference_de_plus_de_100_caracteres(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Cheque->value,
                'montant' => 120,
                'reference' => str_repeat('A', 101),
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['reference' => 'max'])
            ->assertSee('Le champ référence ne doit pas dépasser 100 caractères.');

        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_paiements(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $autorisations = [
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::PAIEMENT_CREER,
        ];
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);

        $client = Client::factory()->create();

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        $paiementA = Paiement::create([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 10,
            'recu_le' => now()->toDateString(),
        ]);

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        $paiementB = Paiement::create([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 20,
            'recu_le' => now()->toDateString(),
        ]);

        Livewire::test(ListPaiements::class)
            ->assertCanSeeTableRecords([$paiementB])
            ->assertCanNotSeeTableRecords([$paiementA]);
    }

    /**
     * B16 : un vendeur n'encaisse que ses factures. Le client doit
     * 120,00 € au vendeur A et 50,00 € au vendeur B : B ne se voit proposer
     * que la sienne.
     */
    public function test_un_vendeur_ne_se_voit_proposer_que_ses_factures(): void
    {
        ['vendeurB' => $vendeurB, 'client' => $client, 'factureDeB' => $factureDeB] = $this->clientQuiDoitAuxDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::test(CreatePaiement::class)->fillForm(['client_id' => $client->id, 'allocations' => [['facture_id' => null]]]);

        $formulaire->assertFormFieldExists('allocations.0.facture_id', fn (Select $champ): bool => collect($champ->getOptions())->all() === [
            $factureDeB->id => "{$factureDeB->numero} — restant : 50,00 €",
        ]);
    }

    public function test_le_proprietaire_se_voit_proposer_les_factures_de_toute_l_equipe(): void
    {
        ['magasin' => $magasin, 'client' => $client, 'factureDeA' => $factureDeA, 'factureDeB' => $factureDeB] = $this->clientQuiDoitAuxDeuxVendeurs();
        $this->actingAs($this->proprietaireDe($magasin));

        $formulaire = Livewire::test(CreatePaiement::class)->fillForm(['client_id' => $client->id, 'allocations' => [['facture_id' => null]]]);

        $formulaire->assertFormFieldExists('allocations.0.facture_id', fn (Select $champ): bool => collect($champ->getOptions())->keys()->sort()->values()->all() === [$factureDeA->id, $factureDeB->id]);
    }

    /**
     * La facture du vendeur A, forgée dans le formulaire du vendeur B hors
     * des choix proposés, est refusée (403) : aucun paiement, et elle
     * reste due.
     */
    public function test_la_facture_d_un_collegue_forgee_est_refusee_sans_rien_enregistrer(): void
    {
        ['vendeurB' => $vendeurB, 'client' => $client, 'factureDeA' => $factureDeA] = $this->clientQuiDoitAuxDeuxVendeurs();
        $this->actingAs($vendeurB);

        $formulaire = Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
                'allocations' => [['facture_id' => $factureDeA->id, 'montant' => 120]],
            ])
            ->call('create');

        $formulaire->assertForbidden();
        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame([StatutFacture::Emise, 120.0], [$factureDeA->fresh()->statut, $factureDeA->fresh()->montantRestant()]);
    }

    /**
     * Le client doit 120,00 € sur le ticket du vendeur A et 50,00 € sur
     * celui du vendeur B, deux vendeurs aux autorisations de leur rôle.
     *
     * @return array{magasin: Magasin, vendeurB: User, client: Client, factureDeA: Facture, factureDeB: Facture}
     */
    private function clientQuiDoitAuxDeuxVendeurs(): array
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur);
        $client = Client::factory()->create();
        $factureDeA = $this->emettreUnTicketEnTantQue($vendeurA, $client, 120);
        $factureDeB = $this->emettreUnTicketEnTantQue($vendeurB, $client, 50);
        Filament::setTenant($magasin);

        return ['magasin' => $magasin, 'vendeurB' => $vendeurB, 'client' => $client, 'factureDeA' => $factureDeA, 'factureDeB' => $factureDeB];
    }

    private function emettreUnTicketEnTantQue(User $vendeur, Client $client, float $prixTtc): Facture
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * Un paiement garde son client, même archivé depuis (voir
     * Paiement::client()) ; ce client, qui ne doit plus rien, n'est plus
     * proposé pour un nouveau paiement.
     */
    public function test_le_client_archive_reste_affiche_sur_ses_paiements_sans_etre_propose(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Jeanne Archivée']);
        $paiement = Paiement::create([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 10,
            'recu_le' => now()->toDateString(),
        ]);
        $client->delete();

        Livewire::test(ListPaiements::class)
            ->assertTableColumnStateSet('client.nom', 'Jeanne Archivée', $paiement);
        Livewire::test(CreatePaiement::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => ! array_key_exists($client->id, $champ->getOptions()));
    }

    /**
     * Archivé, un client n'est plus proposé pour une vente, mais il règle
     * toujours ce qu'il doit : deux factures en un seul virement, le
     * montant du paiement égal au total imputé (voir
     * Client::scopeQuiPeuventRegler()).
     */
    public function test_un_client_archive_regle_deux_factures_en_un_seul_paiement(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['nom' => 'Société Archivée']);
        $factureA = $this->emettreFactureDepuisLePanneau($client, 100);
        $factureB = $this->emettreFactureDepuisLePanneau($client, 50 / 1.2);
        $client->delete();

        Livewire::test(CreatePaiement::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => ($champ->getOptions()[$client->id] ?? null) === 'Société Archivée')
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 170,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $factureA->id, 'montant' => 120],
                    ['facture_id' => $factureB->id, 'montant' => 50],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $paiement = Paiement::query()->sole();
        $this->assertSame([$client->id, '170.00'], [$paiement->client_id, $paiement->montant]);
        $this->assertSame([StatutFacture::Payee, 0.0], [$factureA->fresh()->statut, $factureA->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::Payee, 0.0], [$factureB->fresh()->statut, $factureB->fresh()->montantRestant()]);
    }

    /**
     * Un client archivé qui ne doit plus rien n'est pas accepté, même par
     * une requête forgée.
     */
    public function test_un_client_archive_qui_ne_doit_plus_rien_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client, 100);
        Livewire::test(ListFactures::class)->callTableAction('encaisser', $facture, data: [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ]);
        $client->delete();

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(['120.00'], Paiement::query()->pluck('montant')->all());
    }

    /**
     * B3 : le formulaire refuse un montant reçu différent du total imputé
     * sur les factures, avec le message du domaine.
     */
    public function test_le_formulaire_refuse_un_montant_recu_different_du_total_impute(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 200,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['montant'])
            ->assertSee('Le montant reçu (200,00 €) doit être égal au total imputé sur les factures (120,00 €).');

        $this->assertSame([0, StatutFacture::Emise], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    public function test_le_reste_a_imputer_suit_la_saisie(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'montant' => 150,
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->assertSee('30,00 €');
    }

    /**
     * B8 : le mode de paiement préféré du client choisi est présélectionné.
     */
    public function test_le_mode_de_paiement_prefere_du_client_est_preselectionne(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['mode_paiement_prefere' => ModePaiement::Cheque]);

        Livewire::test(CreatePaiement::class)
            ->fillForm(['client_id' => $client->id])
            ->assertFormSet(['mode_paiement' => ModePaiement::Cheque->value]);
    }

    public function test_contre_passer_un_paiement_depuis_la_liste_rouvre_sa_facture(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120
        Livewire::test(ListFactures::class)->callTableAction('encaisser', $facture, data: [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ]);
        $errone = Paiement::query()->sole();

        Livewire::test(ListPaiements::class)
            ->callTableAction('contre_passer', $errone, data: ['motif' => 'Saisi sur le mauvais client'])
            ->assertHasNoTableActionErrors();

        $contrePassation = Paiement::query()->whereKeyNot($errone->id)->sole();
        $this->assertSame([$errone->id, '-120.00', 'Saisi sur le mauvais client'], [$contrePassation->contre_passe_paiement_id, $contrePassation->montant, $contrePassation->notes]);
        $this->assertSame([StatutFacture::Emise, 120.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
        Livewire::test(ListPaiements::class)
            ->assertTableColumnStateSet('correction', 'Contre-passé', $errone->getKey())
            ->assertTableColumnStateSet('correction', 'Contre-passation', $contrePassation->getKey())
            ->assertTableActionHidden('contre_passer', $errone->getKey())
            ->assertTableActionHidden('contre_passer', $contrePassation->getKey());
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSee('Contre-passation')
            ->assertSee("-120,00\u{00A0}€");
    }

    public function test_contre_passer_sans_motif_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120
        Livewire::test(ListFactures::class)->callTableAction('encaisser', $facture, data: [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 120,
            'recu_le' => now()->toDateString(),
        ]);

        Livewire::test(ListPaiements::class)
            ->callTableAction('contre_passer', Paiement::query()->sole(), data: ['motif' => ''])
            ->assertHasTableActionErrors(['motif' => 'required']);

        $this->assertSame([1, StatutFacture::Payee], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    /**
     * B8 : un client sans mode de paiement préféré ne change pas le mode
     * déjà choisi.
     */
    public function test_choisir_un_client_sans_mode_prefere_garde_le_mode_deja_choisi(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['mode_paiement_prefere' => null]);

        Livewire::test(CreatePaiement::class)
            ->fillForm(['mode_paiement' => ModePaiement::Virement->value])
            ->fillForm(['client_id' => $client->id])
            ->assertFormSet(['mode_paiement' => ModePaiement::Virement->value]);
    }

    /**
     * Un client qui a 36,00 € de crédit d'avoir (un achat de 36,00 €
     * payé puis rendu) et des factures à régler, de la plus ancienne à la
     * plus récente : un virement les réglait quand l'avoir a été émis, qui
     * n'a donc rien eu à y imputer (B6), puis la contre-passation de ce
     * virement, rejeté, les a rouvertes.
     *
     * @param  list<float>  $prixDesFactures
     * @return array{client: Client, factures: list<Facture>}
     */
    private function clientAvecDuCreditEtDesFacturesDues(array $prixDesFactures): array
    {
        $client = Client::factory()->create();
        $achatRendu = app(EnregistrerVenteCaisseAction::class)->handle($client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 36, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $factures = array_map(fn (float $prix): Facture => app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prix, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
        ])), $prixDesFactures);
        $virementRejete = app(EnregistrerPaiementAction::class)->handle(
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => array_sum($prixDesFactures), 'recu_le' => today()->toDateString()],
            array_map(fn (Facture $facture): array => ['facture_id' => $facture->id, 'montant' => $facture->total_ttc], $factures),
        );
        app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle($virementRejete, 'Virement rejeté');
        $this->assertSame(36.0, $client->soldeCrediteur());

        return ['client' => $client, 'factures' => $factures];
    }

    /**
     * K9 : le crédit ne solde que ce que le montant reçu laisse dû. «
     * Encaisser » propose donc le reste dû moins ce crédit, 84,00 €, et le
     * dit ; validé tel quel, il règle la facture avec 84,00 € reçus et
     * 36,00 € de crédit.
     */
    public function test_encaisser_propose_le_reste_du_moins_le_credit_qui_solde_la_facture(): void
    {
        $this->connecterLeProprietaire();
        ['client' => $client, 'factures' => [$facture]] = $this->clientAvecDuCreditEtDesFacturesDues([120]);

        Livewire::test(ListFactures::class)
            ->mountTableAction('encaisser', $facture)
            ->assertTableActionDataSet(['montant' => 84.0])
            ->assertSee('Crédit disponible pour ce client : 36,00 €. Il soldera ce que le montant reçu laisse dû : 84,00 € suffisent pour régler la facture.')
            ->setTableActionData(['mode_paiement' => ModePaiement::Especes->value])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, 84.0, 36.0, 0.0], [$facture->statut, $facture->montantPaye(), $facture->montantCrediteParAvoir(), $facture->montantRestant()]);
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * Quand le crédit couvre tout ce qui reste dû, « Encaisser » ne
     * propose aucun montant et le dit.
     */
    public function test_encaisser_ne_propose_aucun_montant_quand_le_credit_couvre_tout_le_reste_du(): void
    {
        $this->connecterLeProprietaire();
        ['factures' => [$facture]] = $this->clientAvecDuCreditEtDesFacturesDues([30]);

        Livewire::test(ListFactures::class)
            ->mountTableAction('encaisser', $facture)
            ->assertTableActionDataSet(['montant' => null])
            ->assertSee('Crédit disponible pour ce client : 36,00 €. Il soldera ce que le montant reçu laisse dû, et couvre déjà tout ce qui reste dû.');
    }

    /**
     * Dans le formulaire des paiements, le choix d'une facture propose son
     * reste dû moins le crédit encore libre : 84,00 € pour la première ;
     * ce qu'elle laisse au crédit l'épuise, et la seconde est proposée en
     * entier. Le paiement de 204,00 € règle les deux, le crédit compris.
     */
    public function test_choisir_une_facture_propose_son_reste_du_moins_le_credit_encore_libre(): void
    {
        $this->connecterLeProprietaire();
        ['client' => $client, 'factures' => [$premiere, $seconde]] = $this->clientAvecDuCreditEtDesFacturesDues([120, 120]);
        $formulaire = Livewire::test(CreatePaiement::class)
            ->fillForm(['client_id' => $client->id, 'mode_paiement' => ModePaiement::Virement->value, 'montant' => 204, 'recu_le' => now()->toDateString()])
            ->assertSee('Crédit disponible pour ce client : 36,00 €. Il soldera ce que les montants imputés laissent dû sur les factures choisies, de la plus ancienne à la plus récente.');

        $formulaire
            ->fillForm(['allocations' => [['facture_id' => $premiere->id]]])
            ->assertFormSet(['allocations.0.montant' => 84.0])
            ->fillForm(['allocations' => [['facture_id' => $premiere->id, 'montant' => 84.0], ['facture_id' => $seconde->id]]])
            ->assertFormSet(['allocations.1.montant' => 120.0])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([StatutFacture::Payee, 84.0, 36.0], [$premiere->fresh()->statut, $premiere->fresh()->montantPaye(), $premiere->fresh()->montantCrediteParAvoir()]);
        $this->assertSame([StatutFacture::Payee, 120.0, 0.0], [$seconde->fresh()->statut, $seconde->fresh()->montantPaye(), $seconde->fresh()->montantCrediteParAvoir()]);
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    /**
     * Un montant reçu hors limites ne casse pas la page pendant la saisie
     * (le reste à imputer ne s'en déduit pas) : l'enregistrement est
     * refusé par le message de la limite.
     */
    public function test_un_montant_recu_hors_limites_est_refuse_par_un_message(): void
    {
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => '10000000000000',
                'recu_le' => now()->toDateString(),
                'allocations' => [['facture_id' => $facture->id, 'montant' => '10000000000000']],
            ])
            ->call('create')
            ->assertHasFormErrors(['montant' => 'max', 'allocations.0.montant' => 'max']);

        $this->assertSame([0, StatutFacture::Emise], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
