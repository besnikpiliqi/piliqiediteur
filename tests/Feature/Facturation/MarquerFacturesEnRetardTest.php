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
use App\Domain\Facturation\Models\Paiement;
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
use Tests\TestCase;

class MarquerFacturesEnRetardTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFacture(Magasin $magasin, string $dateEcheance): Facture
    {
        app(TenantContext::class)->set($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ['date_echeance' => $dateEcheance]);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_une_facture_emise_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(5)->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    public function test_une_facture_partiellement_payee_dont_lecheance_est_depassee_passe_en_retard(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDay()->toDateString());

        app(TenantContext::class)->set($magasin);
        $paiement = Paiement::create([
            'client_id' => $facture->client_id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 50,
            'recu_le' => now()->toDateString(),
        ]);
        $facture->allocationsPaiement()->create([
            'paiement_id' => $paiement->id,
            'montant_alloue' => 50,
        ]);

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    public function test_une_facture_dont_lecheance_nest_pas_encore_passee_nest_pas_touchee(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->addDays(10)->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    public function test_une_facture_deja_payee_nest_pas_touchee_meme_echue(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(3)->toDateString());

        app(TenantContext::class)->set($magasin);
        $facture->forceFill(['statut' => StatutFacture::Payee])->save();

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_un_magasin_suspendu_nest_pas_traite(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFacture($magasin, now()->subDays(2)->toDateString());

        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Le propriétaire de chaque magasin apprend quelles factures de SON
     * magasin viennent de passer en retard -- jamais celles d'un autre, et
     * le reste de l'équipe n'est pas dérangé.
     */
    public function test_le_proprietaire_est_prevenu_des_factures_de_son_magasin_passees_en_retard(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $vendeurA = app(CreerMembreMagasinAction::class)->handle($magasinA, ['name' => 'Vendeur', 'email' => 'vendeur@boutique-a.fr', 'password' => 'secret123'], RoleMagasin::Vendeur);
        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);
        $factureA1 = $this->emettreFacture($magasinA, now()->subDays(5)->toDateString());
        $factureA2 = $this->emettreFacture($magasinA, now()->subDay()->toDateString());
        $this->emettreFacture($magasinA, now()->addDay()->toDateString());
        $factureB = $this->emettreFacture($magasinB, now()->subDays(2)->toDateString());

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
        $this->emettreFacture($magasin, now()->addDays(3)->toDateString());

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
        $this->travelTo('2026-10-02 00:15:00');
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $facture = $this->emettreFacture($magasin, '2026-10-01');
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->travelTo('2026-10-02 15:00:00');
        app(TenantContext::class)->set($magasin);
        app(EnregistrerPaiementAction::class)->handle([
            'client_id' => $facture->client_id,
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
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $numeros = collect(range(1, 11))->map(fn (): string => $this->emettreFacture($magasin, now()->subDays(3)->toDateString())->numero);

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $notification = $proprietaire->notifications()->sole();
        $this->assertSame('Ma Boutique : 11 factures passées en retard', $notification->data['title']);
        $this->assertSame('Échéance dépassée sans règlement complet : '.$numeros->take(10)->implode(', ').' et 1 autre(s).', $notification->data['body']);
    }

    public function test_traite_plusieurs_magasins_independamment(): void
    {
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $factureA = $this->emettreFacture($magasinA, now()->subDay()->toDateString());
        $factureB = $this->emettreFacture($magasinB, now()->subDay()->toDateString());

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::EnRetard, $factureB->fresh()->statut);
    }
}
