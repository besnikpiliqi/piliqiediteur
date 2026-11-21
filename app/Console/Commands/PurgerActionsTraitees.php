<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\ActionTraitee;
use App\Domain\Tenancy\Models\ActionTraiteeDeLaSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
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
 * démarrage), puis ceux des gestes de chaque société hors de ses
 * magasins (ses charges), sous sa société, par lots de 1 000. Rien d'autre ne s'efface : la table est
 * technique, sans valeur fiscale ; les documents et les registres restent.
 *
 * Le nombre de jetons effacés s'inscrit au journal de l'application. La
 * panne d'un magasin n'arrête pas les suivants : elle est signalée
 * (report()), et ses jetons s'effacent au passage suivant ; de même pour
 * une société.
 */
#[Signature('app:purger-actions-traitees')]
#[Description('Efface les jetons des demandes traitées depuis plus de 30 jours')]
class PurgerActionsTraitees extends Command
{
    public const LOT = 1000;

    public function handle(TenantContext $tenantContext): int
    {
        $limite = Calendrier::deParis()->decaler(now(), CarbonInterval::days(-Reglage::entier('conservation.actions_traitees_en_jours')));
        $effaces = 0;
        $enEchec = 0;

        Magasin::query()->with('societe')->orderBy('id')->each(function (Magasin $magasin) use ($tenantContext, $limite, &$effaces, &$enEchec): void {
            try {
                $effaces += $tenantContext->runAs($magasin, fn (): int => self::effacer(ActionTraitee::class, $limite));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Societe::query()->orderBy('id')->each(function (Societe $societe) use ($tenantContext, $limite, &$effaces, &$enEchec): void {
            try {
                $effaces += $tenantContext->runAsSociete($societe, fn (): int => self::effacer(ActionTraiteeDeLaSociete::class, $limite));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Log::info('Jetons des demandes traitées effacés.', ['nombre' => $effaces]);
        $this->info("{$effaces} jeton(s) effacé(s).");

        if ($enEchec > 0) {
            $this->error("{$enEchec} magasin(s) ou société(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Efface, par lots, les jetons de cette table, dans le magasin ou la
     * société du contexte (voir leur scope), inscrits avant cette limite.
     *
     * @param  class-string<ActionTraitee|ActionTraiteeDeLaSociete>  $modele
     */
    private static function effacer(string $modele, CarbonImmutable $limite): int
    {
        $effaces = 0;

        do {
            $lot = $modele::query()->where('created_at', '<', $limite)->orderBy('id')->limit(self::LOT)->pluck('id');

            if ($lot->isNotEmpty()) {
                $effaces += ValeursLues::entier($modele::query()->whereKey($lot->all())->delete());
            }
        } while ($lot->count() === self::LOT);

        return $effaces;
    }
}
