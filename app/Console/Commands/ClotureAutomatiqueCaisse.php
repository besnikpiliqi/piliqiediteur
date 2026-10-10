<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Filet de sécurité réglementaire : un magasin qui ne clôture pas sa
 * journée lui-même (bouton « Clôturer la journée », voir
 * ListClotureCaisses) ne doit jamais laisser une journée de ventes aux
 * particuliers sans clôture ni e-reporting. Cette commande, planifiée à
 * minuit, heure de Paris (voir bootstrap/app.php, avant le marquage des
 * factures en retard à 00:15), clôture pour chaque magasin, dans l'ordre,
 * toutes ses journées encore ouvertes jusqu'à la VEILLE comprise (voir
 * CloturerLesJourneesAction) : celle qui vient de finir, et toute journée
 * qu'un passage précédent a manquée (serveur arrêté, panne). Jamais le
 * jour même, qui commence à peine. Elle n'attend pas les caisses
 * personnelles des vendeurs, que seule la clôture manuelle attend (voir
 * VerificateurCaissesPersonnelles) : une journée est toujours clôturée,
 * même si personne n'intervient.
 *
 * Un magasin suspendu ou fermé est clôturé comme les autres : ses clôtures
 * continuent jusqu'au bout. Seul un magasin supprimé n'est plus traité. La
 * panne d'un magasin n'arrête pas les suivants : elle est signalée au
 * journal de l'application (report()), et ce magasin reprend à sa journée
 * en échec au passage suivant.
 */
#[Signature('app:cloturer-caisses-automatiquement')]
#[Description("Clôture, jusqu'à la veille, les journées de caisse que les magasins n'ont pas clôturées eux-mêmes")]
class ClotureAutomatiqueCaisse extends Command
{
    public function handle(TenantContext $tenantContext, CloturerLesJourneesAction $cloturerLesJournees): int
    {
        $hier = now()->subDay();
        $clotures = 0;
        $magasinsEnEchec = 0;

        Magasin::query()->each(function (Magasin $magasin) use ($tenantContext, $cloturerLesJournees, $hier, &$clotures, &$magasinsEnEchec): void {
            $tenantContext->runAs($magasin, function () use ($magasin, $cloturerLesJournees, $hier, &$clotures, &$magasinsEnEchec): void {
                $avant = ClotureCaisse::query()->count();

                try {
                    $cloturerLesJournees->jusquAu($magasin, $hier);
                } catch (Throwable $exception) {
                    report($exception);
                    $magasinsEnEchec++;
                } finally {
                    $clotures += ClotureCaisse::query()->count() - $avant;
                }
            });
        });

        $this->info("{$clotures} clôture(s) automatique(s) effectuée(s) jusqu'au {$hier->format('d/m/Y')}.");

        if ($magasinsEnEchec > 0) {
            $this->error("{$magasinsEnEchec} magasin(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
