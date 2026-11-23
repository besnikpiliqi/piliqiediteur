<?php

namespace App\Console\Commands;

use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Toutes les heures, rattrape tout rattachement manqué de la déclaration
 * d'un expert-comptable de la société au cabinet vérifié de son SIREN (une
 * tâche perdue, un chemin qui rend un cabinet vérifié sans la demander) :
 * pour chaque SIREN déclaré en attente dont un cabinet est vérifié, le
 * rattachement de ses déclarations part (voir
 * RattacherLesDeclarationsDuSiren) ; au plus une heure sépare ainsi un
 * cabinet vérifié du rattachement d'une déclaration rattachable de son
 * SIREN. Une déclaration dont aucune personne n'est active est relue sans
 * rien changer. Ne lit, hors de toute société, que ces SIREN.
 */
#[Signature('app:rattacher-experts-comptables')]
#[Description('Rattache aux cabinets vérifiés de leur SIREN les déclarations d\'expert-comptable en attente')]
class RattacherLesExpertsComptables extends Command
{
    public function handle(ExpertsComptablesDeclares $experts): int
    {
        $sirens = $experts->sirensAvecUnCabinetVerifie();

        foreach ($sirens as $siren) {
            RattacherLesDeclarationsDuSiren::dispatch($siren);
        }

        $this->info(count($sirens).' SIREN de cabinet vérifié avec une déclaration en attente.');

        return self::SUCCESS;
    }
}
