<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ExpirerLesDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * Un devis envoyé expire dans la nuit qui suit le dernier jour de sa
 * validité (B15) : app:expirer-devis, planifiée à 00:30, heure de Paris.
 */
class ExpirerDevisTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_devis_envoye_expire_le_lendemain_du_dernier_jour_de_sa_validite(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $echuHier = $this->devisEnvoye($magasin, '2026-10-08');
        $valableAujourdHui = $this->devisEnvoye($magasin, '2026-10-09');
        $this->travelTo('2026-10-09 00:30:00');

        $this->artisan('app:expirer-devis')
            ->expectsOutputToContain('1 devis expiré(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('devis', ['id' => $echuHier->id, 'statut' => 'expire']);
        $this->assertDatabaseHas('devis', ['id' => $valableAujourdHui->id, 'statut' => 'envoye']);
    }

    /**
     * Un brouillon n'est pas parti, un devis accepté l'a été à temps, un
     * devis sans date de validité n'expire jamais : aucun n'est touché.
     */
    public function test_seuls_les_devis_envoyes_expirent(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $brouillon = $this->devisBrouillon($magasin, '2026-10-08');
        $accepte = app(TenantContext::class)->runAs($magasin, fn (): Devis => app(AccepterDevisAction::class)->handle($this->devisEnvoye($magasin, '2026-10-08')));
        $sansValidite = $this->devisEnvoye($magasin, null);
        $this->travelTo('2026-10-09 00:30:00');

        $this->artisan('app:expirer-devis')->assertSuccessful();

        $this->assertDatabaseHas('devis', ['id' => $brouillon->id, 'statut' => 'brouillon']);
        $this->assertDatabaseHas('devis', ['id' => $accepte->id, 'statut' => 'accepte']);
        $this->assertDatabaseHas('devis', ['id' => $sansValidite->id, 'statut' => 'envoye']);
    }

    /**
     * Le passage d'un magasin, dans son propre contexte, ne touche pas aux
     * devis d'un autre magasin.
     */
    public function test_le_passage_d_un_magasin_ne_touche_pas_aux_devis_d_un_autre(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $devisDeA = $this->devisEnvoye($magasinA, '2026-10-08');
        $devisDeB = $this->devisEnvoye($magasinB, '2026-10-08');
        $this->travelTo('2026-10-09 00:30:00');

        $expires = app(TenantContext::class)->runAs($magasinA, fn (): int => app(ExpirerLesDevisAction::class)->handle($magasinA));

        $this->assertSame(1, $expires);
        $this->assertDatabaseHas('devis', ['id' => $devisDeA->id, 'statut' => 'expire']);
        $this->assertDatabaseHas('devis', ['id' => $devisDeB->id, 'statut' => 'envoye']);
    }

    /**
     * La requête est limitée au contexte (MagasinScope) et, explicitement,
     * au magasin traité : appelée pour un autre magasin que celui du
     * contexte, elle ne touche à rien.
     */
    public function test_un_passage_pour_un_autre_magasin_que_celui_du_contexte_ne_touche_a_rien(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $devisDeA = $this->devisEnvoye($magasinA, '2026-10-08');
        $devisDeB = $this->devisEnvoye($magasinB, '2026-10-08');
        $this->travelTo('2026-10-09 00:30:00');

        $expires = app(TenantContext::class)->runAs($magasinA, fn (): int => app(ExpirerLesDevisAction::class)->handle($magasinB));

        $this->assertSame(0, $expires);
        $this->assertDatabaseHas('devis', ['id' => $devisDeA->id, 'statut' => 'envoye']);
        $this->assertDatabaseHas('devis', ['id' => $devisDeB->id, 'statut' => 'envoye']);
    }

    /**
     * Suspendu, le magasin ne vend plus, mais les statuts de ses devis
     * continuent de suivre leurs dates.
     */
    public function test_un_magasin_suspendu_est_traite(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = Magasin::factory()->create();
        $devis = $this->devisEnvoye($magasin, '2026-10-08');
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->travelTo('2026-10-09 00:30:00');

        $this->artisan('app:expirer-devis')->assertSuccessful();

        $this->assertDatabaseHas('devis', ['id' => $devis->id, 'statut' => 'expire']);
    }

    /**
     * La panne d'un magasin n'arrête pas les suivants : elle est signalée,
     * la commande sort en échec, et ses devis expirent au passage suivant.
     */
    public function test_la_panne_d_un_magasin_n_empeche_pas_d_expirer_les_devis_des_autres(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasinEnPanne = Magasin::factory()->create();
        $autreMagasin = Magasin::factory()->create();
        $devisEnPanne = $this->devisEnvoye($magasinEnPanne, '2026-10-08');
        $autreDevis = $this->devisEnvoye($autreMagasin, '2026-10-08');
        $this->travelTo('2026-10-09 00:30:00');
        Exceptions::fake([RuntimeException::class]);
        $this->app->instance(ExpirerLesDevisAction::class, new class($magasinEnPanne->id) extends ExpirerLesDevisAction
        {
            public function __construct(private readonly int $magasinEnPanne) {}

            public function handle(Magasin $magasin): int
            {
                if ($magasin->id === $this->magasinEnPanne) {
                    throw new RuntimeException('Panne du serveur de base de données.');
                }

                return parent::handle($magasin);
            }
        });

        $this->artisan('app:expirer-devis')
            ->expectsOutputToContain('1 devis expiré(s).')
            ->expectsOutputToContain('1 magasin(s) en échec, repris au prochain passage.')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertDatabaseHas('devis', ['id' => $autreDevis->id, 'statut' => 'expire']);
        $this->assertDatabaseHas('devis', ['id' => $devisEnPanne->id, 'statut' => 'envoye']);

        $this->app->forgetInstance(ExpirerLesDevisAction::class);
        $this->travelTo('2026-10-10 00:30:00');
        $this->artisan('app:expirer-devis')->assertSuccessful();

        $this->assertDatabaseHas('devis', ['id' => $devisEnPanne->id, 'statut' => 'expire']);
    }

    public function test_l_expiration_des_devis_part_a_00_h_30_heure_de_paris(): void
    {
        // Le planning de bootstrap/app.php s'enregistre au démarrage
        // d'Artisan, comme sur le serveur avant schedule:run.
        $this->artisan('schedule:list')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-08 22:30:00', 'UTC'));

        $evenementsDus = app(Schedule::class)->dueEvents($this->app);

        $this->assertTrue($evenementsDus->contains(fn (Event $evenement): bool => str_contains((string) $evenement->command, 'app:expirer-devis')));
    }

    private function devisBrouillon(Magasin $magasin, ?string $dateValidite): Devis
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($dateValidite): Devis {
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

            return app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => $dateValidite]);
        });
    }

    private function devisEnvoye(Magasin $magasin, ?string $dateValidite): Devis
    {
        $devis = $this->devisBrouillon($magasin, $dateValidite);

        return app(TenantContext::class)->runAs($magasin, fn (): Devis => app(EmettreDevisAction::class)->handle($devis));
    }
}
