<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Exceptions\MessagesNonSignables;
use App\Domain\Demonstration\Exceptions\SiteDeDemonstrationInjoignable;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
use App\Domain\Demonstration\Services\EtatsDeLaDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Le travail d'envoi des messages d'une démonstration (B.13), sur la file
 * « demonstration » : il lit les messages non accusés de cette seule
 * démonstration, un à un dans leur ordre, jusqu'au dernier, même déposé
 * pendant qu'il envoie (les démonstrations ne s'attendent pas entre
 * elles), les signe au moment de l'envoi (voir MessagesSignes) et les
 * envoie au site de démonstration ; il tire de chaque réponse l'état de
 * la carte (voir EtatsDeLaDemonstration).
 *
 * Deux travaux de la même démonstration ne se chevauchent pas : celui qui
 * trouve l'envoi déjà en cours s'arrête aussitôt, sans échec ni nouvelle
 * tentative, puisque celui en cours lit jusqu'au dernier message ; un
 * message déposé juste après sa dernière lecture est repris par
 * demonstration:relancer-les-messages (voir MINUTE_SANS_ENVOI).
 *
 * Un message que le site de démonstration ne reçoit pas (coupure, réponse
 * hors du protocole, 409 « mise à jour en cours », clé non réglée) arrête
 * l'envoi de cette démonstration, pour garder l'ordre, et repart 30
 * secondes, 2 minutes, puis 10 minutes plus tard ; ensuite toutes les 5
 * minutes, par demonstration:relancer-les-messages.
 *
 * Sans site de démonstration réglé (poste de développement), les
 * messages attendent dans la boîte, sans tentative comptée.
 *
 * Il tourne hors de tout contexte de société : il lit la société de la
 * démonstration par son identifiant, puis lit et écrit dans son contexte,
 * avec un filtre explicite par société et par démonstration.
 */
final class EnvoyerLesMessagesALaDemonstration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FILE = 'demonstration';

    /**
     * Les délais des trois premières reprises, en secondes ; ensuite la
     * tâche des relances, toutes les 5 minutes.
     */
    public const REPRISES = [30, 120, 600];

    public const INTERVALLE_DES_RELANCES = 300;

    /**
     * Au-delà, un message jamais essayé (son travail a trouvé l'envoi de sa
     * démonstration déjà en cours) est repris par la tâche des relances.
     */
    public const MINUTE_SANS_ENVOI = 60;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $demonstrationId,
        public readonly int $societeId,
    ) {
        $this->onQueue(self::FILE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('demonstration-envoi:'.$this->demonstrationId))->dontRelease()->expireAfter(120)];
    }

    public function handle(SiteDeDemonstration $site, MessagesSignes $signataire, EtatsDeLaDemonstration $etats, TenantContext $contexte): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);
        $societe = Societe::query()->find($this->societeId);

        if ($societe === null || ! $site->estLance()) {
            return;
        }

        $contexte->runAsSociete($societe, function () use ($site, $signataire, $etats): void {
            $dernier = 0;

            while (($message = $this->suivant($dernier)) !== null) {
                $dernier = CleDuModele::de($message);

                if ($message->prochain_essai_le?->isFuture() === true) {
                    return;
                }

                try {
                    $reponse = $site->envoyer($signataire->signerUnMessage($message->contenu));
                } catch (SiteDeDemonstrationInjoignable|MessagesNonSignables $erreur) {
                    $this->reporter($message, $erreur->getMessage());

                    return;
                }

                if (! $reponse->estDefinitive()) {
                    $this->reporter($message, 'Le site de démonstration est en cours de mise à jour (409).');

                    return;
                }

                DB::transaction(function () use ($message, $reponse, $etats): void {
                    $demonstration = Demonstration::query()
                        ->where('societe_id', $this->societeId)
                        ->whereKey($this->demonstrationId)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $message->forceFill([
                        'envoye_le' => CarbonImmutable::now(),
                        'accuse_le' => CarbonImmutable::now(),
                        'code_de_la_reponse' => $reponse->code,
                        'tentatives' => $message->tentatives + 1,
                        'prochain_essai_le' => null,
                        'derniere_erreur' => $reponse->code === 422 ? ($reponse->cause ?? 'Message refusé par le site de démonstration.') : null,
                    ])->save();
                    $etats->appliquer($demonstration, $message->quoi, $reponse);
                });
            }
        });
    }

    /**
     * Le premier message non accusé de cette démonstration après celui-ci,
     * relu à chaque tour : un message déposé pendant l'envoi part aussi.
     */
    private function suivant(int $apres): ?MessageVersLaDemonstration
    {
        return MessageVersLaDemonstration::query()
            ->where('societe_id', $this->societeId)
            ->where('demonstration_id', $this->demonstrationId)
            ->whereNull('accuse_le')
            ->where('id', '>', $apres)
            ->orderBy('id')
            ->first(['id', 'societe_id', 'demonstration_id', 'quoi', 'contenu', 'tentatives', 'prochain_essai_le']);
    }

    /**
     * Le message n'a pas été reçu : il repart plus tard, avec sa cause en
     * clair, sans son contenu.
     */
    private function reporter(MessageVersLaDemonstration $message, string $cause): void
    {
        $tentatives = $message->tentatives + 1;
        $delai = self::REPRISES[$tentatives - 1] ?? self::INTERVALLE_DES_RELANCES;
        $message->forceFill([
            'envoye_le' => CarbonImmutable::now(),
            'tentatives' => $tentatives,
            'prochain_essai_le' => CarbonImmutable::now()->addSeconds($delai),
            'derniere_erreur' => mb_substr($cause, 0, 255),
        ])->save();

        if ($tentatives <= count(self::REPRISES)) {
            self::dispatch($this->demonstrationId, $this->societeId)->delay($delai);
        }
    }
}
