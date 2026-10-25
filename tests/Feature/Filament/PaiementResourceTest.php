<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaiementResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * « Encaisser » depuis la liste des factures, en une seule part : la
     * première part, que la fenêtre propose, prend ce mode, ce montant et
     * cette référence.
     *
     * @param  array{mode: string, montant: int|float|string, reference?: string}  $part
     */
    private function encaisserDepuisLaListe(Facture $facture, array $part, string $recuLe): Testable
    {
        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $facture);
        $cle = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        return $liste
            ->setTableActionData(array_merge(
                ["parts.{$cle}.montant_saisi" => true],
                collect($part)->mapWithKeys(fn (mixed $valeur, string $champ): array => ["parts.{$cle}.{$champ}" => $valeur])->all(),
                ['recu_le' => $recuLe],
            ))
            ->callMountedTableAction();
    }

    /**
     * Crée une facture émise (total TTC 120, via un produit à 120 € TTC et
     * 20 % de TVA) depuis le panneau, pour servir de base aux tests
     * d'encaissement.
     */
    private function emettreFactureDepuisLePanneau(Client $client, float $prixUnitaireTtc = 120): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixUnitaireTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => $prixUnitaireTtc, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_enregistrer_un_paiement_complet_depuis_le_panneau_passe_la_facture_a_payee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $this->travelTo(self::enUtc('2026-10-09 08:00:05'));
        $facture = $this->emettreFactureDepuisLePanneau($client);
        $this->travelTo(self::enUtc('2026-10-09 08:00:04'));
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $clientDUnAutreMagasin = Client::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client, 120); // total 120
        $factureB = $this->emettreFactureDepuisLePanneau($client, 50); // total 50

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::CarteBancaire->value, 'montant' => 120], now()->toDateString());

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame(1, Paiement::query()->count());
    }

    public function test_laction_rapide_encaisser_accepte_un_montant_partiel(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 50], now()->toDateString())
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 150], now()->toDateString())
            ->assertHasTableActionErrors(['parts'])
            ->assertSee('Les parts du règlement (150,00 €) dépassent ce qui reste dû (120,00 €).');

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Un virement vu hier sur le relevé s'enregistre aujourd'hui avec sa
     * vraie date de réception.
     */
    public function test_laction_rapide_encaisser_accepte_une_date_de_reception_passee(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Virement->value, 'montant' => 120], '2026-10-08')
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-10-08', Paiement::query()->sole()->recu_le->toDateString());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    /**
     * Aucun paiement ne se reçoit dans le futur.
     */
    public function test_laction_rapide_encaisser_refuse_une_date_de_reception_future(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->connecterLeProprietaire();
        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 120], '2026-10-10')
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

        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $facture);
        $part = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        $liste
            ->setTableActionData(["parts.{$part}.mode" => ModePaiement::CarteBancaire->value, "parts.{$part}.reference" => str_repeat('A', 101)])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(["parts.{$part}.reference" => 'max'])
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autorisations = [
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::PAIEMENT_CREER,
        ];
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);

        $client = Client::factory()->create();
        $paiementA = $this->paiementEnregistrePar($vendeurA, $client, 10);
        $paiementB = $this->paiementEnregistrePar($vendeurB, $client, 20);

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
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

    /**
     * Sans « Factures : voir (les siennes) », un membre ne se voit proposer
     * aucune facture, pas même son propre ticket de 12,00 € (créé par lui,
     * émis par un responsable), dont il ne peut pas lire le reste dû (voir
     * FacturePolicy::view()).
     */
    public function test_sans_voir_ses_factures_aucune_facture_n_est_proposee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(
            RoleMagasin::Vendeur->permissionsParDefaut(),
            [PermissionRegistry::FACTURE_VIEW],
        )));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle($membre, $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        app(EmettreFactureAction::class)->handle($this->responsableDeTest($magasin), $brouillon);
        $this->actingAs($membre);
        Filament::setTenant($magasin);

        $formulaire = Livewire::test(CreatePaiement::class)->fillForm(['client_id' => $client->id, 'allocations' => [['facture_id' => null]]]);

        $formulaire->assertFormFieldExists('allocations.0.facture_id', fn (Select $champ): bool => collect($champ->getOptions())->isEmpty());
    }

    /**
     * Qui voit les factures de toute l'équipe et enregistre les paiements
     * (un responsable ; le propriétaire, qui voit tout, n'enregistre aucun
     * paiement) se voit proposer les factures de tous les vendeurs.
     */
    public function test_un_responsable_se_voit_proposer_les_factures_de_toute_l_equipe(): void
    {
        ['magasin' => $magasin, 'client' => $client, 'factureDeA' => $factureDeA, 'factureDeB' => $factureDeB] = $this->clientQuiDoitAuxDeuxVendeurs();
        $this->actingAs($this->responsableDeTest($magasin));

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
     * Un paiement que ce membre a enregistré : la liste ne lit que son
     * auteur.
     */
    private function paiementEnregistrePar(User $membre, Client $client, float $montant): Paiement
    {
        $paiement = new Paiement([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => $montant,
            'recu_le' => now()->toDateString(),
        ]);
        $paiement->forceFill(['cree_par' => $membre->getKey()])->save();

        return $paiement;
    }

    /**
     * Le client doit 120,00 € sur le ticket du vendeur A et 50,00 € sur
     * celui du vendeur B, deux vendeurs aux autorisations de leur rôle.
     *
     * @return array{magasin: Magasin, vendeurB: User, client: Client, factureDeA: Facture, factureDeB: Facture}
     */
    private function clientQuiDoitAuxDeuxVendeurs(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
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

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * Un paiement garde son client, même archivé depuis (voir
     * Paiement::client()) ; ce client, qui ne doit plus rien, n'est plus
     * proposé pour un nouveau paiement.
     */
    public function test_le_client_archive_reste_affiche_sur_ses_paiements_sans_etre_propose(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
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
        $factureA = $this->emettreFactureDepuisLePanneau($client, 120);
        $factureB = $this->emettreFactureDepuisLePanneau($client, 50);
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
        $facture = $this->emettreFactureDepuisLePanneau($client, 120);
        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 120], now()->toDateString());
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
        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 120], now()->toDateString());
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
        $this->encaisserDepuisLaListe($facture, ['mode' => ModePaiement::Especes->value, 'montant' => 120], now()->toDateString());

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
     * virement, rejeté, les a rouvertes et y a aussitôt imputé ce crédit,
     * sur la plus ancienne d'abord (complément 1).
     *
     * @param  list<float>  $prixDesFactures
     * @return array{client: Client, factures: list<Facture>}
     */
    private function clientDontLeCreditSImputeALaContrePassation(array $prixDesFactures): array
    {
        $client = Client::factory()->create();
        $achatRendu = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 36, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $factures = array_map(fn (float $prix): Facture => app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prix, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
        ])), $prixDesFactures);
        $virementRejete = app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => array_sum($prixDesFactures), 'recu_le' => today()->toDateString()],
            array_map(fn (Facture $facture): array => ['facture_id' => $facture->id, 'montant' => $facture->total_ttc], $factures),
        );
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $virementRejete, 'Virement rejeté');

        return ['client' => $client, 'factures' => $factures];
    }

    /**
     * Complément 1 et point 1 : le crédit est déjà imputé sur la facture
     * rouverte. « Encaisser » le montre en lecture, comme la part « Avoir
     * (crédit déjà imputé) », et propose ce qui reste dû, 84,00 €, dans le
     * mode préféré du client ; validé tel quel, il règle la facture avec
     * 84,00 € reçus.
     */
    public function test_encaisser_montre_le_credit_deja_impute_et_propose_ce_qui_reste_du(): void
    {
        $this->connecterLeProprietaire();
        ['client' => $client, 'factures' => [$facture]] = $this->clientDontLeCreditSImputeALaContrePassation([120]);
        $this->assertSame(0.0, $client->soldeCrediteur());
        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $facture);
        $part = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        $liste
            ->assertTableActionDataSet(["parts.{$part}.mode" => ModePaiement::Especes->value, "parts.{$part}.montant" => '84.00'])
            ->assertSee('Avoir (crédit déjà imputé)')
            ->assertSee('36,00 €')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, 84.0, 36.0, 0.0], [$facture->statut, $facture->montantPaye(), $facture->montantCrediteParAvoir(), $facture->montantRestant()]);
    }

    /**
     * Complément 1 : quand le crédit couvre tout ce que la contre-passation
     * a rouvert, la facture est réglée aussitôt, il reste 6,00 € de crédit
     * au client, et « Encaisser » disparaît.
     */
    public function test_une_facture_que_le_credit_solde_a_la_contre_passation_n_est_plus_a_encaisser(): void
    {
        $this->connecterLeProprietaire();
        ['client' => $client, 'factures' => [$facture]] = $this->clientDontLeCreditSImputeALaContrePassation([30]);

        Livewire::test(ListFactures::class)->assertTableActionHidden('encaisser', $facture);

        $this->assertSame([StatutFacture::Payee, 0.0, 30.0], [$facture->fresh()->statut, $facture->fresh()->montantPaye(), $facture->fresh()->montantCrediteParAvoir()]);
        $this->assertSame(6.0, $client->soldeCrediteur());
    }

    /**
     * Complément 1 : dans le formulaire des paiements, le choix d'une
     * facture propose ce qui en reste dû, le crédit y étant déjà imputé :
     * 84,00 € pour la plus ancienne, 120,00 € pour la suivante. Le paiement
     * de 204,00 € règle les deux, sans imputer aucun crédit.
     */
    public function test_choisir_une_facture_propose_son_reste_du_le_credit_etant_deja_impute(): void
    {
        $this->connecterLeProprietaire();
        ['client' => $client, 'factures' => [$premiere, $seconde]] = $this->clientDontLeCreditSImputeALaContrePassation([120, 120]);
        $formulaire = Livewire::test(CreatePaiement::class)
            ->fillForm(['client_id' => $client->id, 'mode_paiement' => ModePaiement::Virement->value, 'montant' => 204, 'recu_le' => now()->toDateString()])
            ->assertDontSee('Crédit disponible pour ce client');

        $formulaire
            ->fillForm(['allocations' => [['facture_id' => $premiere->id]]])
            ->assertFormSet(['allocations.0.montant' => 84.0])
            ->fillForm(['allocations' => [['facture_id' => $premiere->id, 'montant' => 84.0], ['facture_id' => $seconde->id]]])
            ->assertFormSet(['allocations.1.montant' => 120.0])
            ->call('create')
            ->assertHasNoFormErrors();

        $paiement = Paiement::query()->where('montant', '204.00')->sole();
        $this->assertSame([], $paiement->creditsImputes->all());
        $this->assertSame([StatutFacture::Payee, 84.0, 36.0], [$premiere->fresh()->statut, $premiere->fresh()->montantPaye(), $premiere->fresh()->montantCrediteParAvoir()]);
        $this->assertSame([StatutFacture::Payee, 120.0, 0.0], [$seconde->fresh()->statut, $seconde->fresh()->montantPaye(), $seconde->fresh()->montantCrediteParAvoir()]);
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

    /**
     * Un bon d'avoir du client comptoir de ce montant, AV-MABOUT-2026-000001
     * pour le premier : un ticket vendu au comptoir puis repris en entier.
     */
    private function bonDAvoir(float $montant): Avoir
    {
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $montant, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
    }

    /**
     * Q6, limite en attendant l'éditeur : sur un ticket du client comptoir
     * qui doit encore 20,00 €, « Encaisser » refuse dès la saisie un bon de
     * 30,00 €, qui couvrirait tout ce reste, sous le numéro du bon ; et un
     * bon de 10,00 € présenté sans part payée, sous le règlement. Rien
     * n'est enregistré.
     *
     * @return array<string, array{0: float, 1: string}>
     */
    public static function bonsSansPartPayee(): array
    {
        return [
            'bon qui couvre tout le reste dû' => [30, 'bon'],
            'bon sans part payée' => [10, 'parts'],
        ];
    }

    #[DataProvider('bonsSansPartPayee')]
    public function test_encaisser_refuse_des_la_saisie_un_bon_d_avoir_sans_part_payee(float $montantDuBon, string $champEnErreur): void
    {
        $this->connecterLeProprietaire();
        $bon = $this->bonDAvoir($montantDuBon);
        $ticket = $this->emettreFactureDepuisLePanneau(Client::query()->where('est_comptoir', true)->sole(), 20);
        $paiementsAvant = Paiement::query()->count();
        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $ticket);
        $cle = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        $liste
            ->setTableActionData(["parts.{$cle}.mode" => FactureResource::PART_AVOIR, "parts.{$cle}.bon" => $bon->numero])
            ->callMountedTableAction()
            ->assertHasTableActionErrors([$champEnErreur === 'bon' ? "parts.{$cle}.bon" : 'parts'])
            ->assertSee(Avoir::BON_D_AVOIR_SANS_PART_PAYEE);

        $this->assertSame([$paiementsAvant, (int) ($montantDuBon * 100)], [Paiement::query()->count(), $bon->fresh()->creditEnCentimes()]);
        $this->assertSame([StatutFacture::Emise, 20.0], [$ticket->fresh()->statut, $ticket->fresh()->montantRestant()]);
    }

    /**
     * Q6 : sur un ticket du client comptoir qui doit 20,00 €, un bon de
     * 10,00 € et 10,00 € en espèces le règlent d'un seul geste ; l'aide du
     * bon dit que la première part payée le signe.
     */
    public function test_encaisser_regle_un_ticket_du_client_comptoir_avec_un_bon_d_avoir_et_une_part_payee(): void
    {
        $this->connecterLeProprietaire();
        $bon = $this->bonDAvoir(10);
        $ticket = $this->emettreFactureDepuisLePanneau(Client::query()->where('est_comptoir', true)->sole(), 20);
        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $ticket);
        $especes = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        $liste
            ->setTableActionData([
                "parts.{$especes}.mode" => ModePaiement::Especes->value,
                "parts.{$especes}.montant" => '10',
                'parts.bon' => ['mode' => FactureResource::PART_AVOIR, 'bon' => $bon->numero, 'montant' => null, 'reference' => null, 'montant_saisi' => false],
            ])
            ->assertSee(FactureResource::BON_SIGNE_PAR_LA_PREMIERE_PART)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame([StatutFacture::Payee, 10.0, 10.0], [$ticket->fresh()->statut, $ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir()]);
        $this->assertSame(0, $bon->fresh()->creditEnCentimes());
    }

    /**
     * La fenêtre de la contre-passation du paiement qui signe un bon
     * d'avoir annonce qu'elle rend au bon son crédit, et, pour le client
     * comptoir, que seuls les avoirs qui corrigent les tickets rouverts
     * s'y imputeront ; celle d'un paiement sans bon n'en parle pas.
     */
    public function test_la_fenetre_de_contre_passation_annonce_le_bon_d_avoir_qu_elle_rend(): void
    {
        $this->connecterLeProprietaire();
        $bon = $this->bonDAvoir(10);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = $this->emettreFactureDepuisLePanneau($comptoir, 50);
        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $ticket, [new PartDeReglement(ModePaiement::CarteBancaire, 3000), new PartDeReglement(ModePaiement::Especes, 1000)], [$bon->numero], today()->toDateString());
        $partsDuTicket = Paiement::query()->whereHas('allocations', fn (Builder $allocations): Builder => $allocations->where('facture_id', $ticket->id));
        $carte = (clone $partsDuTicket)->where('mode_paiement', ModePaiement::CarteBancaire)->sole();
        $especes = (clone $partsDuTicket)->where('mode_paiement', ModePaiement::Especes)->sole();

        Livewire::test(ListPaiements::class)
            ->mountTableAction('contre_passer', $carte)
            ->assertSee("Le bon d'avoir {$bon->numero} (10,00 €), présenté avec lui, retrouvera le crédit qu'il y avait imputé, que le ticket devra de nouveau : son numéro pourra resservir.")
            ->assertSee('Les avoirs qui corrigent les tickets rouverts s\'y imputeront aussitôt.')
            ->assertDontSee('Le crédit d\'avoir disponible du client');
        Livewire::test(ListPaiements::class)
            ->mountTableAction('contre_passer', $especes)
            ->assertDontSee($bon->numero);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
