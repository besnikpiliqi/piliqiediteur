<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deux « Fermer ma caisse » du même vendeur au même instant (double clic,
 * deux onglets), chacune dans son processus, sur une vraie base
 * PostgreSQL : la seconde attend la première puis part de sa borne (voir
 * FermerSaCaisseAction::debutDuReleve()), sans compter une seconde fois
 * ses ventes ni ses paiements, ni échouer sur la clé primaire de
 * clotures_caisse_personnelles_factures ou de
 * clotures_caisse_personnelles_paiements (erreur 500).
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FermerSaCaisseActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_fermetures_simultanees_du_meme_vendeur_se_suivent_sans_compter_deux_fois_ses_ventes_ni_ses_paiements(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $magasinId = $magasin->id;
        $vendeurId = $vendeur->id;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $magasinId, $vendeurId): void {
            if ($concurrente === null && str_contains($requete->sql, 'from "factures"') && str_contains($requete->sql, 'for update')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($magasinId, $vendeurId): void {
                    app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
                    app(FermerSaCaisseAction::class)->handle(User::query()->findOrFail($vendeurId), User::query()->findOrFail($vendeurId));
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        $premiere = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $seconde = ClotureCaissePersonnelle::query()->whereKeyNot($premiere->id)->sole();
        $this->assertSame([1, 0], [$premiere->fresh()->nombre_ventes, $seconde->nombre_ventes]);
        $this->assertSame($premiere->fresh()->jusqu_au->format('Y-m-d H:i:s'), $seconde->periode_debut->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('clotures_caisse_personnelles_factures')->count());
        $this->assertSame(1, DB::table('clotures_caisse_personnelles_paiements')->count());
    }
}
