<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * La suppression d'une copie (lot DEMO-COPIE, C.7 et C.14), sur la file
 * demonstration-admin : archive de sa caisse, relue, puis seulement
 * DROP DATABASE (voir FinDesCopies::supprimer()). Une archive qui échoue
 * réessaie 2 minutes, puis 10 minutes plus tard ; la copie reste « en
 * archivage » avec sa cause, que l'état publié montre.
 */
final class ArchiverPuisSupprimerUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public readonly string $reference, public readonly string $motif)
    {
        $this->onConnection('demonstration');
        $this->onQueue(CreerUneCopie::FILE);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(FinDesCopies $fin): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $fin->supprimer($this->reference, $this->motif);
    }
}
