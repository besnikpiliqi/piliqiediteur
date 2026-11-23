<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\TachesDesCopies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Sur le site de démonstration, chaque heure (et la nuit avec --nuit) :
 * les tâches du calendrier des magasins de chaque copie à passer (voir
 * TachesDesCopies::aPasser()), chacune dans un processus à part
 * (demonstration:taches-d-une-copie), quatre à la fois au plus, dix minutes
 * au plus chacun (lot DEMO-COPIE, C.13). Un processus qui échoue ou dépasse
 * son délai n'arrête pas les autres ; la commande le dit et sort en échec.
 */
#[Signature('demonstration:taches-des-copies {--nuit : Toutes les copies servies, avec la vérification de leur registre fiscal}')]
#[Description('Passe, dans chaque copie de démonstration à passer, les tâches du calendrier des magasins.')]
class PasserLesTachesDesCopies extends Command
{
    public const EN_MEME_TEMPS = 4;

    public const DELAI_D_UNE_COPIE = 600;

    public function handle(TachesDesCopies $taches): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $nuit = (bool) $this->option('nuit');
        $aPasser = $taches->aPasser($nuit);
        $enCours = [];
        $echecs = 0;

        while ($aPasser !== [] || $enCours !== []) {
            while ($aPasser !== [] && count($enCours) < self::EN_MEME_TEMPS) {
                $reference = array_shift($aPasser);
                $processus = new Process([PHP_BINARY, base_path('artisan'), 'demonstration:taches-d-une-copie', $reference, ...($nuit ? ['--nuit'] : [])], base_path(), null, null, self::DELAI_D_UNE_COPIE);
                $processus->start();
                $enCours[$reference] = $processus;
            }

            foreach ($enCours as $reference => $processus) {
                try {
                    $processus->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $this->error("Les tâches de la copie {$reference} ont dépassé dix minutes : arrêtées.");
                    $echecs++;
                    unset($enCours[$reference]);

                    continue;
                }

                if (! $processus->isRunning()) {
                    if (! $processus->isSuccessful()) {
                        $this->error("Les tâches de la copie {$reference} ont échoué : ".trim($processus->getErrorOutput().$processus->getOutput()));
                        $echecs++;
                    }

                    unset($enCours[$reference]);
                }
            }

            usleep(200_000);
        }

        $this->info('Tâches passées dans les copies de démonstration'.($echecs > 0 ? ", {$echecs} en échec." : '.'));

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }
}
