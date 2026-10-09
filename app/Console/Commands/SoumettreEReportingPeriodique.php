<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\GenererSoumissionEReportingAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Services\CalculateurPeriodeEReporting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Contrairement à la clôture de caisse (quotidienne), la transmission de
 * l'e-reporting au PDP n'est PAS journalière : elle se fait par lots sur
 * une période dont la durée dépend du régime de TVA du magasin (voir
 * CalculateurPeriodeEReporting). Cette commande tourne chaque jour mais
 * n'agrège et ne transmet réellement une période qu'une fois celle-ci
 * terminée -- la contrainte d'unicité sur (magasin_id, periode_debut,
 * periode_fin) rend les exécutions suivantes des no-op silencieux pour
 * cette période (voir GenererSoumissionEReportingAction).
 *
 * Un magasin sans régime de TVA configuré (voir ParametresFiscaux) est
 * simplement ignoré, même dégradation douce que l'absence de mandat PDP.
 * Un magasin suspendu ou fermé est déclaré comme les autres : ses
 * déclarations continuent jusqu'au bout, comme ses clôtures (voir
 * ClotureAutomatiqueCaisse). Seul un magasin supprimé n'est plus traité.
 */
#[Signature('app:soumettre-ereporting-periodique')]
#[Description("Génère et transmet au PDP les soumissions d'e-reporting des périodes venant de se terminer")]
class SoumettreEReportingPeriodique extends Command
{
    public function handle(
        TenantContext $tenantContext,
        CalculateurPeriodeEReporting $calculateur,
        GenererSoumissionEReportingAction $genererSoumission,
    ): int {
        $aujourdHui = Carbon::now()->toImmutable();
        $totalSoumissions = 0;

        Magasin::query()
            ->whereNotNull('regime_tva')
            ->each(function (Magasin $magasin) use ($tenantContext, $calculateur, $genererSoumission, $aujourdHui, &$totalSoumissions) {
                $tenantContext->runAs($magasin, function () use ($magasin, $calculateur, $genererSoumission, $aujourdHui, &$totalSoumissions) {
                    $periode = $calculateur->periodePrecedente($magasin->regime_tva, $aujourdHui);

                    if ($genererSoumission->handle($magasin, $periode) !== null) {
                        $totalSoumissions++;
                    }
                });
            });

        $this->info("{$totalSoumissions} soumission(s) d'e-reporting générée(s).");

        return self::SUCCESS;
    }
}
