<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande planifiée chaque nuit (voir bootstrap/app.php) : efface les
 * traces techniques (adresse IP et navigateur, voir TraceEvenement) dont la
 * durée de conservation est passée (expire_le, 1 an par défaut, voir
 * config/conservation.php), magasin par magasin, sous le magasin de chacun
 * (une commande n'a aucun TenantContext au démarrage), par lots de 1 000
 * traces. Un magasin supprimé, suspendu ou fermé est traité comme les
 * autres : ses traces ne se gardent pas plus longtemps. Rien d'autre ne
 * s'efface : la base refuse la suppression d'une trace avant son
 * expiration (voir DeclencheursDeProtection::effacementApresExpiration()).
 *
 * Le nombre de traces effacées s'inscrit au journal de l'application. La
 * panne d'un magasin n'arrête pas les suivants : elle est signalée
 * (report()), et ses traces s'effacent au passage suivant.
 */
#[Signature('app:purger-traces')]
#[Description('Efface les traces techniques (adresse IP et navigateur) dont la durée de conservation est passée')]
class PurgerTraces extends Command
{
    public const LOT = 1000;

    public function handle(TenantContext $tenantContext): int
    {
        $limite = HorodatageUtc::enBase(now());
        $effacees = 0;
        $magasinsEnEchec = 0;

        Magasin::withTrashed()->each(function (Magasin $magasin) use ($tenantContext, $limite, &$effacees, &$magasinsEnEchec): void {
            try {
                $effacees += $tenantContext->runAs($magasin, fn (): int => $this->effacerLesTracesExpirees($limite));
            } catch (Throwable $exception) {
                report($exception);
                $magasinsEnEchec++;
            }
        });

        Log::info('Traces techniques expirées effacées.', ['nombre' => $effacees]);
        $this->info("{$effacees} trace(s) technique(s) effacée(s).");

        if ($magasinsEnEchec > 0) {
            $this->error("{$magasinsEnEchec} magasin(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Les traces expirées du magasin actif, lot par lot.
     *
     * @param  string  $limite  maintenant, tel que expire_le s'écrit en base (UTC)
     */
    private function effacerLesTracesExpirees(string $limite): int
    {
        $effacees = 0;

        do {
            $lot = TraceEvenement::query()
                ->where('expire_le', '<=', $limite)
                ->orderBy('enregistrement_fiscal_id')
                ->limit(self::LOT)
                ->pluck('enregistrement_fiscal_id');

            if ($lot->isNotEmpty()) {
                $effacees += TraceEvenement::query()->whereKey($lot->all())->delete();
            }
        } while ($lot->count() === self::LOT);

        return $effacees;
    }
}
