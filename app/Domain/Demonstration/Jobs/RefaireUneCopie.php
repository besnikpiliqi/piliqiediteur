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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La réfection d'une copie depuis le modèle du jour, sur la file
 * demonstration-admin (voir FinDesCopies::refaire()) : « Recommencer à
 * zéro » (C.7), mise à jour avant la mise en production (E.4), migration
 * ratée (E.5) ou copie perdue (E.7) ; archive de sa caisse quand sa base
 * existe, base supprimée, nouvelle copie, même référence, même lien, même
 * fin, avec le message de son motif. Un échec réessaie 30 secondes, 2
 * minutes puis 10 minutes plus tard ; après le dernier, une copie dont la
 * base a déjà été supprimée passe « en échec », reprise une heure plus
 * tard ; une copie dont la base existe encore (son archive a échoué) reste
 * « à refaire », et sa réfection entière, archive comprise, reprend une
 * heure plus tard (voir CopiesDeLaDemonstration::marquerEnEchec()).
 */
final class RefaireUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CLE_DE_LA_COPIE_PERDUE = 'demonstration:copie-perdue:';

    public int $tries = 4;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $reference,
        public readonly string $motif = FinDesCopies::MOTIF_REMISE_A_ZERO,
        public readonly bool $archiveFaite = false,
    ) {
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

    /**
     * La réfection d'une copie dont la base n'existe plus, trouvée à une
     * visite (voir OuvrirLaCopie) : demandée une seule fois tant qu'elle
     * n'est pas finie (verrou du cache du registre, que le travail rend à
     * sa fin) ; l'événement s'écrit au journal du site. Le rôle des pages
     * ne fait qu'envoyer le travail : demo_admin seul écrit au registre et
     * refait la copie.
     */
    public static function demanderPourUneCopiePerdue(string $reference): void
    {
        if (Cache::store('registre')->add(self::CLE_DE_LA_COPIE_PERDUE.$reference, true, 1800)) {
            Log::warning('Une copie de la démonstration n\'a plus de base : elle est refaite depuis le modèle de son type (E.7).', ['reference' => $reference]);
            self::dispatch($reference, FinDesCopies::MOTIF_PERDUE);
        }
    }

    public function handle(FinDesCopies $fin): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        try {
            $fin->refaire($this->reference, $this->motif, $this->archiveFaite);
        } finally {
            if ($this->motif === FinDesCopies::MOTIF_PERDUE) {
                Cache::store('registre')->forget(self::CLE_DE_LA_COPIE_PERDUE.$this->reference);
            }
        }
    }

    public function failed(?Throwable $erreur): void
    {
        app(CopiesDeLaDemonstration::class)->marquerEnEchec(
            $this->reference,
            $erreur instanceof CopieImpossible || $erreur instanceof ArchiveImpossible ? $erreur->getMessage() : 'La réfection de la copie a échoué.',
        );
    }
}
