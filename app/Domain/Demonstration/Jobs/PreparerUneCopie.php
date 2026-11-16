<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * La préparation d'une copie déjà inscrite au registre (lot DEMO-COPIE,
 * C.3), sur la file demonstration-admin : quand une place se libère
 * (après une suppression, pour la demande qui l'attend en premier) et
 * pour reprendre, une heure plus tard, une copie en échec
 * (demonstration:reprendre-les-copies). Mêmes essais que CreerUneCopie.
 */
final class PreparerUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 900;

    public function __construct(public readonly string $reference)
    {
        $this->onConnection('demonstration');
        $this->onQueue(CreerUneCopie::FILE);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(CopiesDeLaDemonstration $copies): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $copies->preparer($this->reference);
    }

    public function failed(?Throwable $erreur): void
    {
        app(CopiesDeLaDemonstration::class)->marquerEnEchec(
            $this->reference,
            $erreur instanceof CopieImpossible ? $erreur->getMessage() : 'La création de la copie a échoué.',
        );
    }
}
