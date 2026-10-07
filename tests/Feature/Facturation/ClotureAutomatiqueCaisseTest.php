<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClotureAutomatiqueCaisseTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureHier(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        // L'émission se fait "maintenant" (voir EmettreFactureAction) :
        // on recule la date d'émission pour simuler une vente d'hier.
        $facture->forceFill(['date_emission' => now()->subDay()])->save();

        return $facture;
    }

    public function test_cloture_la_veille_pour_un_magasin_qui_ne_la_pas_fait_lui_meme(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFactureHier($magasin);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, ClotureCaisse::query()->count());
        $this->assertNotNull($facture->fresh()->cloture_caisse_id);
    }

    /**
     * Exigence explicite : contrairement à la clôture MANUELLE (voir
     * ListClotureCaisses, bloquée tant qu'un vendeur n'a pas fermé sa
     * caisse personnelle, voir VerificateurCaissesPersonnelles), la
     * commande automatique de minuit doit toujours clôturer -- elle est le
     * filet de sécurité si personne n'intervient, jamais un chemin qui peut
     * rester bloqué.
     */
    public function test_cloture_meme_si_le_vendeur_na_pas_ferme_sa_caisse_personnelle(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $this->actingAs($vendeur);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);
        $facture->forceFill(['date_emission' => now()->subDay()])->save();

        // Le vendeur n'a jamais fermé sa caisse personnelle : il apparaîtrait
        // comme "en attente" si on le vérifiait (voir
        // VerificateurCaissesPersonnelles), mais ça ne doit rien changer ici.
        $this->assertTrue(
            app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDay())->contains('id', $vendeur->id)
        );

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, ClotureCaisse::query()->count());
        $this->assertNotNull($facture->fresh()->cloture_caisse_id);
    }

    public function test_ne_reclôture_pas_un_magasin_deja_cloture_manuellement(): void
    {
        $magasin = Magasin::factory()->create();
        $this->emettreFactureHier($magasin);

        app(TenantContext::class)->set($magasin);
        app(CloturerCaisseAction::class)->handle($magasin, now()->subDay());

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, ClotureCaisse::query()->count(), 'Une seule clôture doit exister pour la veille.');
    }

    public function test_ne_cloture_jamais_le_jour_meme(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle($facture); // vente d'aujourd'hui

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $cloture = ClotureCaisse::query()->first();
        $this->assertSame(0, $cloture->nombre_ventes, 'La vente du jour même ne doit pas être incluse dans la clôture de la veille.');
    }

    /**
     * Minuit à Paris en été, c'est 22 h UTC la veille : la commande clôture
     * la journée parisienne qui vient de finir, avec la vente de 23 h 30,
     * et non l'avant-veille.
     */
    public function test_a_minuit_heure_de_paris_cloture_la_journee_qui_vient_de_finir(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $this->travelTo(Carbon::parse('2026-07-14 21:30:00', 'UTC'));
        app(EmettreFactureAction::class)->handle($facture);
        $this->travelTo(Carbon::parse('2026-07-14 22:00:30', 'UTC'));

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame('2026-07-14', $cloture->date_cloture->toDateString());
        $this->assertSame($cloture->id, $facture->fresh()->cloture_caisse_id);
    }

    public function test_la_cloture_automatique_part_a_minuit_heure_de_paris(): void
    {
        // Le planning de bootstrap/app.php s'enregistre au démarrage
        // d'Artisan, comme sur le serveur avant schedule:run.
        $this->artisan('schedule:list')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-07-14 22:00:00', 'UTC'));

        $evenementsDus = app(Schedule::class)->dueEvents($this->app);

        $this->assertTrue($evenementsDus->contains(fn (Event $evenement): bool => str_contains((string) $evenement->command, 'app:cloturer-caisses-automatiquement')));
    }

    public function test_un_magasin_suspendu_nest_pas_traite(): void
    {
        $magasin = Magasin::factory()->create();
        $this->emettreFactureHier($magasin);
        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    public function test_traite_plusieurs_magasins_independamment(): void
    {
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $this->emettreFactureHier($magasinA);
        $this->emettreFactureHier($magasinB);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasinA);
        $this->assertSame(1, ClotureCaisse::query()->count());

        app(TenantContext::class)->set($magasinB);
        $this->assertSame(1, ClotureCaisse::query()->count());
    }
}
