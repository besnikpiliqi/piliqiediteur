<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Deux membres du même magasin créent au même instant les références
 * « ABC » et « abc », chacun dans son processus, sur une vraie base
 * PostgreSQL : le second passe la vérification de la référence avant que
 * le premier ne valide, attend sur l'index unique, puis reçoit le refus
 * habituel de la référence, jamais l'erreur de l'index (page 500).
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ProduitConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_la_meme_reference_creee_au_meme_instant_par_deux_membres_est_refusee_au_second(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $magasinId = $magasin->id;
        $concurrente = null;

        DB::transaction(function () use (&$concurrente, $magasinId): void {
            Produit::factory()->create(['reference' => 'ABC']);
            $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($magasinId): void {
                app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
                Produit::factory()->create(['reference' => 'abc']);
            });
            $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
        });

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertSame(InvalidArgumentException::class.' : La référence « abc » est déjà celle d\'un autre produit.', $resultat->errorOutput());
        $this->assertSame(['ABC'], Produit::query()->pluck('reference')->all());
    }
}
