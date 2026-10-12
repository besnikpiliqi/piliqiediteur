<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Une vente validée par une autre caisse pendant la vérification de
 * l'intégrité, sur une vraie base PostgreSQL : le vérificateur lit toute
 * la base dans la photo prise à sa première lecture (REPEATABLE READ READ
 * ONLY), et ne signale donc pas à tort une ligne de tête qui ne
 * correspondrait plus aux derniers enregistrements relus après elle. La
 * vente sera vérifiée par la vérification suivante.
 *
 * DatabaseMigrations, pas RefreshDatabase : le vérificateur ouvre alors
 * lui-même la transaction la plus externe, et l'autre processus ne voit
 * que ce que le test a validé.
 */
class VerificateurIntegriteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_une_vente_validee_pendant_la_verification_n_y_parait_pas_a_moitie(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->create();
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $magasinId = $magasin->id;
        $clientId = $client->id;
        $produitId = $produit->id;
        $venteConcurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$venteConcurrente, $magasinId, $clientId, $produitId): void {
            if ($venteConcurrente === null && str_contains($requete->sql, 'from "tetes_registre_fiscal"')) {
                $venteConcurrente = $this->demarrerUnProcessusConcurrent(static function () use ($magasinId, $clientId, $produitId): void {
                    app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
                    app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::query()->findOrFail($clientId), [['produit_id' => $produitId, 'quantite' => 1]], ModePaiement::Especes);
                })->wait();
            }
        });

        $pendantLaVente = app(VerificateurIntegrite::class)->verifier($magasin);

        $this->assertTrue($venteConcurrente?->successful(), $venteConcurrente?->errorOutput() ?? 'La vente concurrente n\'a pas été lancée.');
        $this->assertSame(2, Facture::query()->count());
        $this->assertSame([], $pendantLaVente->anomalies());
        $this->assertTrue(app(VerificateurIntegrite::class)->verifier($magasin)->estConforme());
    }
}
