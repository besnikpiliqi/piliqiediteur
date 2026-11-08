<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Auth\ChoisirSonMotDePasse;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Filament\EspaceDeLaSociete;
use App\Filament\InitialesDuCompte;
use App\Filament\PoliceServieParPiliqi;
use App\Http\Controllers\TelechargerLesDocumentsController;
use App\Http\Controllers\TelechargerUnePieceDuFournisseurController;
use App\Http\Middleware\BindTenantContextFromFilamentTenant;
use App\Http\Middleware\ExigerSonPropreMotDePasse;
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
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panneau applicatif quotidien des magasins : le propriétaire de la
 * société, dans chacun de ses magasins, ses administrateurs, les membres
 * de l'équipe de chaque magasin, et les comptes d'un cabinet d'expertise
 * comptable lié, dans les magasins réels des clients qui leur sont
 * confiés, selon les autorisations que chaque client accorde au cabinet,
 * y gèrent clients, produits, factures, et le propriétaire et les
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
 * événements de ce magasin. Avant tout cela, ExigerSonPropreMotDePasse
 * mène un responsable ou un administrateur dont le mot de passe est
 * encore son identifiant au choix du sien, puis MenerUnCompteSansMagasin
 * mène un compte qui n'a encore aucun magasin à l'espace de sa société,
 * et un compte d'un cabinet à l'espace de son cabinet.
 * Le sélecteur de magasin n'apparaît qu'à qui en ouvre plusieurs : un
 * compte de membre n'en a qu'un, un compte d'un cabinet n'y voit que les
 * magasins du client ouvert.
 *
 * Tout composant Livewire monté par une vue hors des ressources, des pages
 * et des widgets découverts (les tableaux des clients et des produits des
 * pages de vente, dans app/Filament/App/Livewire) s'enregistre ici : sans
 * cela, Livewire ne retrouve pas sa classe d'après le nom inscrit dans son
 * instantané, et chacune de ses requêtes répond 419 « Page Expired ».
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            // La police et l'image des initiales du compte viennent de PILIQI,
            // jamais d'un site tiers (Q-TIERS, voir InitialesDuCompte).
            ->font('Inter', provider: PoliceServieParPiliqi::class)
            ->defaultAvatarProvider(InitialesDuCompte::class)
            ->path('app')
            ->login(Connexion::class)
            ->registration(InscriptionSociete::class)
            // « Mot de passe oublié » envoie un lien par e-mail au seul
            // propriétaire, à son adresse de connexion (voir DemandeDeLien),
            // qui mène à la page du lien de PILIQI (voir PageDuLien), où il
            // choisit son mot de passe ; tout autre compte se fait redonner
            // l'accès par qui gère son compte. Le profil permet à chacun de
            // changer son nom, son adresse e-mail et son mot de passe (voir
            // ModifierProfil).
            ->passwordReset(DemandeDeLien::class, PageDuLien::class)
            ->authenticatedRoutes(function (): void {
                ChoisirSonMotDePasse::registerRoutes();
                // Le lien d'un téléchargement des documents par période, pour
                // le seul compte qui l'a demandé (voir
                // TelechargerLesDocumentsController).
                Route::get('/telechargements/{telechargement}', TelechargerLesDocumentsController::class)
                    ->name('telechargements-de-documents.telecharger');
            })
            // Le téléchargement d'une pièce du fournisseur, dans le magasin
            // de l'adresse, pour qui peut voir son document (voir
            // TelechargerUnePieceDuFournisseurController).
            ->authenticatedTenantRoutes(function (): void {
                Route::get('/pieces-du-fournisseur/{piece}', TelechargerUnePieceDuFournisseurController::class)
                    ->name('pieces-du-fournisseur.telecharger');
            })
            ->profile(ModifierProfil::class)
            ->tenant(Magasin::class, slugAttribute: 'uuid')
            // Pour un compte d'un cabinet d'expertise comptable, seulement
            // les magasins du client ouvert (voir User::getTenants()).
            ->tenantMenu(fn (): bool => (auth()->user()?->getTenants(Filament::getCurrentPanel())->count() ?? 0) > 1)
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
            // (voir SocietePanelProvider), un compte d'un cabinet
            // d'expertise comptable à l'espace de son cabinet (voir
            // CabinetPanelProvider).
            ->userMenuItems([
                MenuItem::make()
                    ->label('Administration de la société')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (): string => EspaceDeLaSociete::accueil(auth()->user()->societe))
                    ->visible(fn (): bool => auth()->user()?->peutAdministrer() ?? false),
                MenuItem::make()
                    ->label('Espace du cabinet')
                    ->icon('heroicon-o-briefcase')
                    ->url(fn (): string => EspaceDeLaSociete::accueil(auth()->user()->societe))
                    ->visible(fn (): bool => auth()->user()?->ouvreLEspaceDuCabinet() ?? false),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            // Les widgets du tableau de bord d'un magasin, dont sa bienvenue
            // (voir BienvenueApercu) et les factures à encaisser (voir
            // FacturesAEncaisserApercu), en tête.
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            // Les tableaux des clients et des produits que montent les
            // fenêtres des pages de vente (voir SelecteurDeClients et
            // SelecteurDeProduits), retrouvés par leur nom à chacune de
            // leurs requêtes (voir plus haut).
            ->discoverLivewireComponents(in: app_path('Filament/App/Livewire'), for: 'App\\Filament\\App\\Livewire')
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
                MenerUnCompteSansMagasin::class,
            ], isPersistent: true)
            // Avant les scripts de Filament/Livewire : le script doit
            // s'abonner à "livewire:init" avant que Livewire ne démarre.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): View => view('filament.app.saisie-protegee'))
            // Une requête refusée (419, 404) dit en français quoi faire, au
            // lieu de la question en anglais de Livewire.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): View => view('filament.requete-refusee'))
            // En tête de chaque page d'un magasin en lecture seule : ce qui
            // l'y met et ce qui lui reste possible (voir ControleDesDroits).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.app.magasin-en-lecture-seule', ['magasin' => Filament::getTenant()]))
            // Tant que la société n'est pas validée, ce qui reste désactivé ;
            // dans le magasin de démonstration, que ses documents n'ont
            // aucune valeur.
            ->renderHook(PanelsRenderHook::CONTENT_START, function (): View {
                $magasin = Filament::getTenant();

                return view('filament.verification-en-cours', ['magasin' => $magasin, 'societe' => $magasin instanceof Magasin ? $magasin->societe : null]);
            })
            // Dans chaque tableau : après une action groupée en partie
            // refusée, seules les lignes refusées restent cochées (voir
            // SupprimeEnMasseLigneParLigne).
            ->renderHook(TablesRenderHook::HEADER_BEFORE, fn (): View => view('filament.app.lignes-cochees-gardees'));
    }
}
