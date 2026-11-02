<?php

namespace Tests\Browser\Support;

use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le noyau HTTP des tests en navigateur : le serveur du module browser de
 * Pest traite chaque requête du navigateur dans le processus du test, avec
 * la même application (voir Pest\Browser\Drivers\LaravelHttpServer), alors
 * qu'en production chaque requête part d'un processus neuf. Avant chaque
 * requête, ce noyau rend donc à l'application l'état d'un processus neuf :
 * - les instances scoped() (TenantContext, l'opérateur courant, la trace
 *   du visiteur, le verrou du registre fiscal, mais aussi le panneau et le
 *   magasin courants de Filament...), comme le fait TestCase::call() pour
 *   le magasin actif, et les instances que gardent les façades, qui sinon
 *   désigneraient encore celles de la requête précédente ;
 * - les gardes d'authentification, qui sinon garderaient le compte d'une
 *   requête précédente, même pour une autre session ;
 * - la session, relue de la base à chaque requête au lieu de garder en
 *   mémoire les clés d'une session précédente ;
 * - les témoins mis en file, qui sinon repartiraient avec chaque réponse ;
 * - les rappels du conteneur à la résolution d'un service, tels qu'au
 *   démarrage de l'application : chaque panneau de Filament qui démarre
 *   ajoute les siens (ses crochets de rendu, ses couleurs, ses fichiers),
 *   que chaque requête suivante rejouerait, si bien qu'une page de l'espace
 *   d'une société recevrait les crochets du panneau des magasins.
 * Le magasin actif de la préparation du test lui est rendu après la
 * réponse, pour ses vérifications.
 */
final class NoyauDUneRequeteNeuve implements Kernel
{
    private ?TenantContext $contexteDuTest = null;

    /**
     * Les rappels du conteneur à la résolution d'un service au démarrage
     * de l'application.
     *
     * @var array{0: array<string, list<Closure>>, 1: array<string, list<Closure>>}
     */
    private readonly array $rappelsDuDemarrage;

    public function __construct(private readonly Kernel $noyau)
    {
        $this->rappelsDuDemarrage = (fn (): array => [$this->resolvingCallbacks, $this->afterResolvingCallbacks])->call($noyau->getApplication());
    }

    public function bootstrap(): void
    {
        $this->noyau->bootstrap();
    }

    /**
     * @param  Request  $request
     */
    public function handle($request): Response
    {
        $application = $this->getApplication();
        $this->contexteDuTest = $application->make(TenantContext::class);
        $rappels = $this->rappelsDuDemarrage;
        (function () use ($rappels): void {
            [$this->resolvingCallbacks, $this->afterResolvingCallbacks] = $rappels;
        })->call($application);
        $application->forgetScopedInstances();
        Facade::clearResolvedInstances();
        $application->make('auth')->forgetGuards();
        $application->make('session')->forgetDrivers();
        $application->make('cookie')->flushQueuedCookies();

        return $this->noyau->handle($request);
    }

    /**
     * @param  Request  $request
     * @param  Response  $response
     */
    public function terminate($request, $response): void
    {
        $this->noyau->terminate($request, $response);

        if ($this->contexteDuTest !== null) {
            $this->getApplication()->instance(TenantContext::class, $this->contexteDuTest);
            $this->contexteDuTest = null;
        }
    }

    public function getApplication(): Application
    {
        return $this->noyau->getApplication();
    }
}
