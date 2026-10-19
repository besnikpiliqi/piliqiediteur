<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\PermissionRegistry;
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
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La société crée directement les comptes de son équipe, un compte par
 * magasin : son identifiant de connexion, tiré au hasard, est aussi son
 * premier mot de passe, remis en main propre, sans expiration ni
 * changement obligatoire ; aucune adresse e-mail ne se saisit à la
 * création, et l'adresse du compte n'est jamais confirmée pour autant.
 */
class MembresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire crée, depuis l'espace de la société, le compte d'un
     * vendeur pour un magasin : son identifiant est son premier mot de
     * passe, sans expiration ; le compte agit aussitôt, dans ce seul
     * magasin.
     */
    public function test_le_proprietaire_cree_un_vendeur_pour_un_magasin_avec_son_identifiant_pour_premier_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->mountAction('creerUnMembre')
            ->setActionData(['magasin_id' => $autre->id, 'name' => 'Paul Vendeur', 'role' => RoleMagasin::Vendeur->value])
            ->set('mountedActionsData.0.autorisations', [])
            ->setActionData(['autorisations' => [PermissionRegistry::FACTURE_VIEW], 'autorisations_vente' => []])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionMounted('compteCree');

        $paul = User::query()->where('name', 'Paul Vendeur')->sole();
        $identifiant = (string) $paul->identifiantDeConnexion();
        $this->assertSame([RoleSociete::Membre, StatutCompte::Actif, true, (int) $proprietaire->id, null], [$paul->role_societe, $paul->statut, $paul->mot_de_passe_est_l_identifiant, (int) $paul->cree_par, $paul->email]);
        $this->assertTrue(Hash::check($identifiant, $paul->password));
        $this->assertNull($paul->email_verified_at);
        $this->assertTrue($paul->peutAgir());
        $this->assertSame([[(int) $autre->id, RoleMagasin::Vendeur]], Affectation::query()->where('user_id', $paul->id)->get()->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $paul->autorisationsDans($autre));
        $this->assertSame([(int) $autre->id, false], [(int) $paul->magasinDeSonIdentifiant()?->id, $paul->peutOuvrir($magasin)]);

        $this->travel(30)->days();
        $this->assertTrue(Hash::check($identifiant, $paul->refresh()->password));
        $this->assertTrue($paul->peutAgir());
    }

    /**
     * Le premier mot de passe, l'identifiant, se remet de la main à la
     * main : aucun e-mail ne le porte, et ni le journal des événements, ni
     * le journal d'audit, ni la ligne du compte ne le contiennent en clair.
     */
    public function test_le_premier_mot_de_passe_n_est_jamais_journalise_ni_envoye_par_e_mail(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $compte = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur);
        $identifiant = (string) $compte->identifiantDeConnexion();

        Notification::assertNothingSentTo($compte);
        $this->assertSame(0, DB::table('enregistrements_fiscaux')->where('contenu', 'like', "%{$identifiant}%")->count());
        $this->assertSame(0, DB::table('journal_audit')->where('nouvelles_valeurs', 'like', "%{$identifiant}%")->orWhere('anciennes_valeurs', 'like', "%{$identifiant}%")->count());
        $this->assertSame(0, DB::table('users')->where('password', $identifiant)->count());
        $this->assertNotEmpty($this->entreesDAudit($magasin->societe, ActionAuditee::MembreCree));
    }

    /**
     * Les droits donnés à la création s'inscrivent au journal des
     * événements du magasin du compte, une fois, et en une seule entrée du
     * journal d'audit ; aucun autre magasin n'en garde trace.
     */
    public function test_la_creation_inscrit_les_droits_dans_son_magasin_en_un_evenement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $avantDansLAutre = count($this->modificationsDesDroits($autre));

        $paul = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $siens = array_values(array_filter($this->modificationsDesDroits($magasin), fn (array $details): bool => $details['membre']['id'] === $paul->id));
        $this->assertSame([[
            'membre' => ['id' => $paul->id, 'nom' => 'Paul'],
            'avant' => null,
            'apres' => ['role_societe' => 'membre', 'role_magasin' => 'vendeur', 'statut' => 'actif', 'permissions' => [PermissionRegistry::FACTURE_VIEW]],
        ]], $siens);
        $this->assertCount($avantDansLAutre, $this->modificationsDesDroits($autre));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::MembreCree));
    }

    /**
     * Un magasin d'une autre société ne reçoit jamais de compte par
     * l'action : aucun compte ne se crée.
     */
    public function test_un_magasin_d_une_autre_societe_ne_s_affecte_jamais(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $proprietaireA = $this->proprietaireDe($magasinA);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);

        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaireA, $magasinB, ['name' => 'Paul'], RoleMagasin::Vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaireA, $magasinB, ['name' => 'Paul'], RoleMagasin::Responsable), AuthorizationException::class);

        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
        $this->assertSame(0, Affectation::withoutGlobalScopes()->where('magasin_id', $magasinB->id)->count());
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

        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul', 'role_societe' => 'proprietaire'], 'proprietaire'), LogicException::class, 'Rôle de magasin inconnu.');
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, 'proprietaire'), LogicException::class, 'Rôle de magasin inconnu.');
        $paul = app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul', 'role_societe' => 'proprietaire'], RoleMagasin::Vendeur);

        $this->assertSame(RoleSociete::Membre, $paul->refresh()->role_societe);
        $this->assertSame(RoleSociete::Membre, $vendeur->refresh()->role_societe);
        $this->assertSame([$proprietaire->id], User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->pluck('id')->all());
    }

    /**
     * Un ancien compte du même nom dans ce magasin est proposé avant d'en
     * créer un autre : suspendu, la fenêtre invite à le réactiver ; retiré,
     * elle propose de le réintégrer, avec son identifiant et son historique,
     * sans créer de second compte.
     */
    public function test_creer_un_compte_du_nom_d_un_compte_suspendu_ou_retire_du_magasin_propose_de_le_reactiver_ou_de_le_reintegrer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Jean Durand']);
        $identifiantDuRetire = $retire->identifiantDeConnexion();
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);
        $comptes = User::query()->where('societe_id', $magasin->societe_id)->count();

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => ' paul martin '])
            ->assertSee('Un compte suspendu porte déjà ce nom dans ce magasin')
            ->assertFormFieldIsHidden('reprendre', 'mountedActionForm');
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => 'Jean Durand', 'role' => RoleMagasin::Observateur->value])
            ->assertFormFieldIsVisible('reprendre', 'mountedActionForm')
            ->setActionData(['reprendre' => (string) $retire->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([StatutCompte::Suspendu, StatutCompte::Actif], [$suspendu->refresh()->statut, $retire->refresh()->statut]);
        $this->assertSame([RoleMagasin::Observateur, $identifiantDuRetire], [Affectation::query()->where('user_id', $retire->id)->sole()->role, $retire->identifiantDeConnexion()]);
        $this->assertSame($comptes, User::query()->where('societe_id', $magasin->societe_id)->count());
    }

    /**
     * Au plus 20 comptes créés par jour et par auteur, plus 10 par magasin
     * réel ouvert de la société, contre les abus : le compte de trop est
     * refusé à son auteur, un autre auteur crée encore, et le lendemain la
     * limite repart.
     */
    public function test_le_compte_de_trop_du_jour_est_refuse_a_son_auteur(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $limite = CreationDesComptes::COMPTES_PAR_JOUR + CreationDesComptes::COMPTES_PAR_MAGASIN_ET_PAR_JOUR;
        $dejaCrees = User::query()->where('cree_par', $proprietaire->id)->count();

        for ($numero = $dejaCrees + 1; $numero <= $limite; $numero++) {
            app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => "Vendeur {$numero}"], RoleMagasin::Vendeur);
        }

        $this->assertThrows(
            fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'De trop'], RoleMagasin::Vendeur),
            ValidationException::class,
            CreationDesComptes::messageDeLaLimite($limite),
        );
        $this->assertSame($limite, User::query()->where('cree_par', $proprietaire->id)->count());
        $this->assertTrue(app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Par le responsable'], RoleMagasin::Vendeur)->exists);

        $this->travel(1)->day();
        $this->assertTrue(app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Le lendemain'], RoleMagasin::Vendeur)->exists);
    }

    /**
     * Le compte que rend l'action se lit comme la base le donne : ses
     * questions répondent sans erreur, dès sa création.
     */
    public function test_un_compte_tout_juste_cree_repond_sans_erreur_et_comme_relu(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $compte = app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur);
        $relu = User::query()->findOrFail($compte->id);

        $this->assertSame(
            [$relu->mot_de_passe_est_l_identifiant, $relu->estActif(), $relu->estMembre(), $relu->peutAgir(), $relu->email_verified_at, $relu->identifiantDeConnexion()],
            [$compte->mot_de_passe_est_l_identifiant, $compte->estActif(), $compte->estMembre(), $compte->peutAgir(), $compte->email_verified_at, $compte->identifiantDeConnexion()],
        );
        $this->assertSame([true, true, true, true, null], [$compte->mot_de_passe_est_l_identifiant, $compte->estActif(), $compte->estMembre(), $compte->peutAgir(), $compte->email_verified_at]);
    }

    /**
     * Aucun compte créé par la société n'a d'adresse, ni donc d'adresse
     * confirmée : membre, responsable, administrateur ou expert-comptable.
     */
    public function test_un_compte_cree_par_la_societe_n_a_pas_d_adresse_confirmee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $comptes = [
            app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Membre'], RoleMagasin::Vendeur),
            app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Responsable'], RoleMagasin::Responsable),
            app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Administrateur'], null, [], []),
            app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Expert']),
        ];

        foreach ($comptes as $compte) {
            $compte->refresh();
            $this->assertSame([null, null], [$compte->email, $compte->email_verified_at], $compte->name);
            $this->assertTrue($compte->mot_de_passe_est_l_identifiant, $compte->name);
        }
    }

    /**
     * L'aide des tests suit le vrai parcours : le compte agit, son mot de
     * passe choisi dans son profil, sans adresse confirmée.
     */
    public function test_le_membre_cree_par_l_aide_de_test_agit_et_n_a_pas_d_adresse_confirmee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertSame([true, false, null], [$vendeur->peutAgir(), $vendeur->mot_de_passe_est_l_identifiant, $vendeur->email_verified_at]);
        $this->assertTrue($vendeur->peutOuvrir($magasin));
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
        $this->assertSame($this->proprietaireDe($magasin)->id, (int) $vendeur->cree_par);
        $this->assertInstanceOf(Magasin::class, $magasin);
    }
}
