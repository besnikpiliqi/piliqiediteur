<?php

namespace App\Domain\Cabinets\Listeners;

use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Tenancy\Events\SocieteValidee;

/**
 * Un cabinet d'expertise comptable validé par le code de son courrier peut
 * devenir, avec cette validation, le cabinet vérifié du SIREN que des
 * sociétés ont déclaré pour leur expert-comptable : le rattachement de
 * leurs déclarations part, après le commit (voir
 * RattacherLesDeclarationsDuSiren). Rien pour une autre société.
 */
class RattacherLesDeclarationsDuCabinetValide
{
    public function handle(SocieteValidee $evenement): void
    {
        if ($evenement->societe->estUnCabinet()) {
            RattacherLesDeclarationsDuSiren::dispatch($evenement->societe->siren);
        }
    }
}
