<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Auth\ModifierProfil;
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
 * gèrent clients, produits, factures. C'est le seul panneau, donc le
 * panneau par défaut (->default()), avec la tenancy Filament branchée sur
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
            // « Mot de passe oublié » envoie un lien par e-mail (voir
            // MAIL_MAILER ; textes dans lang/fr/passwords.php et
            // lang/fr.json) ; le profil permet à chacun de changer son
            // propre mot de passe. Le magasin ne réinitialise depuis
            // l'équipe que les comptes qui n'appartiennent qu'à lui (voir
            // ReinitialiserMotDePasseMembreAction).
            ->passwordReset()
            ->profile(ModifierProfil::class)
            ->tenant(Magasin::class, slugAttribute: 'uuid')
            ->tenantMiddleware([
                BindTenantContextFromFilamentTenant::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Indigo,
            ])
            // La cloche des notifications en base (voir
            // NotificationDuMagasin) : envois échoués, factures passées en
            // retard, stocks bas. Elle mélange les magasins de
            // l'utilisateur, d'où leur nom en tête de chaque notification.
            ->databaseNotifications()
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
