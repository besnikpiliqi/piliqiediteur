<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\App\Resources\MembreEquipeResource\Pages\CreateMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\EditMembreEquipe;
use App\Filament\App\Resources\MembreEquipeResource\Pages\ListMembreEquipes;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Le rôle coche d'office les autorisations de son modèle ; le
     * propriétaire en décoche une (l'envoi des factures par e-mail) et en
     * coche une autre (la création d'avoirs) : le vendeur a exactement ce
     * qui est coché.
     */
    public function test_le_proprietaire_coche_et_decoche_les_autorisations_d_un_nouveau_vendeur(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $composant = Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Paul Vendeur',
            'email' => 'paul@example.test',
            'password' => 'mot-de-passe-sur',
            'role' => RoleMagasin::Vendeur->value,
        ]);
        $autorisations = [
            ...array_diff($composant->get('data.permissions'), [PermissionRegistry::FACTURE_ENVOYER]),
            PermissionRegistry::AVOIR_CREER,
        ];

        $composant->set('data.permissions', $autorisations)
            ->call('create')
            ->assertHasNoFormErrors();

        $vendeur = User::where('email', 'paul@example.test')->firstOrFail();
        app(TenantContext::class)->set($magasin);
        $this->assertFalse($vendeur->can(PermissionRegistry::FACTURE_ENVOYER));
        $this->assertTrue($vendeur->can(PermissionRegistry::AVOIR_CREER));
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_CREATE));
    }

    /**
     * Changer de rôle coche de nouveau les autorisations du modèle du
     * nouveau rôle.
     */
    public function test_changer_de_role_coche_le_modele_du_nouveau_role(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        $composant = Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->set('data.role', RoleMagasin::Comptable->value);

        $autorisations = $composant->get('data.permissions');
        sort($autorisations);
        $this->assertSame([
            'avoir.envoyer', 'avoir.view', 'avoir_fournisseur.view',
            'caisse_securisee.consulter', 'client.view',
            'cloture_caisse.view', 'cloture_caisse.view_toutes',
            'devis.view',
            'facture.envoyer', 'facture.view', 'facture.view_toutes', 'facture_achat.view',
            'fournisseur.view',
            'paiement.creer', 'paiement.view',
            'produit.view', 'produit.voir_valeur_stock',
        ], $autorisations);
    }

    /**
     * Le propriétaire peut cocher chaque autorisation pour un membre,
     * paramètres du magasin compris, sous son libellé : aucune ne lui
     * manque.
     */
    public function test_le_proprietaire_peut_cocher_chaque_autorisation_sous_son_libelle(): void
    {
        $this->connecterLeProprietaire();
        $toutes = PermissionRegistry::toutes();
        sort($toutes);

        Livewire::test(CreateMembreEquipe::class)
            ->assertFormFieldExists('permissions', function (CheckboxList $autorisations) use ($toutes): bool {
                $proposees = array_keys($autorisations->getOptions());
                sort($proposees);

                return $proposees === $toutes
                    && $autorisations->getOptions()[PermissionRegistry::PARAMETRES_GERER] === 'Paramètres du magasin : identité, TVA, échéance et mentions des factures'
                    && $autorisations->getOptions()[PermissionRegistry::FACTURE_ENVOYER] === 'Factures : envoyer par e-mail et relancer';
            })
            ->assertFormFieldIsEnabled('permissions');
    }

    /**
     * Un administrateur voit les autorisations sans pouvoir les changer :
     * même par une requête forgée, il ne donne jamais plus que le modèle
     * du rôle.
     */
    public function test_un_administrateur_cree_un_membre_avec_le_seul_modele_de_son_role(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $this->connecter($this->membre($magasin, RoleMagasin::Administrateur), $magasin);

        Livewire::test(CreateMembreEquipe::class)
            ->assertFormFieldIsDisabled('permissions')
            ->fillForm([
                'name' => 'Paul Vendeur',
                'email' => 'paul@example.test',
                'password' => 'mot-de-passe-sur',
                'role' => RoleMagasin::Vendeur->value,
            ])
            ->set('data.permissions', [PermissionRegistry::FACTURE_VIEW_TOUTES])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendeur = User::where('email', 'paul@example.test')->firstOrFail();
        $this->assertSame([
            'client.create', 'client.update', 'client.view',
            'devis.create', 'devis.envoyer', 'devis.update', 'devis.view',
            'facture.create', 'facture.emettre', 'facture.envoyer', 'facture.view',
            'paiement.creer', 'paiement.view',
            'produit.view',
        ], $this->autorisations($vendeur, $magasin));
    }

    /**
     * Le propriétaire n'a laissé à son administrateur que la gestion de
     * l'équipe et ses propres factures : la liste, qu'il ne peut pas
     * changer, montre que le compte « Administrateur » qu'il crée n'aura
     * que ces autorisations-là, et le compte n'a bien qu'elles.
     */
    public function test_un_membre_qui_gere_l_equipe_cree_un_compte_qui_n_a_pas_plus_d_autorisations_que_lui(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur, [
            PermissionRegistry::EQUIPE_VIEW, PermissionRegistry::EQUIPE_CREER, PermissionRegistry::FACTURE_VIEW,
        ]);
        $this->connecter($administrateur, $magasin);

        $composant = Livewire::test(CreateMembreEquipe::class)->fillForm([
            'name' => 'Second compte',
            'email' => 'second@example.test',
            'password' => 'mot-de-passe-sur',
            'role' => RoleMagasin::Administrateur->value,
        ]);
        $affichees = $composant->get('data.permissions');
        sort($affichees);
        $composant->call('create')->assertHasNoFormErrors();

        $this->assertSame(['equipe.creer', 'equipe.view', 'facture.view'], $affichees);
        $this->assertSame(['equipe.creer', 'equipe.view', 'facture.view'], $this->autorisations(User::where('email', 'second@example.test')->sole(), $magasin));
    }

    /**
     * Un vendeur à qui le propriétaire a coché « Équipe : modifier » pour
     * suspendre ses collègues n'ouvre pas sa propre ligne : il s'y
     * donnerait le rôle Administrateur et tout son modèle.
     */
    public function test_un_membre_qui_gere_l_equipe_n_ouvre_pas_sa_propre_ligne(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::EQUIPE_VIEW, PermissionRegistry::EQUIPE_MODIFIER]);
        $saLigne = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $this->connecter($vendeur, $magasin);

        Livewire::test(ListMembreEquipes::class)
            ->assertTableActionHidden('edit', $saLigne)
            ->assertTableActionHidden('retirer', $saLigne);
        Livewire::test(EditMembreEquipe::class, ['record' => $saLigne->getRouteKey()])
            ->assertForbidden();

        $this->assertSame(RoleMagasin::Vendeur, $saLigne->fresh()->role);
        $this->assertSame(['equipe.modifier', 'equipe.view'], $this->autorisations($vendeur, $magasin));
    }

    /**
     * Le propriétaire a coché « Avoirs : créer » pour un vendeur, sans
     * « Factures : envoyer par e-mail et relancer ». L'administrateur voit
     * son rôle sans pouvoir le changer : même par une requête forgée, le
     * vendeur garde son rôle et les choix du propriétaire, que le modèle
     * d'un autre rôle effacerait.
     */
    public function test_un_administrateur_ne_change_pas_le_role_d_un_membre(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $this->connecter($administrateur, $magasin);

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->assertFormFieldIsDisabled('role')
            ->set('data.role', RoleMagasin::Comptable->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(RoleMagasin::Vendeur, $membre->fresh()->role);
        $this->assertSame(['avoir.creer', 'facture.view'], $this->autorisations($vendeur, $magasin));
    }

    /**
     * Le propriétaire a coché « Paramètres du magasin » pour son
     * administrateur, et « Équipe : modifier » pour un vendeur : le
     * vendeur ne donne pas à l'administrateur un mot de passe de son
     * choix, qui lui ouvrirait l'identité et la TVA du magasin.
     */
    public function test_un_membre_qui_gere_l_equipe_ne_donne_pas_de_mot_de_passe_a_un_membre_plus_autorise_que_lui(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Marc', 'email' => 'marc@example.test', 'password' => 'mot-de-passe-de-marc',
        ], RoleMagasin::Administrateur, [...RoleMagasin::Administrateur->permissionsParDefaut(), PermissionRegistry::PARAMETRES_GERER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut(),
            PermissionRegistry::EQUIPE_VIEW,
            PermissionRegistry::EQUIPE_MODIFIER,
        ]);
        $this->connecter($vendeur, $magasin);

        Livewire::test(ListMembreEquipes::class)
            ->callTableAction('reinitialiser_mot_de_passe', $magasin->membres()->where('user_id', $administrateur->id)->firstOrFail(), data: [
                'nouveau_mot_de_passe' => 'choisi-par-le-vendeur',
            ])
            ->assertNotified('Seul le propriétaire peut donner un nouveau mot de passe à ce membre : il a des autorisations que vous n\'avez pas.');

        $this->assertTrue(Hash::check('mot-de-passe-de-marc', $administrateur->fresh()->password));
    }

    public function test_le_proprietaire_donne_un_nouveau_mot_de_passe_a_un_vendeur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
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
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
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
        $administrateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
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
     * « Équipe : voir » ne donne pas « Équipe : modifier » : ni nouveau mot
     * de passe (qui ouvrirait le compte de l'administrateur), ni retrait,
     * même en appelant les actions masquées par une requête forgée.
     */
    public function test_un_membre_qui_voit_seulement_lequipe_ne_change_pas_le_mot_de_passe_dun_administrateur_ni_ne_le_retire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $administrateur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-admin',
        ], RoleMagasin::Administrateur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut(),
            PermissionRegistry::EQUIPE_VIEW,
        ]);
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

    public function test_le_proprietaire_coche_une_autorisation_de_plus_pour_un_vendeur(): void
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

        $composant = Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()]);
        $composant->set('data.permissions', [...$composant->get('data.permissions'), PermissionRegistry::AVOIR_VIEW])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MembreEquipeResource::getUrl('index'));

        app(TenantContext::class)->set($magasin);
        $vendeur->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertTrue($vendeur->can(PermissionRegistry::AVOIR_VIEW), 'L\'autorisation cochée doit maintenant être accordée.');
        // Cocher « voir les avoirs » ne donne pas « créer un avoir » : un
        // vendeur reste sans cette autorisation tant qu'elle n'est pas
        // cochée.
        $this->assertFalse($vendeur->can(PermissionRegistry::AVOIR_CREER));
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_CREATE));
    }

    /**
     * Un administrateur voit les autorisations d'un membre sans pouvoir
     * les changer, même par une requête forgée.
     */
    public function test_un_administrateur_ne_change_pas_les_autorisations_d_un_membre(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $this->connecter($administrateur, $magasin);

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->assertFormFieldIsDisabled('permissions')
            ->set('data.permissions', [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_VIEW])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['facture.view'], $this->autorisations($vendeur, $magasin));
    }

    /**
     * Sans liste d'autorisations (un administrateur ne l'envoie pas), un
     * simple changement de statut garde celles que le propriétaire a
     * cochées.
     */
    public function test_changer_le_statut_sans_changer_le_role_garde_les_autorisations_cochees(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $this->connecter($administrateur, $magasin);

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->fillForm(['statut' => StatutMembreMagasin::Suspendu->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(StatutMembreMagasin::Suspendu, $membre->fresh()->statut);
        $this->assertSame(['avoir.creer', 'facture.view'], $this->autorisations($vendeur, $magasin));
    }

    public function test_retirer_un_membre_efface_ses_autorisations(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        Livewire::test(ListMembreEquipes::class)
            ->callTableAction('retirer', $membre)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($membre);
        $this->assertDatabaseMissing('model_has_permissions', ['model_type' => $vendeur->getMorphClass(), 'model_id' => $vendeur->id]);
    }

    /**
     * Rôle, statut et autorisations changent ensemble : un seul événement
     * du journal (JET 130), avec les droits d'avant et d'après.
     */
    public function test_une_modification_s_inscrit_en_un_seul_evenement_avec_les_droits_avant_et_apres(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $modificationsAvant = $this->modificationsDesDroits()->count();

        Livewire::test(EditMembreEquipe::class, ['record' => $membre->getRouteKey()])
            ->set('data.role', RoleMagasin::Comptable->value)
            ->set('data.statut', StatutMembreMagasin::Suspendu->value)
            ->set('data.permissions', [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES])
            ->call('save')
            ->assertHasNoFormErrors();

        $modifications = $this->modificationsDesDroits();
        $this->assertCount($modificationsAvant + 1, $modifications);
        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => $vendeur->name],
            'avant' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['facture.view']],
            'apres' => ['role' => 'accountant', 'statut' => 'suspended', 'permissions' => ['facture.view', 'facture.view_toutes']],
        ], $modifications->last()->contenuSigne()['donnees']['details']);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->connecter($proprietaire, $magasin);

        return $magasin;
    }

    private function connecter(User $utilisateur, Magasin $magasin): void
    {
        $this->actingAs($utilisateur);
        Filament::setTenant($magasin);
        app(TenantContext::class)->set($magasin);
    }

    /**
     * Les autorisations données directement à l'utilisateur dans ce
     * magasin, par ordre alphabétique.
     *
     * @return list<string>
     */
    private function autorisations(User $utilisateur, Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs(
            $magasin,
            fn (): array => $utilisateur->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        );
    }

    /**
     * Les modifications des droits (JET 130) du journal du magasin actif,
     * dans l'ordre de sa chaîne.
     *
     * @return Collection<int, EnregistrementFiscal>
     */
    private function modificationsDesDroits(): Collection
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ModificationDesDroits->value)
            ->orderBy('sequence')
            ->get();
    }
}
