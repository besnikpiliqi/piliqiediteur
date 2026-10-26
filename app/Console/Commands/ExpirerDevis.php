<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\ExpirerLesDevisAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Commande planifiée toutes les heures (voir bootstrap/app.php) qui passe
 * à « Expiré » les devis envoyés dont la date de validité est dépassée au
 * jour de leur magasin (voir
 * ExpirerLesDevisAction), magasin par magasin : une commande n'a aucun
 * TenantContext au démarrage. Un magasin suspendu ou fermé est traité
 * comme les autres : les statuts de ses documents continuent de suivre
 * leurs dates. Seul un magasin supprimé n'est plus traité.
 *
 * La panne d'un magasin n'arrête pas les suivants : elle est signalée au
 * journal de l'application (report()), et ses devis expirent au passage
 * suivant.
 */
#[Signature('app:expirer-devis')]
#[Description('Passe à « Expiré » les devis envoyés dont la date de validité est dépassée')]
class ExpirerDevis extends Command
{
    public function handle(TenantContext $tenantContext, ExpirerLesDevisAction $expirerLesDevis): int
    {
        $expires = 0;
        $magasinsEnEchec = 0;

        Magasin::query()->each(function (Magasin $magasin) use ($tenantContext, $expirerLesDevis, &$expires, &$magasinsEnEchec): void {
            try {
                $expires += $tenantContext->runAs($magasin, fn (): int => $expirerLesDevis->handle($magasin));
            } catch (Throwable $exception) {
                report($exception);
                $magasinsEnEchec++;
            }
        });

        $this->info("{$expires} devis expiré(s).");

        if ($magasinsEnEchec > 0) {
            $this->error("{$magasinsEnEchec} magasin(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
