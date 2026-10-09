<?php

namespace App\Http\Middleware;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Support\MagasinOuvertParLaSession;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inscrit l'ouverture de session (JET 80) au journal des événements du
 * magasin, au premier accès de la session à son panneau : une seule fois
 * par session (voir MagasinOuvertParLaSession), quel que soit le nombre de
 * pages ouvertes ensuite.
 *
 * Déclaré dans ->tenantMiddleware() du panneau, après
 * BindTenantContextFromFilamentTenant : l'utilisateur est authentifié,
 * Filament a vérifié qu'il est membre actif du magasin de l'URL, et ce
 * magasin est le magasin actif du TenantContext. Persistant, il passe
 * aussi sur les requêtes Livewire du panneau.
 *
 * Fermé par défaut, comme toute écriture fiscale : quand le registre ne
 * peut pas l'inscrire (clé de signature absente, horloge du serveur qui
 * recule), la session n'entre pas dans le magasin. L'incident est signalé
 * au journal de l'application et la page répond 503, sans rien noter dans
 * la session : la requête suivante réessaie.
 */
class JournaliserOuvertureDeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->has() || $request->user() === null || ! $request->hasSession()) {
            return $next($request);
        }

        $magasinId = $contexte->id();
        $session = $request->session();

        if (MagasinOuvertParLaSession::de($session) !== $magasinId) {
            try {
                DB::transaction(fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::OuvertureDeSession));
            } catch (RefusFiscalException $refus) {
                report($refus);

                abort(Response::HTTP_SERVICE_UNAVAILABLE, $refus->getMessage());
            }

            MagasinOuvertParLaSession::noter($session, $magasinId);
        }

        return $next($request);
    }
}
