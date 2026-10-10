<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class ClotureAutomatiqueCaisseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une vente à un client particulier, émise maintenant.
     */
    private function emettreUneVente(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    /**
     * Un ticket de 12,00 € TTC au client particulier, émis maintenant.
     */
    private function vendreUnTicketDe12Euros(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    private function emettreFactureHier(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        // L'émission se date à l'instant du registre fiscal (voir
        // EmettreFactureAction), et une facture émise ne se modifie plus :
        // une vente d'hier s'émet hier.
        $this->travel(-1)->days();
        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->travelBack();

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
        $this->travel(-1)->days();
        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->travelBack();

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
        $this->travelTo('2026-10-08 15:00:00');
        $magasin = Magasin::factory()->create();
        $venteDHier = $this->emettreUneVente($magasin);
        $this->travelTo('2026-10-09 15:00:00');
        $venteDuJour = $this->emettreUneVente($magasin);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame(['2026-10-08', 1], [$cloture->date_cloture->toDateString(), $cloture->nombre_ventes]);
        $this->assertSame($cloture->id, $venteDHier->fresh()->cloture_caisse_id);
        $this->assertNull($venteDuJour->fresh()->cloture_caisse_id, 'La vente du jour même ne doit pas être incluse dans la clôture de la veille.');
    }

    /**
     * Un ticket Z se produit chaque jour à partir de la première vente,
     * jamais avant : ni un magasin qui n'a jamais vendu, ni un magasin dont
     * la première vente est d'aujourd'hui n'a de journée à clôturer, et
     * aucun numéro n'est consommé.
     */
    public function test_aucune_journee_n_est_cloturee_avant_la_premiere_vente(): void
    {
        $this->travelTo('2026-10-09 15:00:00');
        $magasinSansVente = Magasin::factory()->create();
        $magasinVendantAujourdHui = Magasin::factory()->create();
        $this->emettreUneVente($magasinVendantAujourdHui);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        foreach ([$magasinSansVente, $magasinVendantAujourdHui] as $magasin) {
            app(TenantContext::class)->set($magasin);
            $this->assertSame(0, ClotureCaisse::query()->count());
            $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->where('type_document', TypeDocument::ClotureCaisse->value)->exists());
        }
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

    /**
     * Suspendu, le magasin ne vend plus, mais ses clôtures continuent
     * jusqu'au bout : la journée de ses dernières ventes est clôturée.
     */
    public function test_un_magasin_suspendu_est_cloture(): void
    {
        $magasin = Magasin::factory()->create();
        $facture = $this->emettreFactureHier($magasin);
        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, ClotureCaisse::query()->count());
        $this->assertNotNull($facture->fresh()->cloture_caisse_id);
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

    /**
     * La clôture du 31 décembre, faite à minuit le 1er janvier, termine la
     * série de son année ; celle du 1er janvier, sans vente, ouvre la
     * série de la nouvelle année.
     */
    public function test_la_cloture_du_31_decembre_est_numerotee_dans_la_serie_de_son_annee(): void
    {
        $magasin = Magasin::factory()->create(['nom' => 'Ma Boutique']);
        $this->travelTo('2026-12-31 18:00:00');
        $this->vendreUnTicketDe12Euros($magasin);

        $this->travelTo('2027-01-01 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->travelTo('2027-01-02 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(
            [['2026-12-31', 'Z-MABOUT-2026-000001', '12.00'], ['2027-01-01', 'Z-MABOUT-2027-000001', '0.00']],
            ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->total_ttc])->all(),
        );
    }

    /**
     * La commande n'a tourné ni le 07 ni le 08 à minuit : le 09 à minuit,
     * elle clôture dans l'ordre les journées des 06, 07 et 08, et la vente
     * du 07 entre dans la clôture de sa journée.
     */
    public function test_rattrape_dans_l_ordre_les_journees_que_la_commande_a_manquees(): void
    {
        $magasin = Magasin::factory()->create(['nom' => 'Ma Boutique']);
        $this->travelTo('2026-10-05 10:00:00');
        $this->vendreUnTicketDe12Euros($magasin);
        $this->travelTo('2026-10-06 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->travelTo('2026-10-07 15:00:00');
        $venteDu7 = $this->vendreUnTicketDe12Euros($magasin);

        $this->travelTo('2026-10-09 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')
            ->expectsOutputToContain('3 clôture(s) automatique(s) effectuée(s) jusqu\'au 08/10/2026.')
            ->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $clotures = ClotureCaisse::query()->orderBy('date_cloture')->get();
        $this->assertSame(
            [['2026-10-05', 'Z-MABOUT-2026-000001', '12.00'], ['2026-10-06', 'Z-MABOUT-2026-000002', '0.00'], ['2026-10-07', 'Z-MABOUT-2026-000003', '12.00'], ['2026-10-08', 'Z-MABOUT-2026-000004', '0.00']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->total_ttc])->all(),
        );
        $this->assertSame($clotures[2]->id, $venteDu7->fresh()->cloture_caisse_id);
    }

    /**
     * Un ticket Z se produit chaque jour à partir de la première vente :
     * un magasin ouvert le 01/10 qui vend pour la première fois le 05/10
     * n'a aucune clôture avant, puis seulement celle du 05/10.
     */
    public function test_les_clotures_commencent_le_jour_de_la_premiere_vente(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = Magasin::factory()->create();
        foreach (['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $jour) {
            $this->travelTo("{$jour} 00:00:00");
            $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        }
        app(TenantContext::class)->set($magasin);
        $avantLaPremiereVente = ClotureCaisse::query()->count();
        $this->travelTo('2026-10-05 10:00:00');
        $this->vendreUnTicketDe12Euros($magasin);

        $this->travelTo('2026-10-06 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(0, $avantLaPremiereVente);
        $this->assertSame(['2026-10-05'], ClotureCaisse::query()->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture->toDateString())->all());
    }

    /**
     * La panne d'un magasin n'arrête pas les suivants : elle est signalée,
     * la commande sort en échec, et ce magasin reprend à sa journée en
     * échec au passage suivant.
     */
    public function test_la_panne_d_un_magasin_n_empeche_pas_de_cloturer_les_autres(): void
    {
        $magasinEnPanne = Magasin::factory()->create();
        $autreMagasin = Magasin::factory()->create();
        $this->travelTo('2026-10-08 10:00:00');
        $this->vendreUnTicketDe12Euros($magasinEnPanne);
        $this->vendreUnTicketDe12Euros($autreMagasin);
        $this->travelTo('2026-10-09 00:00:00');
        Exceptions::fake([RuntimeException::class]);
        $this->app->instance(CloturerCaisseAction::class, new class(app(DocumentNumberingService::class), app(CalculateurLigne::class), app(RegistreFiscal::class), app(PanierDeCaisse::class), $magasinEnPanne->id) extends CloturerCaisseAction
        {
            public function __construct(DocumentNumberingService $numerotation, CalculateurLigne $calculateur, RegistreFiscal $registre, PanierDeCaisse $panierDeCaisse, private readonly int $magasinEnPanne)
            {
                parent::__construct($numerotation, $calculateur, $registre, $panierDeCaisse);
            }

            public function handle(Magasin $magasin, CarbonInterface|string $date): ClotureCaisse
            {
                if ($magasin->id === $this->magasinEnPanne) {
                    throw new RuntimeException('Panne du serveur de base de données.');
                }

                return parent::handle($magasin, $date);
            }
        });

        $this->artisan('app:cloturer-caisses-automatiquement')
            ->expectsOutputToContain('1 clôture(s) automatique(s) effectuée(s) jusqu\'au 08/10/2026.')
            ->expectsOutputToContain('1 magasin(s) en échec, repris au prochain passage.')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(1, app(TenantContext::class)->runAs($autreMagasin, fn (): int => ClotureCaisse::query()->count()));
        $this->assertSame(0, app(TenantContext::class)->runAs($magasinEnPanne, fn (): int => ClotureCaisse::query()->count()));

        $this->app->forgetInstance(CloturerCaisseAction::class);
        $this->travelTo('2026-10-10 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $this->assertSame(
            ['2026-10-08', '2026-10-09'],
            app(TenantContext::class)->runAs($magasinEnPanne, fn (): array => ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture->toDateString())->all()),
        );
    }
}
