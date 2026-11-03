<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Positionne le TenantContext (source de vérité unique, voir
 * app/Domain/Tenancy/Services/TenantContext.php) depuis le tenant déjà
 * résolu par Filament pour le panneau courant : le magasin, et avec lui sa
 * société, dans le panneau « app » ; la société seule dans celui de
 * l'administration de la société, où toute lecture d'un modèle de magasin
 * échoue donc fermée (voir MagasinScope), sauf sous runAs().
 *
 * Doit être déclaré dans ->tenantMiddleware() de chaque panneau, APRÈS que
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

        if ($tenant instanceof Societe) {
            app(TenantContext::class)->poserSociete($tenant);
        }

        return $next($request);
    }
}
