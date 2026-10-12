<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseProvisoireAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Filament\App\Pages\Auth\ChangerMotDePasseProvisoire;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La société crée directement les comptes de son équipe, avec un mot de
 * passe provisoire remis en main propre, valable 72 heures, que la
 * personne change à sa première connexion, avant toute autre page ; son
 * adresse n'est pas confirmée pour autant. Une adresse déjà utilisée ne
 * sert jamais à un nouveau compte, et la réponse n'en dit rien.
 */
class MembresTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_vendeur_affecte_a_deux_magasins_avec_un_mot_de_passe_provisoire(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->mountAction('creerUnMembre')
            ->set('mountedActionsData.0.affectations', [
                ['magasin_id' => $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => RoleMagasin::Vendeur->permissionsParDefaut()],
                ['magasin_id' => $autre->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]],
            ])
            ->setActionData(['name' => 'Paul Vendeur', 'email' => 'paul@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $paul = User::query()->where('email', 'paul@example.test')->sole();
        $this->assertSame([RoleSociete::Membre, StatutCompte::Actif, true, $proprietaire->id], [$paul->role_societe, $paul->statut, $paul->doitChangerSonMotDePasse(), (int) $paul->cree_par]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_PROVISOIRE, $paul->password));
        $this->assertSame('2026-10-12 10:00:00', $paul->mot_de_passe_provisoire_expire_le->toDateTimeString());
        $this->assertNull($paul->email_verified_at);
        $this->assertFalse($paul->peutAgir());
        $affectations = Affectation::query()->where('user_id', $paul->id)->orderBy('magasin_id')->get();
        $this->assertSame([[$magasin->id, RoleMagasin::Vendeur], [$autre->id, RoleMagasin::Vendeur]], $affectations->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());
        $this->assertEqualsCanonicalizing(RoleMagasin::Vendeur->permissionsParDefaut(), $affectations[0]->autorisations());
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $affectations[1]->autorisations());
    }

    /**
     * À sa première connexion, toute page du panneau mène au changement de
     * son mot de passe ; une fois changé, le panneau s'ouvre.
     */
    public function test_le_membre_change_son_mot_de_passe_provisoire_a_sa_premiere_connexion_avant_toute_page(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);

        $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertRedirect(route('filament.app.mot-de-passe-provisoire'));
        $this->get(ProduitResource::getUrl('index', ['tenant' => $magasin]))->assertRedirect(route('filament.app.mot-de-passe-provisoire'));
        $this->get(route('filament.app.mot-de-passe-provisoire'))->assertOk()->assertSee('Choisissez votre mot de passe');

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(ChangerMotDePasseProvisoire::class)
            ->fillForm(['password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
            ->call('accepter')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $compte->refresh();
        $this->assertSame([false, null], [$compte->doitChangerSonMotDePasse(), $compte->mot_de_passe_provisoire_expire_le]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->password));
        $this->assertNull($compte->email_verified_at);
        $this->flushSession();
        $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertOk();
    }

    /**
     * Une page restée ouverte n'envoie plus rien tant que le mot de passe
     * est provisoire : la vraie requête Livewire suivante est refusée, et
     * rien ne s'enregistre.
     */
    public function test_une_requete_livewire_est_refusee_tant_que_le_mot_de_passe_est_provisoire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = Produit::factory()->create(['nom' => 'Origine', 'suivi_stock' => false]);
        $page = $this->actingAs($proprietaire)->get(ProduitResource::getUrl('edit', ['record' => $produit, 'tenant' => $magasin]))->assertOk();
        $instantane = $this->instantaneLivewire($page->getContent(), 'edit-produit');
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->flushSession();

        $this->actingAs($compte)->requeteLivewire($instantane, ['data.nom' => 'Pirate'], 'save')->assertForbidden();

        $this->assertSame('Origine', Produit::query()->withoutGlobalScopes()->findOrFail($produit->id)->nom);
    }

    /**
     * Après 72 heures, le provisoire n'ouvre plus rien : la connexion
     * avec lui le dit sans ouvrir de session, et il ne se change plus.
     */
    public function test_un_mot_de_passe_provisoire_expire_apres_72_heures(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->travel(72)->hours();
        $this->travel(1)->minute();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $connexion = Livewire::test(Connexion::class)
            ->fillForm(['email' => $compte->email, 'password' => self::MOT_DE_PASSE_PROVISOIRE])
            ->call('authenticate');

        $this->assertSame([ChangerSonMotDePasseProvisoireAction::MESSAGE_EXPIRE], $connexion->errors()->get('data.email'));
        $this->assertGuest();
        $this->assertTrue($compte->refresh()->sonMotDePasseProvisoireAExpire());
        $this->assertThrows(fn () => app(ChangerSonMotDePasseProvisoireAction::class)->handle($compte, self::MOT_DE_PASSE_CHOISI), ValidationException::class, ChangerSonMotDePasseProvisoireAction::MESSAGE_EXPIRE);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_PROVISOIRE, $compte->refresh()->password));
    }

    /**
     * « Mot de passe oublié » réussi, par la page du lien : le mot de passe
     * choisi remplace le provisoire, son expiration s'efface, et le lien
     * suivi confirme l'adresse.
     */
    public function test_un_mot_de_passe_oublie_reussi_efface_le_mot_de_passe_provisoire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $jeton = Password::broker()->createToken($compte);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => $jeton])
            ->fillForm(['password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
            ->call('choisir')
            ->assertHasNoFormErrors();

        $compte->refresh();
        $this->assertSame([false, null], [$compte->doitChangerSonMotDePasse(), $compte->mot_de_passe_provisoire_expire_le]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->password));
        $this->assertNotNull($compte->email_verified_at);
        $this->assertTrue($compte->peutAgir());
    }

    /**
     * Le mot de passe provisoire se remet de la main à la main : aucun
     * e-mail ne le porte, et ni le journal des événements, ni le journal
     * d'audit, ni la ligne du compte ne le contiennent en clair.
     */
    public function test_le_mot_de_passe_provisoire_n_est_jamais_journalise_ni_envoye_par_e_mail(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $provisoire = 'provisoire-tres-secret-42';

        $compte = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], $provisoire, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        Notification::assertNothingSentTo($compte);
        $this->assertSame(0, DB::table('enregistrements_fiscaux')->where('contenu', 'like', "%{$provisoire}%")->count());
        $this->assertSame(0, DB::table('journal_audit')->where('nouvelles_valeurs', 'like', "%{$provisoire}%")->orWhere('anciennes_valeurs', 'like', "%{$provisoire}%")->count());
        $this->assertSame(0, DB::table('users')->where('password', $provisoire)->count());
        $this->assertNotEmpty($this->entreesDAudit($magasin->societe, ActionAuditee::MembreCree));
    }

    /**
     * Une adresse déjà utilisée ne sert jamais à un nouveau compte, quelle
     * qu'en soit la casse ou les espaces : la réponse est la même quel que
     * soit ce compte (d'une autre société, ou de la société sans que
     * l'auteur le voie), pour le propriétaire, un responsable et la
     * création d'un expert-comptable ; le compte existant ne change pas.
     */
    public function test_une_adresse_deja_utilisee_recoit_une_reponse_neutre_et_aucun_compte_n_est_touche(): void
    {
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@boutique-b.test']);
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $alice);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeurAilleurs = $this->membre($autre, RoleMagasin::Vendeur, null, ['name' => 'Bruno', 'email' => 'bruno@example.test']);
        $empreinteDAlice = $alice->refresh()->password;

        $tentatives = [
            'propriétaire' => fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Pirate', 'email' => ' Alice@Boutique-B.TEST '], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Observateur]]),
            'responsable' => fn () => app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Pirate', 'email' => 'alice@boutique-b.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]),
            'responsable, compte de la société qu\'il ne voit pas' => fn () => app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Pirate', 'email' => 'BRUNO@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]),
            'expert-comptable' => fn () => app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Pirate', 'email' => 'alice@boutique-b.test'], self::MOT_DE_PASSE_PROVISOIRE),
            'administrateur' => fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Pirate', 'email' => 'alice@boutique-b.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [], []),
        ];

        foreach ($tentatives as $cas => $tentative) {
            try {
                $tentative();
                $this->fail("La création aurait dû être refusée : {$cas}.");
            } catch (ValidationException $refus) {
                $this->assertSame(['email' => [CreationDesComptes::MESSAGE_ADRESSE_INUTILISABLE]], $refus->errors(), $cas);
            }
        }

        $alice->refresh();
        $this->assertSame(['Alice', $empreinteDAlice, RoleSociete::Proprietaire], [$alice->name, $alice->password, $alice->role_societe]);
        $this->assertSame([$autre->id], Affectation::query()->where('user_id', $vendeurAilleurs->id)->pluck('magasin_id')->all());
        $this->assertSame(0, Affectation::query()->where('user_id', $alice->id)->count());
        $this->assertSame(1, User::query()->where('email', 'alice@boutique-b.test')->count());
    }

    public function test_l_adresse_d_un_nouveau_membre_s_enregistre_en_minuscules_sans_espaces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $vendeur = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['name' => 'Paul', 'email' => '  Paul.Vendeur@Example.TEST '], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertSame('paul.vendeur@example.test', $vendeur->fresh()->email);
    }

    public function test_un_mot_de_passe_provisoire_trop_court_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(
            fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], 'court', [$magasin->id => ['role' => RoleMagasin::Vendeur]]),
            ValidationException::class,
            CreationDesComptes::MESSAGE_PROVISOIRE_TROP_COURT,
        );
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->mountAction('creerUnMembre')
            ->set('mountedActionsData.0.affectations', [['magasin_id' => $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => []]])
            ->setActionData(['name' => 'Paul', 'email' => 'paul@example.test', 'mot_de_passe_provisoire' => 'court'])
            ->callMountedAction()
            ->assertHasActionErrors(['mot_de_passe_provisoire']);

        $this->assertFalse(User::query()->where('email', 'paul@example.test')->exists());
    }

    /**
     * Les droits donnés à la création s'inscrivent au journal des
     * événements de chaque magasin du compte, une fois chacun, et en une
     * seule entrée du journal d'audit.
     */
    public function test_la_creation_inscrit_les_droits_dans_chaque_magasin_en_un_evenement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);

        $paul = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [
            $magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]],
            $autre->id => ['role' => RoleMagasin::Observateur, 'autorisations' => [PermissionRegistry::PRODUIT_VIEW]],
        ]);

        foreach ([[$magasin, 'vendeur', [PermissionRegistry::FACTURE_VIEW]], [$autre, 'observateur', [PermissionRegistry::PRODUIT_VIEW]]] as [$chacun, $role, $autorisations]) {
            $siens = array_values(array_filter($this->modificationsDesDroits($chacun), fn (array $details): bool => $details['membre']['id'] === $paul->id));
            $this->assertSame([[
                'membre' => ['id' => $paul->id, 'nom' => 'Paul'],
                'avant' => null,
                'apres' => ['role_societe' => 'membre', 'role_magasin' => $role, 'statut' => 'actif', 'permissions' => $autorisations],
            ]], $siens, $chacun->nom);
        }
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::MembreCree));
    }

    /**
     * Un magasin d'une autre société ne s'affecte jamais par l'action :
     * ni glissé parmi les magasins de sa société, ni avec la société de
     * l'autre ; aucun compte ne se crée.
     */
    public function test_un_magasin_d_une_autre_societe_ne_s_affecte_jamais(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $proprietaireA = $this->proprietaireDe($magasinA);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);

        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaireA, $magasinA->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasinB->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaireA, $magasinA->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasinA->id => ['role' => RoleMagasin::Vendeur], $magasinB->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaireA, $magasinB->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasinB->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);

        $this->assertFalse(User::query()->where('email', 'paul@example.test')->exists());
        $this->assertSame(0, Affectation::query()->where('magasin_id', $magasinB->id)->count());
    }

    /**
     * Aucun rôle de magasin ne donne la propriété, ni à la création ni à la
     * modification, et le rôle de société ne se remplit jamais par masse.
     */
    public function test_aucune_creation_ni_modification_ne_donne_la_propriete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test', 'role_societe' => 'proprietaire'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => 'proprietaire']]), LogicException::class, 'Rôle de magasin inconnu.');
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => ['role' => 'proprietaire']]), LogicException::class, 'Rôle de magasin inconnu.');
        $paul = app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test', 'role_societe' => 'proprietaire'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertSame(RoleSociete::Membre, $paul->refresh()->role_societe);
        $this->assertSame(RoleSociete::Membre, $vendeur->refresh()->role_societe);
        $this->assertSame([$proprietaire->id], User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->pluck('id')->all());
    }

    /**
     * L'adresse d'un compte suspendu ou retiré de la société ne sert pas à
     * en créer un autre : l'auteur qui le voit déjà est invité à le
     * réactiver ou à le réintégrer.
     */
    public function test_creer_un_compte_suspendu_de_la_societe_propose_de_le_reintegrer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Jean', 'email' => 'jean@example.test']);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        foreach (['paul@example.test', 'jean@example.test'] as $email) {
            $this->assertThrows(
                fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Copie', 'email' => $email], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Observateur]]),
                ValidationException::class,
                CreationDesComptes::MESSAGE_DEJA_DANS_LA_SOCIETE,
            );
        }

        $this->assertSame([StatutCompte::Suspendu, StatutCompte::Retire], [$suspendu->refresh()->statut, $retire->refresh()->statut]);
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $suspendu->id)->sole()->role);
    }

    /**
     * Au plus 20 comptes créés par jour et par société, contre les abus :
     * ceux d'un responsable et ceux des experts-comptables comptent.
     */
    public function test_le_vingt_et_unieme_compte_du_jour_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $this->expertComptable($magasin);
        app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Vendeur du responsable', 'email' => 'vendeur-du-responsable@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        for ($numero = 4; $numero <= CreationDesComptes::COMPTES_PAR_JOUR; $numero++) {
            app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => "Vendeur {$numero}", 'email' => "vendeur{$numero}@example.test"], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
        }

        foreach ([$proprietaire, $responsable] as $auteur) {
            $this->assertThrows(
                fn () => app(CreerMembreAction::class)->handle($auteur, $magasin->societe, ['name' => 'De trop', 'email' => 'de-trop@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]),
                ValidationException::class,
                CreationDesComptes::MESSAGE_LIMITE_DU_JOUR,
            );
        }
        $this->assertSame(CreationDesComptes::COMPTES_PAR_JOUR, User::query()->where('societe_id', $magasin->societe_id)->whereNotNull('cree_par')->count());

        $this->travel(1)->day();
        $this->assertTrue(app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Le lendemain', 'email' => 'de-trop@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]])->exists);
    }

    /**
     * Le compte que rend l'action se lit comme la base le donne : ses
     * questions répondent sans erreur, dès sa création.
     */
    public function test_un_compte_tout_juste_cree_repond_sans_erreur_et_comme_relu(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $compte = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
        $relu = User::query()->findOrFail($compte->id);

        $this->assertSame(
            [$relu->doitChangerSonMotDePasse(), $relu->estActif(), $relu->estMembre(), $relu->peutAgir(), $relu->peutRefuserSonCompte(), $relu->aConfirmeSonAdresse()],
            [$compte->doitChangerSonMotDePasse(), $compte->estActif(), $compte->estMembre(), $compte->peutAgir(), $compte->peutRefuserSonCompte(), $compte->aConfirmeSonAdresse()],
        );
        $this->assertSame([true, true, true, false, true, false], [$compte->doitChangerSonMotDePasse(), $compte->estActif(), $compte->estMembre(), $compte->peutAgir(), $compte->peutRefuserSonCompte(), $compte->aConfirmeSonAdresse()]);
    }

    public function test_un_compte_cree_par_la_societe_n_a_pas_d_adresse_confirmee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $comptes = [
            app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Membre', 'email' => 'membre@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]),
            app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Responsable', 'email' => 'responsable@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]),
            app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Administrateur', 'email' => 'administrateur@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [], []),
            app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Expert', 'email' => 'expert@example.test'], self::MOT_DE_PASSE_PROVISOIRE),
        ];

        foreach ($comptes as $compte) {
            $compte->refresh();
            $this->assertNull($compte->email_verified_at, $compte->email);
            $this->assertFalse($compte->aConfirmeSonAdresse(), $compte->email);
            $this->assertTrue($compte->peutRefuserSonCompte(), $compte->email);
        }
    }

    /**
     * L'aide des tests suit le vrai parcours : le compte agit, son mot de
     * passe choisi, sans que son adresse soit confirmée.
     */
    public function test_le_membre_cree_par_l_aide_de_test_agit_et_n_a_pas_d_adresse_confirmee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertSame([true, false, null], [$vendeur->peutAgir(), $vendeur->doitChangerSonMotDePasse(), $vendeur->email_verified_at]);
        $this->assertTrue($vendeur->peutOuvrir($magasin));
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
        $this->assertSame($this->proprietaireDe($magasin)->id, (int) $vendeur->cree_par);
        $this->assertInstanceOf(Magasin::class, $magasin);
    }
}
