<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande planifiée chaque nuit (voir bootstrap/app.php) : efface les
 * données de vérification des sociétés dont la relation avec l'éditeur a
 * pris fin il y a cinq ans (Q4) : la fin de leur conservation, que la fin
 * de la relation a fixée à la société (voir FinDeLaRelation), est passée.
 * Pour chacune, sous sa société et dans une transaction : les lignes de
 * son registre des vérifications (voir VerificationSociete) et ses codes
 * de courrier recommandé (voir CodeConfirmation). Les entrées de la
 * vérification de son journal d'audit s'effacent par le début de leurs
 * chaînes (voir app:purger-journal-audit). Rien d'autre ne s'efface : la
 * base refuse la suppression de ces lignes avant cette date (voir
 * DeclencheursDeProtection), comparée à l'heure du serveur de base, la
 * seule que suivent ses déclencheurs ; une société dont la date n'est
 * passée qu'à l'heure de l'application attend le passage suivant.
 *
 * Le nombre de lignes effacées s'inscrit au journal de l'application. La
 * panne d'une société n'arrête pas les suivantes : elle est signalée
 * (report()), et reprise au passage suivant.
 */
#[Signature('app:purger-verifications')]
#[Description('Efface les données de vérification des sociétés cinq ans après la fin de la relation')]
class PurgerVerifications extends Command
{
    public function handle(TenantContext $tenantContext): int
    {
        $effacees = 0;
        $enEchec = 0;

        Societe::query()
            ->whereNotNull('verification_conservee_jusqu_au')
            ->where('verification_conservee_jusqu_au', '<=', now())
            ->whereRaw(DeclencheursDeProtection::conditionDExpiration('verification_conservee_jusqu_au'))
            ->orderBy('id')
            ->each(function (Societe $societe) use ($tenantContext, &$effacees, &$enEchec): void {
                try {
                    $effacees += $tenantContext->runAsSociete($societe, fn (): int => DB::transaction(fn (): int => VerificationSociete::query()
                        ->whereRaw(DeclencheursDeProtection::conditionDeFinDeLaRelation('verifications_societe'))
                        ->delete()
                        + CodeConfirmation::query()
                            ->whereRaw(DeclencheursDeProtection::conditionDExpiration('conserver_jusqu_au'))
                            ->delete()));
                } catch (Throwable $exception) {
                    report($exception);
                    $enEchec++;
                }
            });

        Log::info('Données de vérification effacées après la fin de la relation.', ['nombre' => $effacees]);
        $this->info("{$effacees} donnée(s) de vérification effacée(s).");

        if ($enEchec > 0) {
            $this->error("{$enEchec} société(s) en échec, reprise(s) au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
