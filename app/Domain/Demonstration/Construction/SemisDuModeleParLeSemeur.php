<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Demonstration\ConstructionDUnModele;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Le semis d'un modèle par le semeur du poste (db:seed), seul chemin du
 * semis sur le site de démonstration : le semeur ne sème que pendant une
 * construction en cours (ConstructionDUnModele), sur une base de travail
 * (A.9), jamais pour un db:seed lancé à la main.
 */
final class SemisDuModeleParLeSemeur implements SemisDUnModele
{
    public function semer(TypeDActivite $type, string $motDePasse): void
    {
        app()->instance(ConstructionDUnModele::class, new ConstructionDUnModele($type, $motDePasse));

        try {
            if (Artisan::call('db:seed', ['--force' => true]) !== 0) {
                throw new RuntimeException('Le semis du modèle « '.$type->value.' » a échoué : '.trim(Artisan::output()));
            }
        } finally {
            app()->forgetInstance(ConstructionDUnModele::class);
        }
    }
}
