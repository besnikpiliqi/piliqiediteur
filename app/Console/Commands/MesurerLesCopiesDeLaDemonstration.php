<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sur le site de démonstration, chaque heure : la place de chaque copie
 * servie (voir PlaceDesCopies), qui passe en lecture seule au-delà de la
 * sienne (lot DEMO-COPIE, C.16).
 */
#[Signature('demonstration:mesurer-les-copies')]
#[Description('Mesure la place de chaque copie de démonstration et met en lecture seule celles qui dépassent la leur.')]
class MesurerLesCopiesDeLaDemonstration extends Command
{
    public function handle(PlaceDesCopies $place): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $resultat = $place->mesurer();
        $this->info("{$resultat['mesurees']} copie(s) mesurée(s), ".count($resultat['en_lecture_seule']).' en lecture seule.');

        return self::SUCCESS;
    }
}
