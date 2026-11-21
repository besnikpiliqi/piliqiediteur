<?php

namespace App\Domain\Cabinets\Jobs;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Quand un cabinet peut être devenu vérifié (le code de son courrier, son
 * inscription à l'Ordre vérifiée ou constatée, la correction de son SIREN)
 * ou qu'une société déclare ce SIREN, après le commit : si un cabinet
 * vérifié porte ce SIREN, chaque déclaration en attente de ce SIREN, par
 * identifiant croissant, reçoit sa propre tâche de rattachement (voir
 * RattacherUneDeclaration). Il n'écrit rien lui-même et ne lit hors de
 * toute société que le cabinet vérifié de ce SIREN et l'identifiant et la
 * société de ces déclarations. La tâche horaire rattrape un envoi perdu
 * (voir RattacherLesExpertsComptables).
 */
class RattacherLesDeclarationsDuSiren implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $siren)
    {
        $this->afterCommit();
    }

    public function handle(ExpertsComptablesDeclares $experts): void
    {
        $cabinet = $experts->cabinetVerifieDuSiren($this->siren);

        if ($cabinet === null) {
            return;
        }

        foreach (LienCabinet::declaresDuSiren($this->siren) as $declaration) {
            RattacherUneDeclaration::dispatch(CleDuModele::de($declaration), CleDuModele::de($cabinet));
        }
    }
}
