<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\AucunMagasin;
use App\Filament\App\Pages\Auth\ChangerMotDePasseProvisoire;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use App\Http\Middleware\ExigerUnMotDePasseDefinitif;
use App\Http\Middleware\JournaliserOuvertureDeSession;
use App\Http\Middleware\MenerUnCompteSansMagasin;
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
 * société, dans chacun de ses magasins, ses administrateurs, ses
 * experts-comptables (en lecture) et les membres de l'équipe de chaque
 * magasin y gèrent clients, produits, factures, et le propriétaire et les
 * responsables d'un magasin son équipe (voir EquipeDuMagasinResource).
 * C'est le panneau par défaut (->default()), avec la tenancy Filament
 * branchée sur Magasin (URL /app/{magasin:uuid}/...), et celui de
 * l'inscription d'une société (voir InscriptionSociete). L'administration
 * de la société a son propre panneau (voir SocietePanelProvider).
 *
 * BindTenantContextFromFilamentTenant (en tenantMiddleware, donc exécuté
 * après que Filament ait résolu le tenant depuis l'URL) alimente notre
 * TenantContext -- seule source de vérité utilisée par MagasinScope, les
 * policies et la lecture des droits (voir ControleDesDroits).
 * JournaliserOuvertureDeSession le suit : une fois le magasin actif
 * positionné, il inscrit l'ouverture de session (JET 80) au journal des
 * événements de ce magasin. Avant tout cela, ExigerUnMotDePasseDefinitif
 * mène un compte au mot de passe provisoire à son changement, puis
 * MenerUnCompteSansMagasin mène un compte qui n'a encore aucun magasin à
 * l'espace de sa société ou à la page qui le lui dit (voir AucunMagasin).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login(Connexion::class)
            ->registration(InscriptionSociete::class)
            // « Mot de passe oublié » envoie un lien par e-mail à tout
            // compte (voir DemandeDeLien), qui mène à la page du lien de
            // PILIQI (voir PageDuLien) : choisir son mot de passe, ou
            // refuser le compte s'il peut l'être. Le profil permet à chacun
            // de changer son nom, son adresse de connexion et son mot de
            // passe (voir ModifierProfil).
            ->passwordReset(DemandeDeLien::class, PageDuLien::class)
            ->authenticatedRoutes(function (): void {
                ChangerMotDePasseProvisoire::registerRoutes();
                AucunMagasin::registerRoutes();
            })
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
                ExigerUnMotDePasseDefinitif::class,
                MenerUnCompteSansMagasin::class,
            ], isPersistent: true)
            // Avant les scripts de Filament/Livewire : le script doit
            // s'abonner à "livewire:init" avant que Livewire ne démarre.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): View => view('filament.app.saisie-protegee'))
            // En tête de chaque page d'un magasin en lecture seule : ce qui
            // l'y met et ce qui lui reste possible (voir ControleDesDroits).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.app.magasin-en-lecture-seule', ['magasin' => Filament::getTenant()]))
            // Tant que la société n'est pas validée, ce qui reste désactivé ;
            // dans le magasin de démonstration, que ses documents n'ont
            // aucune valeur.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.verification-en-cours', ['magasin' => Filament::getTenant(), 'societe' => Filament::getTenant()?->societe]));
    }
}
