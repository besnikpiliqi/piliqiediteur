<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\ActionTraitee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande planifiée chaque nuit (voir bootstrap/app.php) : efface les
 * jetons des demandes traitées (R3, voir ActionsUniques) inscrits il y a
 * plus de 30 jours (voir config/conservation.php), magasin par magasin,
 * sous le magasin de chacun (une commande n'a aucun TenantContext au
 * démarrage), par lots de 1 000. Rien d'autre ne s'efface : la table est
 * technique, sans valeur fiscale ; les documents et les registres restent.
 *
 * Le nombre de jetons effacés s'inscrit au journal de l'application. La
 * panne d'un magasin n'arrête pas les suivants : elle est signalée
 * (report()), et ses jetons s'effacent au passage suivant.
 */
#[Signature('app:purger-actions-traitees')]
#[Description('Efface les jetons des demandes traitées depuis plus de 30 jours')]
class PurgerActionsTraitees extends Command
{
    public const LOT = 1000;

    public function handle(TenantContext $tenantContext): int
    {
        $limite = Calendrier::deParis()->decaler(now(), CarbonInterval::days(-config('conservation.actions_traitees_en_jours')));
        $effaces = 0;
        $enEchec = 0;

        Magasin::query()->orderBy('id')->each(function (Magasin $magasin) use ($tenantContext, $limite, &$effaces, &$enEchec): void {
            try {
                $effaces += $tenantContext->runAs($magasin, function () use ($limite): int {
                    $effaces = 0;

                    do {
                        $lot = ActionTraitee::query()->where('created_at', '<', $limite)->orderBy('id')->limit(self::LOT)->pluck('id');

                        if ($lot->isNotEmpty()) {
                            $effaces += ActionTraitee::query()->whereKey($lot->all())->delete();
                        }
                    } while ($lot->count() === self::LOT);

                    return $effaces;
                });
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Log::info('Jetons des demandes traitées effacés.', ['nombre' => $effaces]);
        $this->info("{$effaces} jeton(s) effacé(s).");

        if ($enEchec > 0) {
            $this->error("{$enEchec} magasin(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
