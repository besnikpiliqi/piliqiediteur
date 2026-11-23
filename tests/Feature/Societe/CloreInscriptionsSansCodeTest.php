<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * La commande qui clôt les inscriptions restées sans code trente jours
 * après leur dernier courrier (voir CloreInscriptionsSansCode) : l'échec
 * d'une clôture est signalé et n'arrête pas les suivantes, et la commande
 * le dit par son code de sortie.
 */
class CloreInscriptionsSansCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_echec_d_une_cloture_est_signale_sans_arreter_les_suivantes(): void
    {
        Exceptions::fake();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $premiere = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $seconde = $this->inscrireUneSociete(null, 'paul@exemple.fr');
        app(TenantContext::class)->clear();
        $panne = new RuntimeException('Registre indisponible.');
        $closes = [];
        $this->mock(CloreLInscriptionAction::class, function (MockInterface $clore) use ($premiere, $panne, &$closes): void {
            $clore->shouldReceive('cloreSansCode')->twice()->andReturnUsing(function (Societe $societe) use ($premiere, $panne, &$closes): bool {
                if ($societe->is($premiere)) {
                    throw $panne;
                }

                $closes[] = $societe->id;

                return true;
            });
        });

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertFailed();

        $this->assertSame([$seconde->id], $closes);
        Exceptions::assertReported(fn (RuntimeException $erreur): bool => $erreur === $panne);
    }
}
