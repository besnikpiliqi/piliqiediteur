<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\App\Pages\Auth\ChoisirSonMotDePasse;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Societe\Pages\AccepterLaTransmission;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use App\Http\Middleware\ExigerSonPropreMotDePasse;
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
 * identité légale et ses paramètres fiscaux, ses magasins (voir
 * MagasinResource), ses comptes (voir MembreResource), ouverts à son
 * propriétaire et à ses administrateurs actifs, selon leurs autorisations
 * et leur portée, jamais à un membre ni à un expert-comptable (voir
 * User::canAccessPanel(), SocietePolicy, MagasinDeLaSocietePolicy et
 * MembrePolicy). Un administrateur dont le mot de passe est encore son
 * identifiant y choisit d'abord le sien (voir ExigerSonPropreMotDePasse).
 * Un administrateur y accepte la transmission de la propriété par le lien
 * reçu à son adresse (voir AccepterLaTransmission), hors de toute
 * société. La tenancy Filament y est branchée sur Societe, sans menu de
 * changement (un compte n'appartient qu'à une seule société) ; le
 * TenantContext n'y pose que la société (voir
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
            ->login(Connexion::class)
            ->profile(ModifierProfil::class)
            ->authenticatedRoutes(function (): void {
                ChoisirSonMotDePasse::registerRoutes();
                AccepterLaTransmission::registerRoutes();
            })
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
                // Seulement pour qui a un magasin à ouvrir : sans magasin,
                // le panneau des magasins le ramènerait ici.
                MenuItem::make()
                    ->label('Mes magasins')
                    ->icon('heroicon-o-building-storefront')
                    ->url(fn (): string => route('filament.app.tenant'))
                    ->visible(fn (): bool => auth()->user()?->magasinsAccessibles()->isNotEmpty() ?? false),
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
                ExigerSonPropreMotDePasse::class,
            ], isPersistent: true)
            // Tant que la société n'est pas validée, ce qui reste désactivé.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.verification-en-cours', ['magasin' => null, 'societe' => Filament::getTenant()]));
    }
}
