<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\ControleDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\CoordonneesMagasin;
use App\Filament\App\Pages\LaSociete;
use App\Filament\App\Pages\ParametresFactures;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Filament\App\Pages\ParametresRelances;
use App\Filament\App\Pages\TelechargerLesDocuments;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\App\Resources\EReportingResource;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\PaiementResource;
use App\Filament\App\Resources\PosteReconnuResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ServiceResource;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\Pages\TelechargerLesDocuments as TelechargerLesDocumentsDuCabinet;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\ListClientsDuCabinet;
use App\Filament\Cabinet\Resources\CollaborateurResource;
use App\Filament\Cabinet\RessourceDuPanneauCabinet;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MagasinResource;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L'espace d'un cabinet d'expertise comptable (voir CabinetPanelProvider)
 * : chaque page y déclare elle-même son accès et ne s'ouvre qu'aux comptes
 * actifs du cabinet affiché ; chaque ressource hérite de la base stricte,
 * avec une policy ; aucun compte d'une société qui n'est pas un cabinet ne
 * l'ouvre jamais.
 */
class PanneauCabinetTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_page_et_ressource_du_panneau_cabinet_declare_son_acces_et_a_une_policy(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif());
        $magasin = $this->ouvrirUneSociete();
        $proprietaireDUnCommerce = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $suspendu = $this->proprietaireDuCabinet($autreCabinet);
        $suspendu->forceFill(['statut' => StatutCompte::Suspendu])->save();
        // Un client qui accorde au cabinet le téléchargement des documents
        // par période, jamais accordé par défaut (décision de l'éditeur du
        // 03/11/2026, F2 = oui) : la page « Télécharger les documents » ne
        // s'ouvre qu'avec lui.
        $this->lierLeCabinet($cabinet, $this->ouvrirUneSociete(['nom' => 'Client qui accorde le téléchargement']), [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $panneau = Filament::getPanel('cabinet');
        $pages = $panneau->getPages();

        $this->assertContains(TableauDeBordDuCabinet::class, $pages);

        foreach ($pages as $page) {
            /** @var class-string<Page> $page */
            $this->assertSame($page, (new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName(), $page);
            $this->assertSame(
                ['proprietaire' => true, 'autre_cabinet' => false, 'commerce' => false, 'administrateur' => false, 'suspendu' => false, 'sans_connexion' => false],
                [
                    'proprietaire' => $this->ouvre($page, $proprietaire, $cabinet),
                    'autre_cabinet' => $this->ouvre($page, $this->proprietaireDuCabinet($this->ouvrirUnCabinet(SocieteFactory::sirenFictif())), $cabinet),
                    'commerce' => $this->ouvre($page, $proprietaireDUnCommerce, $magasin->societe),
                    'administrateur' => $this->ouvre($page, $administrateur, $magasin->societe),
                    'suspendu' => $this->ouvre($page, $suspendu->fresh(), $autreCabinet),
                    'sans_connexion' => $this->ouvre($page, null, $cabinet),
                ],
                $page,
            );
        }

        foreach ($panneau->getResources() as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertTrue(is_subclass_of($ressource, RessourceDuPanneauCabinet::class), $ressource);
            $this->assertFalse($ressource::shouldCheckPolicyExistence(), $ressource);
            $this->assertNotNull(Gate::getPolicyFor($ressource::getModel()), $ressource);
        }

        $this->assertFalse(RessourceDuPanneauCabinet::shouldCheckPolicyExistence());
    }

    /**
     * @return array<string, array{0: TypeDActivite, 1: string}>
     */
    public static function comptesSansEspaceDeCabinet(): array
    {
        $comptes = [];

        foreach ([TypeDActivite::Commerce, TypeDActivite::Services] as $type) {
            foreach (['proprietaire', 'administrateur', 'membre'] as $compte) {
                $comptes["{$compte} ({$type->value})"] = [$type, $compte];
            }
        }

        return $comptes;
    }

    /**
     * Le propriétaire, un administrateur ou un membre d'une société qui
     * n'est pas un cabinet n'ouvre jamais l'espace d'un cabinet : ni pour
     * sa propre société (403), ni pour un cabinet (403), et le panneau ne
     * lui propose aucune société.
     */
    #[DataProvider('comptesSansEspaceDeCabinet')]
    public function test_un_compte_d_une_societe_qui_n_est_pas_un_cabinet_n_ouvre_jamais_le_panneau_cabinet(TypeDActivite $type, string $role): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['type' => $type]);
        $compte = match ($role) {
            'proprietaire' => $this->proprietaireDe($magasin),
            'administrateur' => $this->administrateur($magasin, null, AutorisationsSociete::toutes()),
            default => $this->responsable($magasin),
        };
        app(TenantContext::class)->clear();

        $this->assertFalse($compte->canAccessPanel(Filament::getPanel('cabinet')));
        $this->assertSame([], $compte->getTenants(Filament::getPanel('cabinet'))->modelKeys());
        $this->assertFalse($compte->canAccessTenant($cabinet));
        $this->actingAs($compte);
        $this->get(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $magasin->societe))->assertForbidden();
        $this->get(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))->assertForbidden();
    }

    /**
     * L'adresse de l'espace d'administration de son propre cabinet
     * (/societe/{uuid} et ses pages), comme son accueil sans société
     * (/societe), mène le propriétaire du cabinet et un collaborateur au
     * tableau de bord de l'espace du cabinet, au lieu d'une page 403.
     */
    #[DataProvider('comptesDuCabinet')]
    public function test_l_adresse_de_l_espace_societe_de_son_cabinet_mene_au_tableau_de_bord_du_cabinet(string $qui): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $compte = $qui === 'proprietaire' ? $this->proprietaireDuCabinet($cabinet) : $this->collaborateur($cabinet);
        $accueil = TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet);
        $this->actingAs($compte);

        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $cabinet))->assertRedirect($accueil);
        $this->get(MagasinResource::getUrl('index', panel: 'societe', tenant: $cabinet))->assertRedirect($accueil);
        $this->get(route('filament.societe.tenant'))->assertRedirect($accueil);
        $this->get($accueil)->assertOk();
    }

    /**
     * L'adresse de l'espace d'administration d'une autre société reste
     * refusée à un compte d'un cabinet, client lié et confié compris, de la
     * même façon que pour une société qui n'existe pas : rien ne s'en
     * apprend.
     */
    #[DataProvider('comptesDuCabinet')]
    public function test_l_espace_societe_d_une_autre_societe_reste_refuse_a_un_compte_de_cabinet_sans_rien_reveler(string $qui): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $compte = $qui === 'proprietaire' ? $this->proprietaireDuCabinet($cabinet) : $this->confier($this->collaborateur($cabinet), [$magasin]);
        $this->actingAs($compte);

        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        $this->get('/societe/'.Str::uuid())->assertForbidden();
    }

    /**
     * Le propriétaire d'une autre société ne trouve aucun espace
     * d'administration sous le uuid d'un cabinet, comme avant.
     */
    public function test_le_proprietaire_d_une_autre_societe_ne_trouve_pas_l_espace_societe_sous_le_uuid_d_un_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique']);

        $reponse = $this->actingAs($this->proprietaireDe($magasin))->get(TableauDeBord::getUrl(panel: 'societe', tenant: $cabinet));

        $reponse->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function comptesDuCabinet(): array
    {
        return ['propriétaire du cabinet' => ['proprietaire'], 'collaborateur' => ['collaborateur']];
    }

    /**
     * Un collaborateur ouvre chaque page de l'espace de son cabinet, sauf
     * « Invitations et demandes » (403) et « Télécharger les documents »,
     * que ce client ne lui accorde pas (jamais par défaut : décision de
     * l'éditeur du 03/11/2026, F2 = oui), sans les gestes du propriétaire :
     * ni créer un collaborateur, ni confier, ni mettre fin au lien, ni
     * vérifier l'Ordre ni modifier l'identité.
     */
    public function test_un_collaborateur_voit_les_pages_du_cabinet_sans_les_actions_du_proprietaire(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);

        foreach (Filament::getPanel('cabinet')->getPages() as $page) {
            $this->assertSame(! in_array($page, [InvitationsEtDemandes::class, TelechargerLesDocumentsDuCabinet::class], true), $this->ouvre($page, $collaborateur, $cabinet), $page);
        }

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        $this->get(InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet))->assertForbidden();
        Livewire::test(ListClientsDuCabinet::class)
            ->assertCanSeeTableRecords([$lien])
            ->assertTableActionHidden('confier', $lien)
            ->assertTableActionHidden('mettreFin', $lien);
        $this->assertFalse($collaborateur->can('creerUnCollaborateur', User::class));
        $this->assertSame(0, CollaborateurResource::getEloquentQuery()->count());
        $this->assertFalse($collaborateur->can('modifierLesParametres', $cabinet));
        $this->assertFalse($collaborateur->can('verifierLOrdre', $cabinet->cabinet()->sole()));
    }

    /**
     * Ce qu'un compte d'un cabinet ouvre dans un magasin d'un client lié
     * qui lui accorde toutes les autorisations d'un cabinet : chaque
     * ressource et chaque page du panneau des magasins, avec sa réponse
     * attendue. Une ressource ou une page ajoutée sans réponse fait échouer
     * le test : c'est lui qui fige la liste blanche des gestes d'un cabinet
     * (voir ControleDesDroits::GESTES_D_UN_CABINET).
     */
    public function test_chaque_ressource_et_page_du_panneau_app_a_une_reponse_attendue_pour_un_compte_de_cabinet(): void
    {
        $attendu = [
            AvoirFournisseurResource::class => ['consulter' => true, 'creer' => true],
            AvoirResource::class => ['consulter' => true, 'creer' => false],
            ClientResource::class => ['consulter' => true, 'creer' => false],
            ClotureCaissePersonnelleResource::class => ['consulter' => true, 'creer' => false],
            ClotureCaisseResource::class => ['consulter' => true, 'creer' => false],
            DevisResource::class => ['consulter' => true, 'creer' => false],
            EReportingResource::class => ['consulter' => true, 'creer' => false],
            EnregistrementFiscalResource::class => ['consulter' => true, 'creer' => false],
            EquipeDuMagasinResource::class => ['consulter' => false, 'creer' => false],
            FactureAchatResource::class => ['consulter' => true, 'creer' => true],
            FactureResource::class => ['consulter' => true, 'creer' => false],
            FournisseurResource::class => ['consulter' => true, 'creer' => true],
            InventaireOuvertureResource::class => ['consulter' => true, 'creer' => true],
            PaiementResource::class => ['consulter' => true, 'creer' => false],
            PosteReconnuResource::class => ['consulter' => false, 'creer' => false],
            ProduitResource::class => ['consulter' => true, 'creer' => false],
            ServiceResource::class => ['consulter' => true, 'creer' => false],
        ];
        $pagesAttendues = [
            Caisse::class => false,
            CoordonneesMagasin::class => false,
            LaSociete::class => false,
            ParametresFactures::class => false,
            ParametresPrixDeVente::class => false,
            ParametresRelances::class => false,
            TelechargerLesDocuments::class => true,
            Dashboard::class => true,
        ];
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, PermissionRegistry::duCabinet(TypeDActivite::Commerce));
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $this->dansLeMagasin($magasin, $collaborateur);
        $panneau = Filament::getPanel('app');
        $pages = array_values($panneau->getPages());

        $this->assertEqualsCanonicalizing(array_keys($attendu), array_values($panneau->getResources()));
        $this->assertEqualsCanonicalizing(array_keys($pagesAttendues), $pages);
        foreach ($attendu as $ressource => $reponse) {
            $this->assertSame($reponse, ['consulter' => $ressource::canViewAny(), 'creer' => $ressource::canCreate()], $ressource);
        }
        foreach ($pagesAttendues as $page => $reponse) {
            $this->assertSame($reponse, $page::canAccess(), $page);
        }
        foreach (['viewAny', 'view'] as $geste) {
            $this->assertNotEmpty(ControleDesDroits::GESTES_D_UN_CABINET[$geste]);
        }
        $this->assertFalse($collaborateur->can('create', Service::class));
        $this->assertFalse($collaborateur->can('viewAny', User::class));
    }

    private function ouvre(string $page, ?User $compte, Societe $societe): bool
    {
        auth()->logout();

        if ($compte !== null) {
            $this->actingAs($compte);
        }

        Filament::setCurrentPanel(Filament::getPanel('cabinet'));
        Filament::setTenant($societe, isQuiet: true);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($societe);

        return $page::canAccess();
    }
}
