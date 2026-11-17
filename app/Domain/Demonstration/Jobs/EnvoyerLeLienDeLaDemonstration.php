<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Mail\LienDeLaDemonstrationMail;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;
use Throwable;

/**
 * L'e-mail du lien de la démonstration (B.3, B.6, B.10), par un travail
 * chiffré : le lien n'est jamais écrit en clair dans la table jobs. Il
 * part seulement à l'adresse de connexion confirmée du propriétaire, au
 * moment de l'envoi : un compte qui n'est plus le propriétaire, ou qui n'a
 * plus d'adresse de connexion, ne reçoit rien.
 *
 * Trois tentatives (aussitôt, 30 secondes, 2 minutes après). Après la
 * dernière, le propriétaire est prévenu dans sa cloche, et la ligne que le
 * travailleur écrit dans failed_jobs est effacée aussitôt (voir failed()) :
 * aucune trace du travail ne reste, même chiffrée.
 */
final class EnvoyerLeLienDeLaDemonstration implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MESSAGE_NON_PARTI = 'Le lien de votre démonstration n\'a pas pu partir par e-mail. Renouvelez-le depuis votre compte.';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 45;

    public function __construct(
        public readonly int $societeId,
        public readonly int $compteId,
        #[SensitiveParameter] public readonly string $lien,
        public readonly string $repere,
        public readonly string $fin,
    ) {
        $this->onQueue(EnvoyerLesMessagesALaDemonstration::FILE);
    }

    public static function envoyer(User $proprietaire, Demonstration $demonstration, #[SensitiveParameter] string $lien): void
    {
        self::dispatch(
            (int) $demonstration->societe_id,
            CleDuModele::de($proprietaire),
            $lien,
            $demonstration->repere,
            Calendrier::deParis()->afficher($demonstration->fin_le, 'd/m/Y'),
        );
    }

    public function handle(): void
    {
        $compte = User::query()->find($this->compteId);

        if ($compte === null || (int) $compte->societe_id !== $this->societeId || ! $compte->estProprietaire() || ! $compte->estActif() || ! $compte->aUneAdresseDeConnexion()) {
            return;
        }

        Mail::to((string) $compte->email)->send(new LienDeLaDemonstrationMail($this->lien, $this->repere, $this->fin));
    }

    /**
     * Après la dernière tentative : le propriétaire est prévenu, et la
     * ligne de failed_jobs, que le travailleur écrit juste après cet
     * appel, est effacée dès qu'elle est écrite.
     */
    public function failed(?Throwable $erreur): void
    {
        $uuid = $this->job?->uuid();

        if ($uuid !== null) {
            Event::listen(JobFailed::class, static function (JobFailed $evenement) use ($uuid): void {
                if ($evenement->job->uuid() === $uuid) {
                    DB::table('failed_jobs')->where('uuid', $uuid)->delete();
                }
            });
        }

        $societe = Societe::query()->find($this->societeId);
        $compte = User::query()->find($this->compteId);

        if ($societe !== null && $compte !== null) {
            $compte->notify(new AvisDeLEquipe($societe, 'Démonstration', self::MESSAGE_NON_PARTI, nature: AvisDeLEquipe::ATTENTION));
        }
    }
}
