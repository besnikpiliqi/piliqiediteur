<?php

namespace App\Providers;

use App\Domain\Demonstration\Courriels\SocieteDuCourriel;
use App\Domain\Demonstration\Exceptions\CopieEnLectureSeule;
use App\Domain\Demonstration\Listeners\AjouterLeCompteAuBandeau;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Demonstration\Taches\TravauxDesCopies;
use App\Domain\Demonstration\Visites\BandeauDeLaDemonstration;
use App\Domain\Demonstration\Visites\SessionsSansTrace;
use App\Domain\Tenancy\Events\CompteCree;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Ce qui ne se branche que sur le site de démonstration (lot DEMO-COPIE,
 * C.8 et C.9) ; sur le site réel, rien.
 *
 * - Les e-mails qui seraient partis : l'e-mail d'une notification se range
 *   dans la société du compte qui la reçoit (voir SocieteDuCourriel et
 *   TransportDeLaDemonstration).
 * - Le bandeau « Vous êtes : », en haut de chaque page des trois panneaux
 *   d'une copie (voir BandeauDeLaDemonstration), où chaque compte créé
 *   dans la copie entre aussitôt (voir AjouterLeCompteAuBandeau).
 * - Ni adresse IP ni navigateur dans les sessions d'une copie (C.17, voir
 *   SessionsSansTrace) ni dans les traces des journaux (TraceDuVisiteur).
 * - Les travaux d'une copie s'exécutent dans leur copie (C.13, voir
 *   TravauxDesCopies).
 * - Une copie en lecture seule, au-delà de sa place (C.16), refuse toute
 *   écriture d'un modèle de sa base (CopieEnLectureSeule, que la page dit
 *   en français) ; la consultation reste, comme le registre des copies.
 */
final class SiteDeDemonstrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocieteDuCourriel::class);
        $this->app->singleton(TravauxDesCopies::class);
    }

    public function boot(): void
    {
        if (! SiteDePiliqi::estLaDemonstration()) {
            return;
        }

        Event::listen(NotificationSending::class, static function (NotificationSending $envoi): void {
            if ($envoi->channel === 'mail' && $envoi->notifiable instanceof User) {
                app(SocieteDuCourriel::class)->poser($envoi->notifiable->societe_id);
            }
        });
        Event::listen([NotificationSent::class, NotificationFailed::class], static function (): void {
            app(SocieteDuCourriel::class)->oublier();
        });
        Event::listen(CompteCree::class, AjouterLeCompteAuBandeau::class);
        $this->app->resolving('session', static function (SessionManager $sessions, Application $application): void {
            $sessions->extend('database', fn (): SessionsSansTrace => new SessionsSansTrace(
                $application->make('db')->connection(ValeurSimple::enTexteOuNul(config('session.connection'))),
                ValeurSimple::enTexte(config('session.table')),
                ValeurSimple::enEntier(config('session.lifetime')),
                $application,
            ));
        });
        Queue::createPayloadUsing(static fn (): array => TravauxDesCopies::chargeUtile());
        Event::listen(JobProcessing::class, static fn (JobProcessing $evenement) => app(TravauxDesCopies::class)->auDebut($evenement));
        Event::listen([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class], static fn () => app(TravauxDesCopies::class)->fermer());
        Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], static function (string $evenement, array $modeles): void {
            $modele = $modeles[0] ?? null;

            if ($modele instanceof Model && $modele->getConnection()->getName() === DB::getDefaultConnection() && PlaceDesCopies::refuseLesEcritures()) {
                throw new CopieEnLectureSeule;
            }
        });
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, static function (): string|View {
            $compte = Auth::guard('web')->user();
            $bandeau = BandeauDeLaDemonstration::enCours($compte instanceof User ? $compte : null);

            return $bandeau === null ? '' : view('demonstration.bandeau', ['bandeau' => $bandeau]);
        });
    }
}
