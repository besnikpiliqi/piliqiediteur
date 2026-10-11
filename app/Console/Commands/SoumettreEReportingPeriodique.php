<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\GenererSoumissionEReportingAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Services\CalculateurPeriodeEReporting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Contrairement à la clôture de caisse (quotidienne), la transmission de
 * l'e-reporting au PDP n'est PAS journalière : elle se fait par lots sur
 * une période dont la durée dépend du régime de TVA de la société, qui
 * est l'assujettie (voir CalculateurPeriodeEReporting). Cette commande tourne chaque jour mais
 * n'agrège et ne transmet réellement une période qu'une fois celle-ci
 * terminée -- la contrainte d'unicité sur (magasin_id, periode_debut,
 * periode_fin) rend les exécutions suivantes des no-op silencieux pour
 * cette période (voir GenererSoumissionEReportingAction).
 *
 * Seuls les magasins réels des sociétés validées sont déclarés : une
 * société en cours de vérification n'a rien vendu, et le magasin de
 * démonstration ne transmet jamais rien. Une société sans régime de TVA
 * configuré (voir ParametresFiscauxSociete) est simplement ignorée, même
 * dégradation douce que l'absence de mandat PDP. Un magasin suspendu ou
 * fermé, ou celui d'une société suspendue ou résiliée, est déclaré comme
 * les autres : ses déclarations continuent jusqu'au bout, comme ses
 * clôtures (voir ClotureAutomatiqueCaisse).
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
            ->with('societe')
            ->where('est_demonstration', false)
            ->whereHas('societe', fn (Builder $societes) => $societes->whereNotNull('validee_le')->whereNotNull('regime_tva'))
            ->orderBy('id')
            ->each(function (Magasin $magasin) use ($tenantContext, $calculateur, $genererSoumission, $aujourdHui, &$totalSoumissions) {
                $tenantContext->runAs($magasin, function () use ($magasin, $calculateur, $genererSoumission, $aujourdHui, &$totalSoumissions) {
                    $periode = $calculateur->periodePrecedente($magasin->societe->regime_tva, $aujourdHui);

                    if ($genererSoumission->handle($magasin, $periode) !== null) {
                        $totalSoumissions++;
                    }
                });
            });

        $this->info("{$totalSoumissions} soumission(s) d'e-reporting générée(s).");

        return self::SUCCESS;
    }
}
