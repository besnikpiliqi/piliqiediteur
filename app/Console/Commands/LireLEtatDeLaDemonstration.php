<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Etat\LectureDeLEtat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sur le site réel, chaque heure : lit l'état publié du site de
 * démonstration et prévient l'éditeur de chaque problème nouveau (lot
 * DEMO-COPIE, C.19, voir LectureDeLEtat).
 */
#[Signature('demonstration:lire-l-etat')]
#[Description('Lit l\'état publié du site de démonstration et prévient l\'éditeur de chaque problème nouveau')]
class LireLEtatDeLaDemonstration extends Command
{
    public function handle(LectureDeLEtat $lecture): int
    {
        $problemes = $lecture->lire();

        foreach ($problemes as $probleme) {
            $this->warn($probleme);
        }

        if ($problemes === []) {
            $this->info('Le site de démonstration ne signale aucun problème.');
        }

        return self::SUCCESS;
    }
}
