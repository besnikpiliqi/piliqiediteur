<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Auth\RegisterMagasin;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panneau applicatif quotidien des magasins : propriétaires et vendeurs y
 * gèrent clients, produits, factures. C'est le panneau PAR DÉFAUT
 * (->default()) et le seul avec la tenancy Filament activée, branchée sur
 * Magasin (URL /app/{magasin:uuid}/...).
 *
 * BindTenantContextFromFilamentTenant (en tenantMiddleware, donc exécuté
 * après que Filament ait résolu le tenant depuis l'URL) alimente notre
 * TenantContext -- seule source de vérité utilisée par MagasinScope, les
 * policies et le resolver de permissions Spatie (voir
 * app/Domain/Tenancy/Services/MagasinTeamResolver.php).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->registration(RegisterMagasin::class)
            ->tenant(Magasin::class, slugAttribute: 'uuid')
            ->tenantMiddleware([
                BindTenantContextFromFilamentTenant::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Avant les scripts de Filament/Livewire : le script doit
            // s'abonner à "livewire:init" avant que Livewire ne démarre.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): View => view('filament.app.saisie-protegee'));
    }
}
