<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
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
 * personnelles des vendeurs : une journée est toujours clôturée, même si
 * personne n'intervient.
 *
 * Puis elle ferme d'office toute caisse personnelle restée ouverte
 * jusqu'à la veille, jusqu'à la fin de la veille (voir
 * FermerLesCaissesPersonnellesAction), même quand le magasin a clôturé sa
 * journée lui-même : une vente faite après minuit reste pour la fermeture
 * suivante. Une caisse qu'un passage n'a pas pu fermer l'est au suivant.
 *
 * Elle traite tous les magasins, ceux des sociétés suspendues ou
 * résiliées et les magasins suspendus compris : leurs clôtures continuent
 * jusqu'au bout. Un magasin fermé n'a plus de journée à clôturer après le
 * jour de sa fermeture, ni pendant ses jours de fermeture (voir
 * CloturerLesJourneesAction et ses périodes d'activité). La
 * panne d'un magasin n'arrête pas les suivants : elle est signalée au
 * journal de l'application (report()), et ce magasin reprend à sa journée
 * en échec au passage suivant ; elle n'empêche pas non plus la fermeture
 * de ses caisses personnelles.
 */
#[Signature('app:cloturer-caisses-automatiquement')]
#[Description("Clôture, jusqu'à la veille, les journées de caisse que les magasins n'ont pas clôturées eux-mêmes, puis ferme les caisses personnelles restées ouvertes")]
class ClotureAutomatiqueCaisse extends Command
{
    public function handle(TenantContext $tenantContext, CloturerLesJourneesAction $cloturerLesJournees, FermerLesCaissesPersonnellesAction $fermerLesCaisses): int
    {
        $hier = CarbonImmutable::now()->subDay();
        $finDHier = $hier->endOfDay();
        $clotures = 0;
        $caissesFermees = 0;
        $magasinsEnEchec = 0;

        Magasin::query()->orderBy('id')->each(function (Magasin $magasin) use ($tenantContext, $cloturerLesJournees, $fermerLesCaisses, $hier, $finDHier, &$clotures, &$caissesFermees, &$magasinsEnEchec): void {
            $tenantContext->runAs($magasin, function () use ($magasin, $cloturerLesJournees, $fermerLesCaisses, $hier, $finDHier, &$clotures, &$caissesFermees, &$magasinsEnEchec): void {
                $avant = ClotureCaisse::query()->count();
                $enEchec = false;

                try {
                    $cloturerLesJournees->jusquAu($magasin, $hier);
                } catch (Throwable $exception) {
                    report($exception);
                    $enEchec = true;
                } finally {
                    $clotures += ClotureCaisse::query()->count() - $avant;
                }

                try {
                    $caissesFermees += $fermerLesCaisses->handle($magasin, $finDHier)->count();
                } catch (Throwable $exception) {
                    report($exception);
                    $enEchec = true;
                }

                $magasinsEnEchec += (int) $enEchec;
            });
        });

        $this->info("{$clotures} clôture(s) automatique(s) effectuée(s) jusqu'au {$hier->format('d/m/Y')}.");
        $this->info("{$caissesFermees} caisse(s) personnelle(s) fermée(s) automatiquement.");

        if ($magasinsEnEchec > 0) {
            $this->error("{$magasinsEnEchec} magasin(s) en échec, repris au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
