<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La fin d'une démonstration (lot DEMO-COPIE, B.8, B.9 et B.13) : ses 30
 * jours passés (demonstration:marquer-les-fins), l'échec définitif de sa
 * création, la clôture de l'inscription de la société, sa révocation, ou
 * un nouveau propriétaire qui a saisi le code du courrier (tous les
 * comptes d'avant retirés). La démonstration vivante de la société passe
 * « terminée » (30 jours, échec) ou « supprimée » (les trois autres), le
 * message « supprimer » part au site de démonstration, qui archive la
 * caisse de la copie puis la supprime, et « Démonstration terminée »
 * s'inscrit au journal d'audit de la société, avec sa cause, au nom de qui
 * a fait le geste ou du système.
 *
 * Sans démonstration vivante, rien ne se fait : chaque appelant peut
 * l'appeler sans savoir s'il y en a une. Elle s'exécute dans la
 * transaction de son appelant quand il en a une, dans le contexte de la
 * société de la démonstration.
 */
final class TerminerLaDemonstrationAction
{
    public function __construct(
        private readonly BoiteDEnvoi $boite,
        private readonly JournalAudit $journalAudit,
        private readonly TenantContext $contexte,
    ) {}

    public function handle(Societe $societe, CauseDeLaFin $cause, ?User $auteur = null): void
    {
        $this->contexte->runAsSociete($societe, fn () => DB::transaction(function () use ($cause, $auteur): void {
            $vivantes = Demonstration::query()
                ->whereIn('etat', [...EtatDeLaDemonstration::valeursEnCours(), EtatDeLaDemonstration::EnEchec->value])
                ->lockForUpdate()
                ->get();

            foreach ($vivantes as $demonstration) {
                $demonstration->forceFill([
                    'etat' => $cause->supprimeAussitot() ? EtatDeLaDemonstration::Supprimee : EtatDeLaDemonstration::Terminee,
                    'terminee_le' => CarbonImmutable::now(),
                    'cause_de_la_fin' => $cause,
                ])->save();
                $this->boite->supprimer($demonstration);
                $this->journalAudit->inscrire(ActionAuditee::DemonstrationTerminee, $auteur, null, null, [
                    'repere' => $demonstration->repere,
                    'cause' => $cause->libelle(),
                ], $demonstration);
            }
        }));
    }
}
