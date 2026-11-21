<?php

namespace App\Domain\Cabinets\Jobs;

use App\Domain\Cabinets\Actions\RattacherUneDeclarationAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Le rattachement d'une déclaration d'expert-comptable de la société au
 * cabinet vérifié de son SIREN, au nom du système (voir
 * RattacherUneDeclarationAction), dans sa propre tâche et sa propre
 * transaction, après le commit du geste qui l'a demandé : l'échec d'une
 * société n'arrête aucune autre. Une seule tâche en attente par
 * déclaration ; rejouée, elle ne change rien à une déclaration déjà
 * rattachée ou terminée. 45 secondes restent sous les 60 de queue:listen
 * et sous le retry_after de la connexion database.
 */
class RattacherUneDeclaration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public int $timeout = 45;

    public function __construct(
        public readonly int $declarationId,
        public readonly int $cabinetId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->declarationId;
    }

    public function handle(RattacherUneDeclarationAction $rattachement): void
    {
        $rattachement->handle($this->declarationId, $this->cabinetId);
    }
}
