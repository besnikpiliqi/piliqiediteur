<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\TachesDesCopies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sur le site de démonstration : les tâches du calendrier des magasins dans
 * une copie, dans l'ordre (voir TachesDesCopies::passer()), lancée dans un
 * processus à part par demonstration:taches-des-copies, ou par le
 * rattrapage de l'entrée dans une copie restée sans passe (lot DEMO-COPIE,
 * C.13). Une référence hors du motif, une copie inconnue, échue ou qui ne
 * se sert pas : rien ne passe.
 */
#[Signature('demonstration:taches-d-une-copie {reference : La référence de la copie au registre} {--nuit : Avec la vérification incrémentale de son registre fiscal, si elle a été visitée depuis}')]
#[Description('Passe les tâches du calendrier des magasins dans une copie de démonstration.')]
class PasserLesTachesDUneCopie extends Command
{
    public function handle(TachesDesCopies $taches): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $reference = (string) $this->argument('reference');

        if (preg_match('/^[0-9a-f]{32}$/', $reference) !== 1) {
            $this->error('La référence d\'une copie compte 32 caractères hexadécimaux.');

            return self::FAILURE;
        }

        $echecs = $taches->passer($reference, (bool) $this->option('nuit'));

        foreach ($echecs as $echec) {
            $this->error($echec);
        }

        return $echecs === [] ? self::SUCCESS : self::FAILURE;
    }
}
