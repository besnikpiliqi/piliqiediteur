<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * L'espace d'un cabinet d'expertise comptable (URL
 * /cabinet/{societe:uuid}/...), distinct de l'espace d'administration
 * d'une société et du panneau d'un magasin : son tableau de bord, avec la
 * liste des étapes de son propriétaire tant que le cabinet n'est pas
 * vérifié (voir TableauDeBordDuCabinet), sa vérification (le courrier
 * recommandé, son code d'activité, son inscription au tableau de l'Ordre,
 * voir VerificationDuCabinet) et son identité (voir IdentiteDuCabinet),
 * ouverts à son propriétaire et à ses comptes actifs (voir
 * User::canAccessPanel() et User::ouvreLEspaceDuCabinet()), jamais à un
 * compte d'une autre société. Le propriétaire d'un cabinet y arrive après
 * la confirmation de son inscription et à chaque connexion (voir
 * MenerUnCompteSansMagasin et ConfirmerInscriptionController). La tenancy
 * Filament y est branchée sur Societe, sans menu de changement ; le
 * TenantContext n'y pose que la société du cabinet (voir
 * BindTenantContextFromFilamentTenant) : tout modèle d'un magasin y échoue
 * fermé. Aucun compte d'un cabinet ne s'y connecte avec son identifiant
 * pour mot de passe : le propriétaire a choisi le sien à l'inscription.
 * Les ressources du panneau héritent de RessourceDuPanneauCabinet, en mode
 * d'autorisation strict.
 */
class CabinetPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cabinet')
            ->path('cabinet')
            ->login(Connexion::class)
            ->profile(ModifierProfil::class)
            ->tenant(Societe::class, slugAttribute: 'uuid')
            ->tenantMenu(false)
            ->tenantMiddleware([
                BindTenantContextFromFilamentTenant::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Sky,
            ])
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Cabinet/Resources'), for: 'App\\Filament\\Cabinet\\Resources')
            ->discoverPages(in: app_path('Filament/Cabinet/Pages'), for: 'App\\Filament\\Cabinet\\Pages')
            ->pages([
                TableauDeBordDuCabinet::class,
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
            ], isPersistent: true)
            // Tant que le cabinet n'est pas validé, qu'il attend son code.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.verification-en-cours', ['magasin' => null, 'societe' => Filament::getTenant()]));
    }
}
