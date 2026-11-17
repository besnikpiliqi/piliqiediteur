<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\TachesDesCopies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Le rattrapage des tâches d'une copie restée plus d'une heure sans passe,
 * demandé à l'entrée d'une visite (lot DEMO-COPIE, C.13) : sur la file
 * demonstration-admin (le registre ne s'écrit que par demo_admin), les
 * tâches du calendrier des magasins dans la copie (voir
 * TachesDesCopies::passer()), pendant que la visite voit « Nous mettons
 * votre démonstration à l'heure ». Un seul rattrapage à la fois par copie
 * (verrou du cache du registre, que le travail rend à sa fin).
 */
final class RattraperLesTachesDUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CLE_DU_VERROU = 'demonstration:rattrapage:';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $reference)
    {
        $this->onConnection('demonstration');
        $this->onQueue(CreerUneCopie::FILE);
    }

    /**
     * Demande le rattrapage de cette copie, une seule fois tant qu'il n'est
     * pas fini.
     */
    public static function demander(string $reference): void
    {
        if (Cache::store('registre')->add(self::CLE_DU_VERROU.$reference, true, 900)) {
            self::dispatch($reference);
        }
    }

    public function handle(TachesDesCopies $taches): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        try {
            $taches->passer($this->reference, false);
        } finally {
            Cache::store('registre')->forget(self::CLE_DU_VERROU.$this->reference);
        }
    }
}
