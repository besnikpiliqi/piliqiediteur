<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use App\Http\Middleware\JournaliserOuvertureDeSession;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
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
 * Panneau applicatif quotidien des magasins : le propriétaire de la
 * société, dans chacun de ses magasins, et les membres de l'équipe d'un
 * magasin y gèrent clients, produits, factures. C'est le panneau par
 * défaut (->default()), avec la tenancy Filament branchée sur Magasin (URL
 * /app/{magasin:uuid}/...), et celui de l'inscription d'une société (voir
 * InscriptionSociete). L'administration de la société a son propre
 * panneau (voir SocietePanelProvider).
 *
 * BindTenantContextFromFilamentTenant (en tenantMiddleware, donc exécuté
 * après que Filament ait résolu le tenant depuis l'URL) alimente notre
 * TenantContext -- seule source de vérité utilisée par MagasinScope, les
 * policies et le resolver de permissions Spatie (voir
 * app/Domain/Tenancy/Services/MagasinTeamResolver.php).
 * JournaliserOuvertureDeSession le suit : une fois le magasin actif
 * positionné, il inscrit l'ouverture de session (JET 80) au journal des
 * événements de ce magasin.
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
            ->registration(InscriptionSociete::class)
            // « Mot de passe oublié » envoie un lien par e-mail (voir
            // MAIL_MAILER ; textes dans lang/fr/passwords.php et
            // lang/fr.json) ; le profil permet à chacun de changer son
            // nom, son adresse de connexion et son mot de passe (voir
            // ModifierProfil). Depuis l'équipe, le magasin donne un
            // nouveau mot de passe à ses membres, jamais au propriétaire de
            // la société ni à celui qui agit (voir ReinitialiserMotDePasseMembreAction).
            ->passwordReset()
            ->profile(ModifierProfil::class)
            ->tenant(Magasin::class, slugAttribute: 'uuid')
            ->tenantMiddleware([
                BindTenantContextFromFilamentTenant::class,
                JournaliserOuvertureDeSession::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Indigo,
            ])
            // La cloche des notifications en base (voir
            // NotificationDuMagasin) : envois échoués, factures passées en
            // retard, stocks bas.
            ->databaseNotifications()
            // Le propriétaire passe d'ici à l'administration de sa société
            // (voir SocietePanelProvider).
            ->userMenuItems([
                MenuItem::make()
                    ->label('Administration de la société')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (): string => TableauDeBord::getUrl(panel: 'societe', tenant: auth()->user()->societe))
                    ->visible(fn (): bool => auth()->user()?->peutAdministrer() ?? false),
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
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): View => view('filament.app.saisie-protegee'))
            // En tête de chaque page d'un magasin en lecture seule : ce qui
            // l'y met et ce qui lui reste possible (voir ControleDesDroits).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.app.magasin-en-lecture-seule', ['magasin' => Filament::getTenant()]));
    }
}
