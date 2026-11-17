<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * « Recommencer à zéro » d'une copie (lot DEMO-COPIE, C.7), sur la file
 * demonstration-admin : archive de sa caisse, base supprimée, nouvelle
 * copie du modèle du jour, même référence, même lien, même fin (voir
 * FinDesCopies::recommencer()). Un échec réessaie 30 secondes, 2 minutes
 * puis 10 minutes plus tard ; après le dernier, une copie dont la base a
 * déjà été supprimée passe « en échec », reprise une heure plus tard ; une
 * copie dont la base existe encore (son archive a échoué) reste « à
 * refaire », et sa remise à zéro entière, archive comprise, reprend une
 * heure plus tard (voir CopiesDeLaDemonstration::marquerEnEchec()).
 */
final class RecommencerUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 1800;

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

    public function handle(FinDesCopies $fin): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $fin->recommencer($this->reference);
    }

    public function failed(?Throwable $erreur): void
    {
        app(CopiesDeLaDemonstration::class)->marquerEnEchec(
            $this->reference,
            $erreur instanceof CopieImpossible || $erreur instanceof ArchiveImpossible ? $erreur->getMessage() : 'La remise à zéro de la copie a échoué.',
        );
    }
}
