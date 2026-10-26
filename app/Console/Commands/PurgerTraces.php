<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande planifiée chaque nuit (voir bootstrap/app.php) : efface les
 * traces techniques (adresse IP et navigateur) dont la durée de
 * conservation est passée (expire_le, 1 an par défaut, voir
 * config/conservation.php), par lots de 1 000 traces : celles des
 * événements du registre fiscal (voir TraceEvenement), magasin par
 * magasin, sous le magasin de chacun, puis celles du journal d'audit (voir
 * TraceAudit), société par société, sous la société de chacune (une
 * commande n'a aucun TenantContext au démarrage). Un magasin suspendu ou
 * fermé, une société suspendue ou résiliée, sont traités comme les
 * autres : leurs traces ne se gardent pas plus longtemps. Rien d'autre ne
 * s'efface : la base refuse la suppression d'une trace avant son
 * expiration (voir DeclencheursDeProtection::effacementApresExpiration()).
 * Une trace s'efface donc une fois expirée à l'heure de l'application et à
 * celle du serveur de base, la seule que suit le déclencheur (voir
 * DeclencheursDeProtection::conditionDExpiration()) : quand l'horloge de
 * l'application devance celle de la base, une trace qui n'a expiré que
 * pour la première attend le passage suivant au lieu de faire refuser tout
 * son lot.
 *
 * Le nombre de traces effacées s'inscrit au journal de l'application. La
 * panne d'un magasin ou d'une société n'arrête pas les suivants : elle est
 * signalée (report()), et ses traces s'effacent au passage suivant.
 */
#[Signature('app:purger-traces')]
#[Description('Efface les traces techniques (adresse IP et navigateur) dont la durée de conservation est passée')]
class PurgerTraces extends Command
{
    public const LOT = 1000;

    public function handle(TenantContext $tenantContext): int
    {
        $limite = CarbonImmutable::now();
        $effacees = 0;
        $enEchec = 0;

        Magasin::query()->orderBy('id')->each(function (Magasin $magasin) use ($tenantContext, $limite, &$effacees, &$enEchec): void {
            try {
                $effacees += $tenantContext->runAs($magasin, fn (): int => $this->effacerLesTracesExpirees(TraceEvenement::query(...), 'enregistrement_fiscal_id', $limite));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Societe::query()->orderBy('id')->each(function (Societe $societe) use ($tenantContext, $limite, &$effacees, &$enEchec): void {
            try {
                $effacees += $tenantContext->runAsSociete($societe, fn (): int => $this->effacerLesTracesExpirees(TraceAudit::query(...), 'entree_audit_id', $limite));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Log::info('Traces techniques expirées effacées.', ['nombre' => $effacees]);
        $this->info("{$effacees} trace(s) technique(s) effacée(s).");

        if ($enEchec > 0) {
            $this->error("{$enEchec} magasin(s) ou société(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Les traces expirées du magasin ou de la société actifs, lot par lot.
     *
     * @param  Closure(): Builder<Model>  $traces  la requête des traces, sous le scope du magasin ou de la société actifs
     * @param  CarbonImmutable  $limite  l'instant du passage
     */
    private function effacerLesTracesExpirees(Closure $traces, string $cle, CarbonImmutable $limite): int
    {
        $expirees = fn (): Builder => $traces()
            ->where('expire_le', '<=', $limite)
            ->whereRaw(DeclencheursDeProtection::conditionDExpiration());
        $effacees = 0;

        do {
            $lot = $expirees()
                ->orderBy($cle)
                ->limit(self::LOT)
                ->pluck($cle);

            if ($lot->isNotEmpty()) {
                $effacees += $expirees()->whereKey($lot->all())->delete();
            }
        } while ($lot->count() === self::LOT);

        return $effacees;
    }
}
