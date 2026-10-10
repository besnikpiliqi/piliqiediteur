<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MarquerFacturesEnRetardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un ticket de 120,00 € TTC émis maintenant à un client au délai de
     * paiement donné : son échéance est la date d'émission plus ce délai
     * (B7).
     */
    private function emettreFacture(Magasin $magasin, int $delaiDuClient): Facture
    {
        app(TenantContext::class)->set($magasin);

        $client = Client::factory()->create(['delai_paiement_jours' => $delaiDuClient]);
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_une_facture_emise_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 0);
        $this->travelTo('2026-10-06 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    public function test_une_facture_partiellement_payee_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 0);
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 50]]);
        $this->assertSame(StatutFacture::PartiellementPayee, $facture->fresh()->statut);
        app(TenantContext::class)->clear();
        $this->travelTo('2026-10-02 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    /**
     * L'échéance est le dernier jour pour payer, à l'heure de Paris : émise
     * juste après minuit à un client payable comptant, la facture échoit le
     * jour même et n'est pas encore en retard ; elle l'est le lendemain,
     * juste après minuit.
     */
    public function test_une_facture_dont_l_echeance_est_aujourd_hui_n_est_en_retard_que_le_lendemain(): void
    {
        $this->travelTo('2026-10-09 00:15:00');
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 0);
        app(TenantContext::class)->clear();

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);

        $this->travelTo('2026-10-10 00:15:00');
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(['2026-10-09', StatutFacture::EnRetard], [$facture->fresh()->date_echeance->toDateString(), $facture->fresh()->statut]);
    }

    public function test_une_facture_dont_lecheance_nest_pas_encore_passee_nest_pas_touchee(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 10);

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    public function test_une_facture_deja_payee_nest_pas_touchee_meme_echue(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 0);

        app(TenantContext::class)->set($magasin);
        $facture->forceFill(['statut' => StatutFacture::Payee])->save();
        $this->travelTo('2026-10-04 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    /**
     * G1 : une facture n'est en retard que tant qu'un montant en reste dû
     * après l'échéance. Remisée à 100 %, elle ne doit rien : payée dès son
     * émission, elle ne passe jamais en retard, et personne n'en est
     * prévenu.
     */
    public function test_une_facture_qui_ne_doit_rien_ne_passe_jamais_en_retard(): void
    {
        Notification::fake();
        $this->travelTo('2026-09-01 10:00:00');
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 100],
        ]));
        app(TenantContext::class)->clear();
        $this->travelTo('2026-10-15 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame([StatutFacture::Payee, '0.00'], [$facture->fresh()->statut, $facture->fresh()->total_ttc]);
        Notification::assertNothingSent();
    }

    /**
     * Suspendu, le magasin ne vend plus, mais les statuts de ses factures
     * continuent de suivre leurs échéances.
     */
    public function test_un_magasin_suspendu_est_traite(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, 0);

        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();
        $this->travelTo('2026-10-03 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    /**
     * Le propriétaire de chaque magasin apprend quelles factures de SON
     * magasin viennent de passer en retard -- jamais celles d'un autre, et
     * le reste de l'équipe n'est pas dérangé.
     */
    public function test_le_proprietaire_est_prevenu_des_factures_de_son_magasin_passees_en_retard(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $vendeurA = app(CreerMembreMagasinAction::class)->handle($proprietaireA, $magasinA, ['name' => 'Vendeur', 'email' => 'vendeur@boutique-a.fr', 'password' => 'secret123'], RoleMagasin::Vendeur);
        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);
        $factureA1 = $this->emettreFacture($magasinA, 0);
        $factureA2 = $this->emettreFacture($magasinA, 4);
        $this->emettreFacture($magasinA, 6);
        $factureB = $this->emettreFacture($magasinB, 3);
        $this->travelTo('2026-10-06 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $notification = $proprietaireA->notifications()->sole();
        $this->assertSame(FacturesPasseesEnRetard::class, $notification->type);
        $this->assertSame('Boutique A : 2 factures passées en retard', $notification->data['title']);
        $this->assertSame("Échéance dépassée sans règlement complet : {$factureA1->numero}, {$factureA2->numero}.", $notification->data['body']);
        $this->assertSame($magasinA->id, $notification->data['magasin_id']);
        $this->assertSame(
            FactureResource::getUrl('index', ['tableFilters' => ['statut' => ['value' => 'en_retard']]], panel: 'app', tenant: $magasinA),
            $notification->data['actions'][0]['url'],
        );
        $this->assertSame(0, $vendeurA->notifications()->count());
        $notificationDeB = $proprietaireB->notifications()->sole();
        $this->assertSame('Boutique B : 1 facture passée en retard', $notificationDeB->data['title']);
        $this->assertSame("Échéance dépassée sans règlement complet : {$factureB->numero}.", $notificationDeB->data['body']);
    }

    public function test_aucune_notification_quand_aucune_facture_ne_passe_en_retard(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->emettreFacture($magasin, 3);

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(0, $proprietaire->notifications()->count());
    }

    /**
     * Payée en partie après son passage en retard, une facture reste en
     * retard -- son échéance est toujours dépassée -- et le propriétaire
     * n'est pas prévenu une deuxième fois le lendemain.
     */
    public function test_une_facture_en_retard_payee_en_partie_reste_en_retard_sans_nouvelle_notification(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $facture = $this->emettreFacture($magasin, 0);
        $this->travelTo('2026-10-02 00:15:00');
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->travelTo('2026-10-02 15:00:00');
        app(TenantContext::class)->set($magasin);
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 20,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 20]]);
        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);

        $this->travelTo('2026-10-03 00:15:00');
        app(TenantContext::class)->clear();
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(1, $proprietaire->notifications()->count());
    }

    /**
     * La notification nomme les dix premières factures passées en retard
     * et compte les autres : elle reste lisible un jour de rattrapage.
     */
    public function test_la_notification_nomme_les_dix_premieres_factures_et_compte_les_autres(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $numeros = collect(range(1, 11))->map(fn (): string => $this->emettreFacture($magasin, 0)->numero);
        $this->travelTo('2026-10-04 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $notification = $proprietaire->notifications()->sole();
        $this->assertSame('Ma Boutique : 11 factures passées en retard', $notification->data['title']);
        $this->assertSame('Échéance dépassée sans règlement complet : '.$numeros->take(10)->implode(', ').' et 1 autre(s).', $notification->data['body']);
    }

    public function test_traite_plusieurs_magasins_independamment(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $factureA = $this->emettreFacture($magasinA, 0);
        $factureB = $this->emettreFacture($magasinB, 0);
        $this->travelTo('2026-10-02 10:00:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::EnRetard, $factureB->fresh()->statut);
    }
}
