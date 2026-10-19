<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les deux premières ventes du même vendeur, à Lyon et à Villeurbanne, au
 * même instant, chacune dans son processus, sur une vraie base
 * PostgreSQL : chacune verrouille le registre de son magasin, puis la
 * ligne du vendeur (voir LecteurDesDroits::exigerSousVerrou()) ; la
 * seconde attend la première, puis voit sa vente sous ce verrou et est
 * refusée (voir VerificateurCaissesPersonnelles). Une personne n'encaisse
 * que dans un magasin à la fois.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class TracabiliteDesVentesConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_premieres_ventes_simultanees_du_meme_vendeur_dans_a_et_b_n_en_laissent_passer_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $vendeur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], [], [...PermissionRegistry::modeleDeLAdministrateur(), ...PermissionRegistry::deVente()]);
        [$vendeurId, $villeurbanneId] = [$vendeur->id, $villeurbanne->id];
        $produitDeVilleurbanne = app(TenantContext::class)->runAs($villeurbanne, fn (): int => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id);
        app(TenantContext::class)->set($lyon);
        $concurrente = null;
        $this->concurrencerDesQueLaLigneDuVendeurEstVerrouillee(static function () use ($vendeurId, $villeurbanneId, $produitDeVilleurbanne): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($villeurbanneId));
            self::vendreAuComptant(User::query()->findOrFail($vendeurId), Client::factory()->create(), [['produit_id' => $produitDeVilleurbanne, 'quantite' => 1]], ModePaiement::Especes);
        }, $concurrente);

        $venteDeLyon = self::vendreAuComptant($vendeur, Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful(), 'La vente de Villeurbanne aurait dû être refusée.');
        $this->assertStringContainsString('Fermez d\'abord votre caisse de Lyon : vous n\'encaissez que dans un magasin à la fois.', $resultat->errorOutput());
        $this->assertSame([[$venteDeLyon->id, $lyon->id]], Facture::withoutGlobalScope(MagasinScope::class)->get()->map(fn (Facture $vente): array => [$vente->id, $vente->magasin_id])->all());
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient la
     * ligne du vendeur verrouillée, et attend qu'elle attende ce verrou.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLaLigneDuVendeurEstVerrouillee(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        $verrouTenu = false;

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, &$verrouTenu, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            if (str_contains($requete->sql, 'from "users"') && str_contains($requete->sql, 'for no key update')) {
                $verrouTenu = true;

                return;
            }

            if ($verrouTenu) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }
}
