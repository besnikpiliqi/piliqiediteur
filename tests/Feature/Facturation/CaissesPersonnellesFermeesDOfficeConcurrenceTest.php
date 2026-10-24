<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R5 : le vendeur ferme sa caisse pendant que la clôture de la journée la
 * ferme d'office, chacun dans son processus, sur une vraie base
 * PostgreSQL. La fermeture d'office attend le verrou que tient celle du
 * vendeur sur sa fiche, relit tout après elle, ne trouve plus rien à
 * compter et ne note rien : aucune vente ni aucun paiement n'est compté
 * deux fois, et aucun relevé vide n'apparaît.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class CaissesPersonnellesFermeesDOfficeConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_une_fermeture_du_vendeur_et_une_fermeture_d_office_simultanees_ne_comptent_rien_deux_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $magasinId = $magasin->id;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $magasinId): void {
            if ($concurrente === null && str_contains($requete->sql, 'from "factures"') && str_contains($requete->sql, 'for update')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($magasinId): void {
                    $magasin = Magasin::query()->findOrFail($magasinId);
                    app(TenantContext::class)->set($magasin);
                    app(FermerLesCaissesPersonnellesAction::class)->handle($magasin, CarbonImmutable::now()->endOfDay());
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        $releveDuVendeur = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [$releveDuVendeur->id, ModeFermetureCaisse::Vendeur, 1, ['especes' => '12.00']],
            [$releve->id, $releve->mode_fermeture, $releve->nombre_ventes, $releve->encaissements],
        );
        $this->assertSame(1, DB::table('clotures_caisse_personnelles_factures')->count());
        $this->assertSame(1, DB::table('clotures_caisse_personnelles_paiements')->count());
    }
}
