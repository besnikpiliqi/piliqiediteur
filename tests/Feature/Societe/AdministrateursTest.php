<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\NommerAdministrateurAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\GardesDesDroits;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Les administrateurs de la société, que le propriétaire seul crée, nomme,
 * modifie, rétrograde, suspend et retire : chacun a accès à tous les
 * magasins, présents et futurs, ou à une sélection, jamais aux deux, avec
 * les autorisations que le propriétaire coche pour lui, magasin et
 * société. Rien ne lui vaut hors de sa portée.
 */
class AdministrateursTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_administrateur_avec_sa_portee_et_ses_autorisations_en_une_fois(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(ListMembres::class)
            ->mountAction('creerUnAdministrateur')
            ->set('mountedActionsData.0.autorisations_societe', [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER])
            ->set('mountedActionsData.0.autorisations_magasin', [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW])
            ->set('mountedActionsData.0.magasins', [$autre->id])
            ->setActionData(['name' => 'Alix', 'email' => 'alix@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'portee' => 'selection'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $alix = User::query()->where('email', 'alix@example.test')->sole();
        $this->assertSame([RoleSociete::Administrateur, true], [$alix->role_societe, $alix->doitChangerSonMotDePasse()]);
        $this->assertSame([AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER], $alix->autorisationsDeSociete());
        $affectation = Affectation::query()->where('user_id', $alix->id)->sole();
        $this->assertSame([(int) $autre->id, null], [(int) $affectation->magasin_id, $affectation->role]);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW], $affectation->autorisations());
    }

    /**
     * Le formulaire ne présélectionne aucune portée : le propriétaire
     * choisit tous les magasins ou une sélection.
     */
    public function test_aucune_portee_n_est_preselectionnee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(ListMembres::class)->mountAction('creerUnAdministrateur');

        $this->assertNull($page->get('mountedActionsData.0.portee'));
        $page->setActionData(['name' => 'Alix', 'email' => 'alix@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE])
            ->callMountedAction()
            ->assertHasActionErrors(['portee']);
        $this->assertFalse(User::query()->where('email', 'alix@example.test')->exists());
    }

    /**
     * Le modèle d'un nouvel administrateur précoche « gérer les magasins »,
     * et ni « nommer et retirer les responsables » ni les paramètres de la
     * société.
     */
    public function test_le_modele_d_un_nouvel_administrateur_precoche_gerer_les_magasins_et_pas_nommer_les_responsables(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(ListMembres::class)->mountAction('creerUnAdministrateur');

        $this->assertSame([AutorisationsSociete::MAGASINS_GERER], array_values($page->get('mountedActionsData.0.autorisations_societe')));
        $this->assertEqualsCanonicalizing(PermissionRegistry::modeleDeLAdministrateur(), $page->get('mountedActionsData.0.autorisations_magasin'));
    }

    public function test_un_administrateur_de_tous_les_magasins_a_ses_autorisations_dans_un_magasin_cree_apres_lui(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [], [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_CREATE]);

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après lui']);

        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_CREATE], $administrateur->refresh()->autorisationsDans($nouveau));
        $this->assertTrue($administrateur->canAccessTenant($nouveau));
        $this->assertTrue($administrateur->administreTousLesMagasins());
    }

    public function test_un_administrateur_d_une_selection_n_a_rien_hors_de_sa_selection(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsSelection = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors sélection']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER]);

        $this->assertSame([], $administrateur->autorisationsDans($horsSelection));
        $this->assertFalse($administrateur->canAccessTenant($horsSelection));
        $this->assertNotContains($horsSelection->id, $administrateur->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertFalse($administrateur->administreLeMagasin($horsSelection));
        $this->actingAs($administrateur)->get("/app/{$horsSelection->uuid}")->assertNotFound();
    }

    public function test_un_administrateur_d_une_selection_n_obtient_rien_dans_un_magasin_cree_apres_lui(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après lui']);

        $this->assertSame([], $administrateur->refresh()->autorisationsDans($nouveau));
        $this->assertFalse($administrateur->canAccessTenant($nouveau));
    }

    /**
     * Même autorisé à gérer les magasins, l'administrateur d'une sélection
     * ne crée pas de magasin ; et les paramètres de toute la société ne se
     * cochent que pour un administrateur de tous les magasins.
     */
    public function test_un_administrateur_d_une_selection_ne_cree_pas_de_magasin_et_ne_coche_pas_les_autorisations_de_toute_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => app(TenantContext::class)->runAsSociete($magasin->societe, fn () => app(CreerEtablissementAction::class)->handle($administrateur, $magasin->societe, ['nom' => 'Pris', 'nic' => MagasinFactory::nicLibre($magasin->societe)])), LogicException::class);
        $this->assertThrows(fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id], [], [AutorisationsSociete::PARAMETRES_GERER]), LogicException::class, PorteeDesAdministrateurs::MESSAGE_TOUS_LES_MAGASINS_EXIGES);

        $this->assertSame(1, $magasin->societe->magasinsReels()->count());
        $this->assertFalse(User::query()->where('email', 'alix@example.test')->exists());
        $this->assertNotContains(AutorisationsSociete::PARAMETRES_GERER, $administrateur->refresh()->autorisationsDeSociete());
    }

    /**
     * Nommer administrateur un membre supprime ses affectations de membre,
     * avec un JET 130 dans chacun de ses anciens magasins et de sa portée.
     */
    public function test_nommer_administrateur_supprime_les_affectations_avec_un_jet_130_par_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $membre = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Observateur]], $magasin, ['name' => 'Paul']);

        app(NommerAdministrateurAction::class)->handle($this->proprietaireDe($magasin), $membre, [$magasin->id], [PermissionRegistry::FACTURE_VIEW], []);

        $this->assertSame([[(int) $magasin->id, null]], Affectation::query()->where('user_id', $membre->id)->get()->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());
        $dansLeMagasin = collect($this->modificationsDesDroits($magasin))->last();
        $this->assertSame(['role_societe' => 'administrateur', 'role_magasin' => null, 'statut' => 'actif', 'permissions' => [PermissionRegistry::FACTURE_VIEW]], $dansLeMagasin['apres']);
        $dansLAutre = collect($this->modificationsDesDroits($autre))->last();
        $this->assertSame([$membre->id, 'observateur', null], [$dansLAutre['membre']['id'], $dansLAutre['avant']['role_magasin'], $dansLAutre['apres']]);
    }

    public function test_modifier_un_administrateur_global_inscrit_le_jet_130_dans_tous_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $administrateur = $this->administrateur($magasin, null, [], [PermissionRegistry::FACTURE_VIEW]);

        app(ModifierAdministrateurAction::class)->modifier($this->proprietaireDe($magasin), $administrateur, null, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW], []);

        foreach ([$magasin, $autre] as $chacun) {
            $dernier = collect($this->modificationsDesDroits($chacun))->last();
            $this->assertSame([$administrateur->id, [PermissionRegistry::FACTURE_VIEW], [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW]], [$dernier['membre']['id'], $dernier['avant']['permissions'], $dernier['apres']['permissions']], $chacun->nom);
        }
    }

    public function test_retrograder_un_administrateur_exige_ses_affectations(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);

        $this->assertThrows(fn () => app(ModifierAdministrateurAction::class)->retrograder($proprietaire, $administrateur, []), LogicException::class, AffectationsDesMembres::MESSAGE_SANS_MAGASIN);
        $this->assertTrue($administrateur->refresh()->estAdministrateur());

        app(ModifierAdministrateurAction::class)->retrograder($proprietaire, $administrateur, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertTrue($administrateur->refresh()->estMembre());
        $this->assertSame([], $administrateur->autorisationsDeSociete());
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $administrateur->id)->sole()->role);
    }

    public function test_retirer_un_administrateur_le_repasse_membre_sans_autorisation_de_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());

        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $administrateur);

        $administrateur->refresh();
        $this->assertSame([RoleSociete::Membre, StatutCompte::Retire], [$administrateur->role_societe, $administrateur->statut]);
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
    }

    /**
     * Suspendu, un administrateur n'ouvre plus rien : la page qu'il avait
     * ouverte refuse sa vraie requête Livewire suivante, et aucun panneau ne
     * s'ouvre plus.
     */
    public function test_un_administrateur_suspendu_n_ouvre_plus_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['nom' => 'Origine', 'suivi_stock' => false]);
        $page = $this->actingAs($administrateur)->get(ProduitResource::getUrl('edit', ['record' => $produit, 'tenant' => $magasin]))->assertOk();
        $instantane = $this->instantaneLivewire($page->getContent(), 'edit-produit');

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $administrateur);

        $this->actingAs($administrateur->refresh())->requeteLivewire($instantane, ['data.nom' => 'Pirate'], 'save')->assertForbidden();
        $this->assertSame('Origine', Produit::query()->withoutGlobalScopes()->findOrFail($produit->id)->nom);
        $this->assertFalse($administrateur->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($administrateur->canAccessPanel(Filament::getPanel('societe')));
        $this->assertFalse($administrateur->canAccessTenant($magasin->societe));
    }

    /**
     * Seul le propriétaire gère les administrateurs : un administrateur,
     * même de tous les magasins et autorisé à tout, n'en crée, n'en nomme
     * ni n'en modifie aucun.
     */
    public function test_un_administrateur_ne_nomme_ni_ne_cree_ni_ne_modifie_un_administrateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $autreAdministrateur = $this->administrateur($magasin, [$magasin->id]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertThrows(fn () => app(CreerAdministrateurAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [], []), AuthorizationException::class, CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->assertThrows(fn () => app(NommerAdministrateurAction::class)->handle($administrateur, $vendeur, null, [], []), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierAdministrateurAction::class)->modifier($administrateur, $autreAdministrateur, null, PermissionRegistry::toutes(), AutorisationsSociete::toutes()), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierAdministrateurAction::class)->retrograder($administrateur, $autreAdministrateur, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(ListMembres::class)->assertActionHidden('creerUnAdministrateur'), 'creerUnAdministrateur', ['name' => 'Alix', 'email' => 'alix@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'portee' => 'tous']);

        $this->assertFalse(User::query()->where('email', 'alix@example.test')->exists());
        $this->assertTrue($vendeur->refresh()->estMembre());
        $this->assertFalse($autreAdministrateur->refresh()->administreTousLesMagasins());
    }

    public function test_le_proprietaire_n_a_jamais_d_affectation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);

        $this->assertThrows(fn () => app(ModifierAdministrateurAction::class)->modifier($proprietaire, $proprietaire, null, [], []), AuthorizationException::class);
        $this->assertThrows(fn () => app(NommerAdministrateurAction::class)->handle($proprietaire, $proprietaire, null, [], []), AuthorizationException::class);

        $this->assertSame(0, Affectation::query()->where('user_id', $proprietaire->id)->count());
        $this->assertTrue($proprietaire->refresh()->estProprietaire());
    }

    /**
     * Seul un administrateur a une affectation à tous les magasins : un
     * membre n'a que des affectations à un magasin, avec un rôle.
     */
    public function test_seul_un_administrateur_est_affecte_a_tous_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->administrateur($magasin);
        $this->membre($magasin, RoleMagasin::Vendeur);
        $this->responsable($magasin);
        $this->expertComptable($magasin);

        $globales = Affectation::query()->whereNull('magasin_id')->pluck('user_id')->all();

        $this->assertSame([RoleSociete::Administrateur], User::query()->whereIn('id', $globales)->pluck('role_societe')->unique()->values()->all());
        $this->assertSame(0, Affectation::query()->whereNull('magasin_id')->whereNotNull('role')->count());
    }

    /**
     * Une affectation globale ou une autorisation de société qui resterait
     * à un membre (glissée sans les gardes) ne lui donne rien.
     */
    public function test_une_affectation_globale_ou_une_autorisation_de_societe_restee_a_un_membre_ne_donne_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $this->retirerLaGardeDesDroits(GardesDesDroits::AFFECTATIONS);
        $this->retirerLaGardeDesDroits(GardesDesDroits::AUTORISATIONS_SOCIETE);
        $globale = DB::table('affectations')->insertGetId(['societe_id' => $magasin->societe_id, 'user_id' => $vendeur->id, 'magasin_id' => null, 'role' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('autorisations_affectations')->insert(['affectation_id' => $globale, 'autorisation' => PermissionRegistry::PRODUIT_CREATE]);
        DB::table('autorisations_societe')->insert(['societe_id' => $magasin->societe_id, 'user_id' => $vendeur->id, 'autorisation' => AutorisationsSociete::MAGASINS_GERER]);
        $vendeur->refresh();

        $this->assertSame([], $vendeur->autorisationsDans($autre));
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $vendeur->autorisationsDans($magasin));
        $this->assertSame([], $vendeur->autorisationsDeSociete());
        $this->assertFalse($vendeur->canAccessTenant($autre));
        $this->assertFalse($vendeur->can(AutorisationsSociete::MAGASINS_GERER));
    }

    public function test_retrograder_un_administrateur_ne_lui_laisse_aucun_droit_dans_un_magasin_cree_ensuite(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        app(ModifierAdministrateurAction::class)->retrograder($this->proprietaireDe($magasin), $administrateur, [$magasin->id => ['role' => RoleMagasin::Observateur]]);

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert ensuite']);

        $this->assertSame([], $administrateur->refresh()->autorisationsDans($nouveau));
        $this->assertFalse($administrateur->canAccessTenant($nouveau));
        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->whereNull('magasin_id')->count());
    }

    /**
     * Tous les magasins, ou une sélection d'au moins un magasin : une
     * sélection vide est refusée, et la base refuse les deux sortes
     * d'affectations ensemble (voir GardesDesDroitsEnBaseTest).
     */
    public function test_un_administrateur_a_tous_les_magasins_ou_une_selection_jamais_les_deux(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [], [], []), LogicException::class, PorteeDesAdministrateurs::MESSAGE_SANS_PORTEE);
        $deTous = $this->administrateur($magasin);
        $deLaSelection = $this->administrateur($magasin, [$magasin->id]);

        $this->assertSame([null], Affectation::query()->where('user_id', $deTous->id)->pluck('magasin_id')->all());
        $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $deLaSelection->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertFalse(User::query()->where('email', 'alix@example.test')->exists());
    }

    /**
     * Une ligne globale qui resterait, glissée sans les gardes, à un
     * administrateur d'une sélection ne lui donne rien : ni hors de sa
     * sélection, ni même dans sa sélection (les deux sortes ensemble ne
     * valent rien).
     */
    public function test_une_ligne_globale_restee_a_un_administrateur_d_une_selection_ne_donne_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsSelection = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors sélection']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [], [PermissionRegistry::FACTURE_VIEW]);
        $this->retirerLaGardeDesDroits(GardesDesDroits::AFFECTATIONS);
        $globale = DB::table('affectations')->insertGetId(['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'magasin_id' => null, 'role' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('autorisations_affectations')->insert(['affectation_id' => $globale, 'autorisation' => PermissionRegistry::PRODUIT_CREATE]);
        $administrateur->refresh();

        $this->assertSame([], $administrateur->autorisationsDans($horsSelection));
        $this->assertSame([], $administrateur->autorisationsDans($magasin));
        $this->assertFalse($administrateur->administreTousLesMagasins());
        $this->assertFalse($administrateur->canAccessTenant($horsSelection));
    }

    /**
     * Le modèle de l'administrateur coche toutes les autorisations sauf les
     * paramètres du magasin et celles de la vente, qui restent dans leur
     * groupe à part, jamais précochées (Q3-a) : un administrateur créé
     * sans y toucher ne vend pas.
     */
    public function test_le_modele_de_l_administrateur_ne_precoche_aucune_autorisation_de_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(ListMembres::class)->mountAction('creerUnAdministrateur')->assertSee('Vente (seulement si cet administrateur vend)');

        $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), PermissionRegistry::modeleDeLAdministrateur())));
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::horsVente(), [PermissionRegistry::PARAMETRES_GERER])), PermissionRegistry::modeleDeLAdministrateur());
        $this->assertEmpty($page->get('mountedActionsData.0.autorisations_magasin_vente'));
        $page->setActionData(['name' => 'Alix', 'email' => 'alix@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'portee' => 'tous'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $alix = $this->choisirSonMotDePasse(User::query()->where('email', 'alix@example.test')->sole());
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $alix->autorisationsDans($magasin))));
        $this->assertFalse($alix->tientUneCaisseDans($magasin));
    }

    /**
     * Sans les autorisations de vente, un administrateur ne vend pas : ni
     * caisse, ni « Nouvelle facture », ni « Émettre » à l'écran ; une
     * requête Livewire forgée, l'action appelée directement et
     * l'instantané de la caisse d'un responsable rejoué sous sa session
     * sont refusés, et rien n'est vendu.
     */
    public function test_un_administrateur_sans_autorisation_de_vente_ne_vend_ni_par_l_ecran_ni_par_livewire_ni_par_l_action_ni_par_une_demande_fabriquee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $brouillon = app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), $this->lignes());
        PanierCaisse::factory()->avecLeProduit(Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]), 1)->create(['user_id' => $responsable->id]);
        $instantane = $this->instantaneLivewire($this->actingAs($responsable)->get(Caisse::getUrl(tenant: $magasin))->assertOk()->getContent(), 'caisse');
        $this->dansLeMagasin($magasin, $administrateur);

        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();
        Livewire::test(ListFactures::class)->assertActionHidden('create')->assertTableActionHidden('emettre', $brouillon);
        Livewire::test(CreateFacture::class)->assertForbidden();
        $this->forgerLActionDeLigne(Livewire::test(ListFactures::class), 'emettre', $brouillon, ['jeton' => (string) Str::uuid()]);
        $this->assertThrows(fn () => app(CreerFactureAction::class)->handle($administrateur, Client::factory()->create(), $this->lignes()), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($administrateur, $brouillon), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->requeteLivewire($instantane, [], 'encaisser')->assertForbidden();

        $this->assertSame([1, StatutFacture::Brouillon], [Facture::query()->count(), $brouillon->fresh()->statut]);
        $this->assertFalse($administrateur->tientUneCaisseDans($magasin));
    }

    /**
     * Un administrateur de deux magasins à qui le propriétaire coche la
     * vente vend dans l'un et l'autre, avec une seule caisse ouverte à la
     * fois : il ferme sa caisse du premier avant d'encaisser dans le second
     * (précision 2, par compte).
     */
    public function test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], [], [...PermissionRegistry::modeleDeLAdministrateur(), ...PermissionRegistry::deVente()]);
        $vendre = fn (Magasin $magasin): Facture => app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($administrateur, Client::factory()->create(), $this->lignes()));

        $this->assertSame($lyon->id, $vendre($lyon)->magasin_id);
        $this->assertThrows(fn () => $vendre($villeurbanne), LogicException::class, 'Fermez d\'abord votre caisse de Lyon : vous n\'encaissez que dans un magasin à la fois.');
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $villeurbanne->id)->count());

        app(TenantContext::class)->runAs($lyon, fn () => app(FermerSaCaisseAction::class)->handle($administrateur, $administrateur));

        $this->assertSame($villeurbanne->id, $vendre($villeurbanne)->magasin_id);
        $this->assertTrue($administrateur->tientUneCaisseDans($lyon) && $administrateur->tientUneCaisseDans($villeurbanne));
    }

    /**
     * Un vendeur nommé administrateur perd ses cases de membre, vente
     * comprise : le formulaire de la nomination ne précoche que le modèle
     * de l'administrateur, sans aucune autorisation de vente, et il ne vend
     * plus.
     */
    public function test_un_vendeur_nomme_administrateur_ne_garde_aucune_autorisation_de_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->assertTrue($vendeur->tientUneCaisseDans($magasin));
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])->mountAction('nommerAdministrateur');
        $this->assertEmpty($page->get('mountedActionsData.0.autorisations_magasin_vente'));
        $page->setActionData(['portee' => 'tous'])->callMountedAction()->assertHasNoActionErrors();

        $administrateur = $vendeur->fresh();
        $this->assertSame(RoleSociete::Administrateur, $administrateur->role_societe);
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $administrateur->autorisationsDans($magasin))));
        $this->assertFalse($administrateur->tientUneCaisseDans($magasin));
        app(TenantContext::class)->set($magasin);
        $this->assertThrows(fn () => app(CreerFactureAction::class)->handle($administrateur, Client::factory()->create(), $this->lignes()), AuthorizationException::class, LecteurDesDroits::REFUS);
    }

    /**
     * Une ligne d'un article à 12,00 € TTC du magasin actif, sans suivi de
     * stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }
}
