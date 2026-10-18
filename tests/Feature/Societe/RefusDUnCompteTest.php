<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\AccepterUnRattachementAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\CreerSaSocieteAvecUnCompteRetireAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\NommerAdministrateurAction;
use App\Domain\Tenancy\Actions\ProposerUnRattachementAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\PropositionDeRattachementMail;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\ChangerMotDePasseProvisoire;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un compte que la société a créé à une adresse se refuse par le titulaire
 * de cette adresse tant qu'elle n'a jamais été confirmée : à sa connexion
 * avec le mot de passe provisoire, ou par la page d'un lien envoyé à
 * l'adresse. Refusé, il est retiré comme par un retrait, son mot de passe
 * ne sert plus, ses sessions se ferment, son auteur (sinon le
 * propriétaire) est prévenu, et la société ne le réintègre plus.
 */
class RefusDUnCompteTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_compte_cree_par_la_societe_se_refuse_a_la_connexion_avec_le_mot_de_passe_provisoire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);

        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(ChangerMotDePasseProvisoire::class)
            ->assertActionVisible('refuserCeCompte')
            ->callAction('refuserCeCompte')
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertGuest();
        $this->assertSame([StatutCompte::Retire, (int) $compte->id], [$compte->refresh()->statut, (int) $compte->statut_change_par]);
        $this->assertSame(0, Affectation::query()->where('user_id', $compte->id)->count());
    }

    /**
     * Fermer la page du mot de passe provisoire n'est ni une acceptation
     * ni un refus : à la connexion suivante, le compte se refuse encore.
     */
    public function test_une_connexion_interrompue_ne_ferme_pas_le_droit_de_refus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->actingAs($compte)->get(route('filament.app.mot-de-passe-provisoire'))->assertOk();
        auth()->logout();
        $this->flushSession();

        $this->assertTrue($compte->refresh()->peutRefuserSonCompte());
        app(RefuserSonCompteAction::class)->handle($compte);
        $this->assertTrue($compte->refresh()->estRetire());
    }

    public function test_le_titulaire_de_l_adresse_refuse_par_mot_de_passe_oublie_meme_apres_l_expiration_du_provisoire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->travel(80)->hours();

        $this->refuserParLeLien($compte);

        $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);
        $this->assertNotNull($compte->email_verified_at);
    }

    /**
     * Quelqu'un qui connaissait le provisoire a choisi un mot de passe à la
     * place du titulaire : l'adresse n'étant jamais confirmée, le titulaire
     * refuse encore le compte par la page d'un lien.
     */
    public function test_le_titulaire_de_l_adresse_refuse_un_compte_deja_active_avec_le_mot_de_passe_provisoire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertTrue($compte->peutRefuserSonCompte());
        $this->refuserParLeLien($compte);

        $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);
        $this->assertFalse(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->password));
    }

    public function test_changer_le_mot_de_passe_provisoire_ne_confirme_pas_l_adresse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);

        $this->choisirSonMotDePasse($compte);

        $this->assertNull($compte->email_verified_at);
        $this->assertTrue($compte->peutRefuserSonCompte());
    }

    public function test_un_compte_dont_l_adresse_est_confirmee_ne_se_refuse_plus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, Password::broker()->createToken($compte), 'mon-propre-mot-de-passe');

        $this->assertFalse($compte->refresh()->peutRefuserSonCompte());
        $this->assertThrows(fn () => app(RefuserSonCompteAction::class)->handle($compte), AuthorizationException::class, RefuserSonCompteAction::MESSAGE_NON_REFUSABLE);
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin);
        $this->assertSame(StatutCompte::Actif, $compte->refresh()->statut);
    }

    /**
     * Le compte du propriétaire ne se refuse jamais, pas même s'il n'a
     * jamais confirmé son adresse : ni celui de l'inscription, ni
     * l'administrateur, créé par la société, à qui la propriété vient
     * d'être transmise.
     */
    public function test_le_proprietaire_ne_refuse_jamais_son_compte_meme_apres_un_transfert(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['email_verified_at' => null])->save();

        $this->assertFalse($proprietaire->peutRefuserSonCompte());
        $this->assertThrows(fn () => app(RefuserSonCompteAction::class)->handle($proprietaire), AuthorizationException::class, RefuserSonCompteAction::MESSAGE_NON_REFUSABLE);
        $this->assertSame([StatutCompte::Actif, RoleSociete::Proprietaire], [$proprietaire->refresh()->statut, $proprietaire->role_societe]);

        $administrateur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
        $this->assertTrue($administrateur->peutRefuserSonCompte());
        app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, 'password');

        $this->assertFalse($administrateur->refresh()->peutRefuserSonCompte());
        $this->assertThrows(fn () => app(RefuserSonCompteAction::class)->handle($administrateur), AuthorizationException::class, RefuserSonCompteAction::MESSAGE_NON_REFUSABLE);
        $this->assertSame([StatutCompte::Actif, RoleSociete::Proprietaire], [$administrateur->refresh()->statut, $administrateur->role_societe]);
    }

    /**
     * Un compte suspendu, ou retiré de son dernier magasin par la société,
     * dont l'adresse n'a jamais été confirmée se refuse par la page d'un
     * lien.
     */
    public function test_un_compte_suspendu_ou_sans_magasin_jamais_confirme_se_refuse_par_le_lien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $sansMagasin = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ModifierMembreAction::class)->handle($proprietaire, $sansMagasin, [$magasin->id => null]);

        foreach ([$suspendu, $sansMagasin] as $compte) {
            $this->refuserParLeLien($compte);
            $this->assertSame([StatutCompte::Retire, (int) $compte->id], [$compte->refresh()->statut, (int) $compte->statut_change_par], $compte->email);
        }
    }

    /**
     * Retiré par la société, un compte jamais confirmé se refuse encore ;
     * refusé, la société ne le réintègre plus sans son accord.
     */
    public function test_un_compte_retire_par_la_societe_jamais_confirme_se_refuse_et_ne_se_reintegre_plus_sans_son_accord(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $compte);
        $this->assertTrue($compte->refresh()->peutRefuserSonCompte());

        app(RefuserSonCompteAction::class)->handle($compte);

        $this->assertSame((int) $compte->id, (int) $compte->refresh()->statut_change_par);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrer($proprietaire, $compte, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);
    }

    public function test_un_compte_refuse_est_retire_avec_un_jet_130_par_magasin_un_audit_et_son_auteur_prevenu(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $compte = app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Jean', 'email' => 'jean@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Observateur]]);
        $creeParLeResponsable = app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        app(RefuserSonCompteAction::class)->handle($compte);
        app(RefuserSonCompteAction::class)->handle($creeParLeResponsable);

        foreach ([$magasin, $autre] as $chacun) {
            $siens = array_values(array_filter($this->modificationsDesDroits($chacun), fn (array $details): bool => $details['membre']['id'] === $compte->id));
            $this->assertSame(['actif', null], [end($siens)['avant']['statut'], end($siens)['apres']], $chacun->nom);
        }
        $this->assertCount(2, $this->entreesDAudit($magasin->societe, ActionAuditee::CompteRefuse));
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => str_contains($avis->texte, 'jean@example.test'));
        Notification::assertSentTo($responsable, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => str_contains($avis->texte, 'paul@example.test'));
        Notification::assertNotSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => str_contains($avis->texte, 'paul@example.test'));
    }

    /**
     * L'auteur du compte, parti de la société, n'est pas prévenu : le
     * propriétaire l'est à sa place.
     */
    public function test_l_auteur_parti_de_la_societe_n_est_pas_prevenu_et_le_proprietaire_l_est(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $compte = app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $responsable);

        app(RefuserSonCompteAction::class)->handle($compte);

        Notification::assertNotSentTo($responsable, AvisDeLEquipe::class);
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class);
    }

    public function test_un_administrateur_qui_refuse_son_compte_perd_ses_autorisations_de_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = app(CreerAdministrateurAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, PermissionRegistry::modeleDeLAdministrateur(), AutorisationsSociete::toutes());

        app(RefuserSonCompteAction::class)->handle($administrateur);

        $administrateur->refresh();
        $this->assertSame([RoleSociete::Membre, StatutCompte::Retire], [$administrateur->role_societe, $administrateur->statut]);
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
    }

    public function test_un_responsable_qui_refuse_son_compte_perd_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin, $autre);

        app(RefuserSonCompteAction::class)->handle($responsable);

        $this->assertSame(0, Affectation::query()->where('user_id', $responsable->id)->count());
        $this->assertFalse($responsable->refresh()->peutOuvrir($magasin));
        $this->assertFalse($responsable->peutOuvrir($autre));
    }

    /**
     * Ni le propriétaire, ni un responsable, ni un administrateur ne
     * réintègre un compte refusé : il ne revient que par un rattachement
     * qu'il accepte.
     */
    public function test_la_societe_ne_reintegre_pas_un_compte_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        app(RefuserSonCompteAction::class)->handle($compte);

        foreach ([[$proprietaire, RoleMagasin::Vendeur], [$responsable, RoleMagasin::Vendeur], [$administrateur, RoleMagasin::Responsable]] as [$auteur, $role]) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $compte, [$magasin->id => ['role' => $role]]), AuthorizationException::class);
        }
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrerCommeExpertComptable($proprietaire, $compte), AuthorizationException::class);
        $this->assertThrows(fn () => app(NommerAdministrateurAction::class)->handle($proprietaire, $compte, null, [], []), AuthorizationException::class);

        $this->assertSame([StatutCompte::Retire, RoleSociete::Membre], [$compte->refresh()->statut, $compte->role_societe]);
        $this->assertSame(0, Affectation::query()->where('user_id', $compte->id)->count());
    }

    /**
     * Refusé, le compte ne crée sa société qu'une fois son mot de passe
     * choisi par un lien envoyé à son adresse : son ancien mot de passe ne
     * sert plus.
     */
    public function test_un_compte_refuse_ne_cree_sa_societe_qu_apres_avoir_choisi_son_mot_de_passe_par_un_lien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);
        app(RefuserSonCompteAction::class)->handle($compte);
        app(TenantContext::class)->clear();
        $societe = ['siren' => SocieteFactory::sirenFictif()];

        $this->assertThrows(fn () => app(CreerSaSocieteAvecUnCompteRetireAction::class)->handle('paul@example.test', self::MOT_DE_PASSE_CHOISI, $societe), ValidationException::class, CreerSaSocieteAvecUnCompteRetireAction::MESSAGE_IDENTIFIANTS);
        $this->assertSame((int) $magasin->societe_id, (int) $compte->refresh()->societe_id);

        app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, Password::broker()->createToken($compte), 'mon-propre-mot-de-passe');
        $nouvelle = app(CreerSaSocieteAvecUnCompteRetireAction::class)->handle('paul@example.test', 'mon-propre-mot-de-passe', $societe);

        $compte->refresh();
        $this->assertSame([(int) $nouvelle->id, RoleSociete::Proprietaire, StatutCompte::Actif], [(int) $compte->societe_id, $compte->role_societe, $compte->statut]);
    }

    /**
     * Accepter un rattachement suit un lien envoyé à l'adresse : elle est
     * confirmée, et le compte ne se refuse plus.
     */
    public function test_un_rattachement_accepte_confirme_l_adresse_et_ferme_le_droit_de_refus(): void
    {
        Mail::fake();
        $ancien = $this->ouvrirUneSociete(['nom' => 'Ancienne Boutique']);
        $compte = $this->membre($ancien, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($ancien), $compte);
        $this->assertTrue($compte->refresh()->peutRefuserSonCompte());
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        app(ProposerUnRattachementAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, 'paul@example.test', RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
        $lien = Mail::sent(PropositionDeRattachementMail::class)->sole()->lien;
        app(AccepterUnRattachementAction::class)->handle(Str::afterLast($lien, '/'), 'paul@example.test', self::MOT_DE_PASSE_CHOISI);

        $compte->refresh();
        $this->assertTrue($compte->aConfirmeSonAdresse());
        $this->assertFalse($compte->peutRefuserSonCompte());
        $this->assertThrows(fn () => app(RefuserSonCompteAction::class)->handle($compte), AuthorizationException::class, RefuserSonCompteAction::MESSAGE_NON_REFUSABLE);
        $this->assertSame(StatutCompte::Actif, $compte->refresh()->statut);
    }

    /**
     * L'auteur du compte refusé, qui connaît son ancien mot de passe ou
     * garde une session ouverte avec lui, ne crée aucune société avec ce
     * compte : seule la personne qui reçoit les liens à son adresse le
     * peut.
     */
    public function test_seul_le_titulaire_du_lien_cree_sa_societe_avec_un_compte_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);
        $this->ouvrirUneSessionAilleurs($compte);
        app(RefuserSonCompteAction::class)->handle($compte);
        app(TenantContext::class)->clear();
        $siren = SocieteFactory::sirenFictif();
        $societes = Societe::query()->count();

        $this->post(route('societe.creer.enregistrer'), ['email' => 'paul@example.test', 'password' => self::MOT_DE_PASSE_CHOISI, 'siren' => $siren])
            ->assertStatus(422)
            ->assertSeeText(CreerSaSocieteAvecUnCompteRetireAction::MESSAGE_IDENTIFIANTS);
        $this->actingAs($compte)
            ->post(route('societe.creer.enregistrer'), ['email' => 'paul@example.test', 'password' => self::MOT_DE_PASSE_PROVISOIRE, 'siren' => $siren])
            ->assertStatus(422);

        $this->assertSame($societes, Societe::query()->count());
        $this->assertSame([(int) $magasin->societe_id, StatutCompte::Retire], [(int) $compte->refresh()->societe_id, $compte->statut]);
    }

    /**
     * Le compte refusé est celui de la session ou du jeton du lien, jamais
     * un identifiant de la requête : l'adresse de la page du lien ne se
     * change pas, et la page du mot de passe provisoire ne refuse que le
     * compte connecté.
     */
    public function test_personne_ne_refuse_le_compte_d_un_autre_par_une_requete_forgee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$auteurDuLien] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $victime = $this->membre($magasin, RoleMagasin::Vendeur);
        $jeton = Password::broker()->createToken($auteurDuLien);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->assertThrows(fn () => Livewire::test(PageDuLien::class, ['email' => $auteurDuLien->email, 'token' => $jeton])->set('email', $victime->email), CannotUpdateLockedPropertyException::class);
        Livewire::test(PageDuLien::class, ['email' => $victime->email, 'token' => $jeton])
            ->assertActionHidden('refuserCeCompte')
            ->assertSee(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE);
        $this->actingAs($auteurDuLien);
        Livewire::test(ChangerMotDePasseProvisoire::class)->callAction('refuserCeCompte');

        $this->assertSame(StatutCompte::Actif, $victime->refresh()->statut);
        $this->assertSame(StatutCompte::Retire, $auteurDuLien->refresh()->statut);
    }

    /**
     * Après un refus, l'ancien mot de passe n'ouvre plus rien, et les
     * sessions ouvertes du compte sont fermées.
     */
    public function test_apres_un_refus_l_ancien_mot_de_passe_et_les_sessions_ouvertes_n_ouvrent_plus_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->ouvrirUneSessionAilleurs($compte);

        app(RefuserSonCompteAction::class)->handle($compte);

        $this->assertFalse(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->refresh()->password));
        $this->assertSessionsFermees($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(Connexion::class)
            ->fillForm(['email' => $compte->email, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();
    }

    /**
     * Le titulaire suit un lien « Mot de passe oublié » et refuse le
     * compte sur sa page.
     */
    private function refuserParLeLien(User $compte): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => Password::broker()->createToken($compte)])
            ->assertActionVisible('refuserCeCompte')
            ->callAction('refuserCeCompte')
            ->assertRedirect(Filament::getLoginUrl());
    }

    /**
     * Version déterministe, sous les deux moteurs, de
     * RefusDUnCompteConcurrenceTest : les effets du refus (compte retiré,
     * sans magasin), validés par une autre transaction, arrivent dès que
     * la vente a verrouillé le registre ; sous le verrou de sa ligne, la
     * vente relit le compte retiré et est refusée.
     */
    public function test_un_refus_et_une_vente_simultanes_du_meme_compte_ne_laissent_jamais_passer_une_vente_apres_le_refus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $lignes = [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
        $refuse = false;
        DB::listen(function () use (&$refuse, $vendeur): void {
            if (! $refuse) {
                $refuse = true;
                DB::table('affectations')->where('user_id', $vendeur->id)->delete();
                DB::table('users')->where('id', $vendeur->id)->update(['statut' => StatutCompte::Retire->value, 'statut_change_par' => $vendeur->id]);
            }
        });

        $this->assertThrows(fn () => self::vendreAuComptant($vendeur, $comptoir, $lignes, ModePaiement::Especes), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame(0, Facture::query()->count());
    }
}
