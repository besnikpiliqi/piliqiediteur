<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DatabaseMigrations, pas RefreshDatabase : la migration doit tourner hors
 * de toute transaction, comme un vrai "php artisan migrate".
 */
class NoterLesVentesCompteesParFermetureMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_10_13_100004_noter_les_ventes_comptees_par_fermeture_de_caisse';

    /**
     * Fermetures faites avec l'ancien curseur : chacune a compté les ventes
     * de son intervalle déjà émises au moment de la fermeture. Le brouillon
     * F1, émis après la première fermeture alors que son ID était déjà
     * couvert, n'a jamais été compté : la migration ne le note nulle part,
     * et la prochaine fermeture le rattrape.
     */
    public function test_les_fermetures_existantes_sont_reprises_et_la_vente_sautee_est_rattrapee(): void
    {
        $this->revenirAvantLaMigration();

        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = User::factory()->create();
        $this->actingAs($vendeur);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        $debut = now()->startOfHour();
        $this->travelTo($debut);
        $brouillon = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $venteComptee = $this->emettreUneVente($client, $produit);
        $premiereFermeture = $this->fermetureAvecCurseur($magasin, $vendeur, $venteComptee, $debut->copy()->addMinute());

        $this->travelTo($debut->copy()->addMinutes(10));
        app(EmettreFactureAction::class)->handle($brouillon);
        $venteSuivante = $this->emettreUneVente($client, $produit);
        $secondeFermeture = $this->fermetureAvecCurseur($magasin, $vendeur, $venteSuivante, $debut->copy()->addMinutes(20));
        $this->travelTo($debut->copy()->addMinutes(30));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('clotures_caisse_personnelles', 'dernier_facture_id'));
        $this->assertSame(
            [$venteComptee->id => $premiereFermeture, $venteSuivante->id => $secondeFermeture],
            DB::table('clotures_caisse_personnelles_factures')->orderBy('facture_id')->pluck('cloture_caisse_personnelle_id', 'facture_id')->all(),
        );

        $rattrapage = app(FermerSaCaisseAction::class)->handle($vendeur);

        $this->assertSame(1, $rattrapage->nombre_ventes);
        $this->assertSame([$brouillon->id], $rattrapage->factures()->pluck('factures.id')->all());
    }

    public function test_revenir_en_arriere_reconstruit_les_curseurs(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $vendeur = User::factory()->create();
        $this->actingAs($vendeur);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $vente = $this->emettreUneVente($client, $produit);
        $avecVente = app(FermerSaCaisseAction::class)->handle($vendeur);
        $sansVente = app(FermerSaCaisseAction::class)->handle($vendeur);

        $this->revenirAvantLaMigration();

        $this->assertFalse(Schema::hasTable('clotures_caisse_personnelles_factures'));
        $this->assertSame($vente->id, (int) DB::table('clotures_caisse_personnelles')->where('id', $avecVente->id)->value('dernier_facture_id'));
        $this->assertSame($vente->id, (int) DB::table('clotures_caisse_personnelles')->where('id', $sansVente->id)->value('dernier_facture_id'));

        $this->artisan('migrate')->assertSuccessful();
    }

    private function emettreUneVente(Client $client, Produit $produit): Facture
    {
        return app(EmettreFactureAction::class)->handle(
            app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]),
        );
    }

    private function fermetureAvecCurseur(Magasin $magasin, User $vendeur, Facture $derniereFactureIncluse, CarbonInterface $clotureeA): int
    {
        return DB::table('clotures_caisse_personnelles')->insertGetId([
            'magasin_id' => $magasin->id,
            'cree_par' => $vendeur->id,
            'dernier_facture_id' => $derniereFactureIncluse->id,
            'periode_debut' => $clotureeA->copy()->startOfDay(),
            'cloturee_a' => $clotureeA,
            'nombre_ventes' => 1,
            'created_at' => $clotureeA,
            'updated_at' => $clotureeA,
        ]);
    }

    private function revenirAvantLaMigration(): void
    {
        $etapes = DB::table('migrations')->where('migration', '>=', self::MIGRATION)->count();

        $this->artisan('migrate:rollback', ['--step' => $etapes])->assertSuccessful();
    }
}
