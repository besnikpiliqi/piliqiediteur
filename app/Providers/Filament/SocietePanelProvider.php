<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * L'espace d'administration d'une société (URL
 * /societe/{societe:uuid}/...) : son tableau de bord, sa vérification, son
 * identité légale et ses paramètres fiscaux, réservés à son propriétaire (voir
 * User::canAccessPanel() et SocietePolicy). La tenancy Filament y est
 * branchée sur Societe, sans menu de changement (un compte n'appartient
 * qu'à une seule société) ; le TenantContext n'y pose que la société (voir
 * BindTenantContextFromFilamentTenant) : tout modèle d'un magasin y échoue
 * fermé.
 *
 * Aucune inscription ici, ni de compte ni de société : une société
 * s'inscrit depuis le panneau des magasins (voir InscriptionSociete). Les
 * ressources du panneau héritent de RessourceDuPanneauSociete, en mode
 * d'autorisation strict.
 */
class SocietePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('societe')
            ->path('societe')
            ->login()
            ->profile(ModifierProfil::class)
            ->tenant(Societe::class, slugAttribute: 'uuid')
            ->tenantMenu(false)
            ->tenantMiddleware([
                BindTenantContextFromFilamentTenant::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->databaseNotifications()
            ->userMenuItems([
                MenuItem::make()
                    ->label('Mes magasins')
                    ->icon('heroicon-o-building-storefront')
                    ->url(fn (): string => route('filament.app.tenant')),
            ])
            ->discoverResources(in: app_path('Filament/Societe/Resources'), for: 'App\\Filament\\Societe\\Resources')
            ->discoverPages(in: app_path('Filament/Societe/Pages'), for: 'App\\Filament\\Societe\\Pages')
            ->pages([
                TableauDeBord::class,
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
            // Tant que la société n'est pas validée, ce qui reste désactivé.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.verification-en-cours', ['magasin' => null, 'societe' => Filament::getTenant()]));
    }
}
