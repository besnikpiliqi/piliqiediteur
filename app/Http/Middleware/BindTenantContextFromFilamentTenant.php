<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Positionne le TenantContext (source de vérité unique, voir
 * app/Domain/Tenancy/Services/TenantContext.php) depuis le tenant déjà
 * résolu par Filament pour le panneau courant.
 *
 * Doit être déclaré dans ->tenantMiddleware() du panneau "app", APRÈS que
 * Filament ait résolu le tenant depuis le segment d'URL -- c'est le cas
 * par défaut car tenantMiddleware() s'exécute après la résolution du
 * tenant dans le cycle de requête de Filament.
 */
class BindTenantContextFromFilamentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Magasin) {
            app(TenantContext::class)->set($tenant);
        }

        return $next($request);
    }
}
