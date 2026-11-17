<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DemandeDeCopie;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * La création d'une copie (lot DEMO-COPIE, C.3), sur la file
 * demonstration-admin, que seul le travailleur de demo_admin traite :
 * envoyé par les pages à la réception du message « creer », après qu'elles
 * ont inscrit la demande au registre (voir
 * CopiesDeLaDemonstration::inscrire()), il la retrouve inscrite (ou
 * l'inscrit, une seule fois par référence) puis crée la copie. Un échec réessaie
 * 30 secondes, 2 minutes puis 10 minutes plus tard, puis la copie passe
 * « en échec », reprise une heure plus tard.
 */
final class CreerUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FILE = 'demonstration-admin';

    public int $tries = 4;

    public int $timeout = 900;

    /**
     * @param  array{reference: string, repere: string, type: string, validee: bool, empreinte_du_lien: string, generation: int, fin: string}  $demande
     */
    public function __construct(public readonly array $demande)
    {
        $this->onConnection('demonstration');
        $this->onQueue(self::FILE);
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
        $copies->inscrire(DemandeDeCopie::lire($this->demande), 'demo_admin');
        $copies->preparer($this->demande['reference']);
    }

    public function failed(?Throwable $erreur): void
    {
        app(CopiesDeLaDemonstration::class)->marquerEnEchec(
            $this->demande['reference'],
            $erreur instanceof CopieImpossible ? $erreur->getMessage() : 'La création de la copie a échoué.',
        );
    }
}
