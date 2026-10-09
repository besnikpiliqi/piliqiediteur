<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\DefinirPermissionsSupplementairesMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\App\Resources\MembreEquipeResource\Pages\CreateMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\EditMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\ListMembreEquipes;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MembreEquipeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_vendeur_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)
            ->fillForm([
                'name' => 'Paul Vendeur',
                'email' => 'paul@example.test',
                'password' => 'mot-de-passe-sur',
                'role' => RoleMagasin::Vendeur->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendeur = User::where('email', 'paul@example.test')->firstOrFail();
        $this->assertNotNull($vendeur->email_verified_at);
        $this->assertTrue($vendeur->appartientA($magasin));
    }

    /**
     * Le propriétaire de B saisissait l'adresse du propriétaire de A avec
     * un mot de passe de son choix, puis ouvrait le magasin A avec.
     */
    public function test_ladresse_du_compte_dun_autre_magasin_est_refusee_sur_le_champ_email(): void
    {
        $alice = User::factory()->create(['email' => 'alice@boutique-a.test', 'password' => 'mot-de-passe-dalice']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $alice);
        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);
        $this->actingAs($proprietaireB);
        Filament::setTenant($magasinB);
        app(TenantContext::class)->set($magasinB);

        Livewire::test(CreateMembreEquipe::class)
            ->fillForm([
                'name' => 'Pirate',
                'email' => 'alice@boutique-a.test',
                'password' => 'choisi-par-b',
                'role' => RoleMagasin::Observateur->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée pour un nouveau membre.']);

        $this->assertTrue(Hash::check('mot-de-passe-dalice', $alice->fresh()->password));
        $this->assertSame(1, $magasinB->membres()->count());
    }

    public function test_un_mot_de_passe_trop_court_est_refuse_a_la_creation(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)
            ->fillForm([
                'name' => 'Paul Vendeur',
                'email' => 'paul@example.test',
                'password' => '1',
                'role' => RoleMagasin::Vendeur->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertFalse(User::where('email', 'paul@example.test')->exists());
    }

    public function test_le_proprietaire_donne_un_nouveau_mot_de_passe_a_un_vendeur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'ancien-mot-de-passe',
        ], RoleMagasin::Vendeur);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListMembreEquipes::class)
            ->callTableAction('reinitialiser_mot_de_passe', $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail(), data: [
                'nouveau_mot_de_passe' => 'nouveau-mot-de-passe',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_un_nouveau_mot_de_passe_trop_court_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'ancien-mot-de-passe',
        ], RoleMagasin::Vendeur);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListMembreEquipes::class)
            ->callTableAction('reinitialiser_mot_de_passe', $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail(), data: [
                'nouveau_mot_de_passe' => '1',
            ])
            ->assertHasTableActionErrors(['nouveau_mot_de_passe']);

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $vendeur->fresh()->password));
    }

    /**
     * « Nouveau mot de passe » ne demande pas le mot de passe actuel : sur
     * sa propre ligne, une session d'administrateur restée ouverte
     * suffirait à prendre son compte, même par une requête forgée.
     */
    public function test_un_administrateur_ne_change_pas_son_propre_mot_de_passe_depuis_lequipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-admin',
        ], RoleMagasin::Administrateur);
        $membreAdministrateur = $magasin->membres()->where('user_id', $administrateur->id)->firstOrFail();
        $this->actingAs($administrateur);
        Filament::setTenant($magasin);

        Livewire::test(ListMembreEquipes::class)
            ->assertTableActionHidden('reinitialiser_mot_de_passe', $membreAdministrateur)
            ->call('mountTableAction', 'reinitialiser_mot_de_passe', (string) $membreAdministrateur->getKey())
            ->set('mountedTableActionsData.0.nouveau_mot_de_passe', 'pris-sur-la-caisse-ouverte')
            ->call('callMountedTableAction');

        $this->assertTrue(Hash::check('mot-de-passe-admin', $administrateur->fresh()->password));
    }

    /**
     * Le refus de ReinitialiserMotDePasseMembreAction s'affiche en
     * notification, sans page d'erreur. Plus aucun écran ne rattache un
     * compte existant à un second magasin : ce compte partagé est construit
     * directement.
     */
    public function test_nouveau_mot_de_passe_dun_compte_rattache_a_un_autre_magasin_est_refuse(): void
    {
        $comptable = User::factory()->create(['password' => 'mot-de-passe-de-claire']);
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Cabinet de Claire'], $comptable);
        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);
        $magasinB->utilisateurs()->attach($comptable->id, ['role' => RoleMagasin::Comptable->value, 'statut' => StatutMembreMagasin::Actif->value, 'joined_at' => now()]);
        $this->actingAs($proprietaireB);
        Filament::setTenant($magasinB);
        app(TenantContext::class)->set($magasinB);

        Livewire::test(ListMembreEquipes::class)
            ->callTableAction('reinitialiser_mot_de_passe', $magasinB->membres()->where('user_id', $comptable->id)->firstOrFail(), data: [
                'nouveau_mot_de_passe' => 'choisi-par-b',
            ])
            ->assertNotified('Ce mot de passe ne se réinitialise pas depuis l\'équipe : la personne utilise « Mot de passe oublié » sur la page de connexion.');

        $this->assertTrue(Hash::check('mot-de-passe-de-claire', $comptable->fresh()->password));
        $this->assertTrue($comptable->fresh()->appartientA($magasinA));
    }

    /**
     * « Équipe : voir » ne donne pas « Équipe : modifier » : ni nouveau mot
     * de passe (qui ouvrirait le compte de l'administrateur), ni retrait,
     * même en appelant les actions masquées par une requête forgée.
     */
    public function test_un_membre_qui_voit_seulement_lequipe_ne_change_pas_le_mot_de_passe_dun_administrateur_ni_ne_le_retire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $administrateur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-admin',
        ], RoleMagasin::Administrateur);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        app(DefinirPermissionsSupplementairesMembreAction::class)->handle(
            $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail(),
            [PermissionRegistry::EQUIPE_VIEW],
        );
        $membreAdministrateur = $magasin->membres()->where('user_id', $administrateur->id)->firstOrFail();
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(ListMembreEquipes::class)
            ->assertCanSeeTableRecords([$membreAdministrateur])
            ->assertTableActionHidden('reinitialiser_mot_de_passe', $membreAdministrateur)
            ->assertTableActionHidden('retirer', $membreAdministrateur)
            ->call('mountTableAction', 'reinitialiser_mot_de_passe', (string) $membreAdministrateur->getKey())
            ->set('mountedTableActionsData.0.nouveau_mot_de_passe', 'pris-par-le-vendeur')
            ->call('callMountedTableAction')
            ->call('mountTableAction', 'retirer', (string) $membreAdministrateur->getKey())
            ->call('callMountedTableAction');

        $this->assertTrue(Hash::check('mot-de-passe-admin', $administrateur->fresh()->password));
        $this->assertModelExists($membreAdministrateur);
    }

    /**
     * CreerMembreMagasinAction refuse d'attribuer le rôle propriétaire
     * depuis la gestion d'équipe (voir son docblock) : le Select de ce
     * formulaire n'offre déjà que les rôles assignables, mais cette
     * exception est le seul rempart si un client contourne ce filtrage
     * (page restée ouverte, requête modifiée) -- avant la correction, elle
     * remontait telle quelle jusqu'à une page d'erreur brute au lieu d'une
     * notification (voir CreateMembreEquipe::handleRecordCreation()).
     */
    public function test_attribuer_le_role_proprietaire_via_creation_membre_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)
            ->fillForm([
                'name' => 'Usurpateur',
                'email' => 'usurpateur@example.test',
                'password' => 'mot-de-passe-sur',
                'role' => RoleMagasin::Vendeur->value,
            ])
            ->set('data.role', RoleMagasin::Proprietaire->value)
            ->call('create')
            ->assertNotified('Le rôle propriétaire ne peut pas être attribué via la gestion d\'équipe.');

        $this->assertSame(1, $magasin->membres()->count()); // uniquement le propriétaire d'origine
    }

    /**
     * Même garde-fou que CreerMembreMagasinAction côté création (voir le
     * test ci-dessus), mais côté modification : MettreAJourMembreMagasinAction
     * refuse de faire passer un membre au rôle propriétaire. Le Select de
     * ce formulaire n'offre déjà que les rôles assignables, mais cette
     * exception est le seul rempart si un client contourne ce filtrage --
     * avant la correction, elle remontait telle quelle jusqu'à une page
     * d'erreur brute (voir EditMembreEquipe::handleRecordUpdate()).
     */
    public function test_modifier_le_role_dun_membre_en_proprietaire_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur', 'email' => 'vendeur@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');
        $vendeur = User::where('email', 'vendeur@example.test')->firstOrFail();
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->fillForm(['role' => RoleMagasin::Vendeur->value, 'statut' => 'active'])
            ->set('data.role', RoleMagasin::Proprietaire->value)
            ->call('save')
            ->assertNotified('Le rôle propriétaire ne se modifie pas via la gestion d\'équipe.');

        $this->assertSame(RoleMagasin::Vendeur, $membre->fresh()->role);
    }

    public function test_un_vendeur_sans_permission_ne_peut_pas_acceder_a_la_gestion_dequipe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Simple', 'email' => 'simple@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');

        $vendeur = User::where('email', 'simple@example.test')->firstOrFail();

        $this->actingAs($vendeur);
        $this->assertFalse($vendeur->can(PermissionRegistry::EQUIPE_VIEW));

        Filament::setTenant($magasin);
        $this->get("/app/{$magasin->uuid}/membre-equipes")->assertForbidden();
    }

    public function test_le_proprietaire_napparait_pas_comme_modifiable_dans_la_liste(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $membreProprietaire = $magasin->membres()->where('user_id', $proprietaire->id)->firstOrFail();

        Livewire::test(ListMembreEquipes::class)
            ->assertTableActionHidden('reinitialiser_mot_de_passe', $membreProprietaire)
            ->assertTableActionHidden('retirer', $membreProprietaire);
    }

    public function test_le_proprietaire_accorde_une_permission_supplementaire_a_un_vendeur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Spécial', 'email' => 'special@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');
        $vendeur = User::where('email', 'special@example.test')->firstOrFail();
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        app(TenantContext::class)->set($magasin);
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_VIEW), 'Un vendeur ne voit pas les avoirs par défaut.');

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->fillForm([
                'role' => RoleMagasin::Vendeur->value,
                'statut' => 'active',
                'permissions_supplementaires' => [PermissionRegistry::AVOIR_VIEW],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MembreEquipeResource::getUrl('index'));

        app(TenantContext::class)->set($magasin);
        $vendeur->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertTrue($vendeur->can(PermissionRegistry::AVOIR_VIEW), 'La permission supplémentaire doit maintenant être accordée.');
        // Le rôle lui-même ne doit pas être affecté par cette permission
        // directe : un vendeur sans la permission dédiée reste incapable
        // de créer un avoir.
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_CREER));
    }

    public function test_un_administrateur_ne_peut_pas_modifier_les_permissions_supplementaires(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Admin Un', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Administrateur->value,
        ])->call('create');
        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Vendeur Cible', 'email' => 'cible@example.test', 'password' => 'mot-de-passe-sur', 'role' => RoleMagasin::Vendeur->value,
        ])->call('create');
        $vendeur = User::where('email', 'cible@example.test')->firstOrFail();
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        $this->actingAs($admin);
        Filament::setTenant($magasin);

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->assertFormFieldIsHidden('permissions_supplementaires');

        app(TenantContext::class)->set($magasin);
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_VIEW));
    }
}
