<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\DonnerUnMotDePasseProvisoireAction;
use App\Domain\Tenancy\Actions\EnvoyerUnLienDeReinitialisationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Notifications\MotDePasseProvisoireRenouvele;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un accès perdu se renouvelle : tant que le mot de passe est encore le
 * provisoire, par un nouveau provisoire remis en main propre, par le
 * propriétaire ou un responsable de tous les magasins du compte, jamais
 * par un administrateur ; ensuite, par un lien envoyé à la personne, et
 * personne d'autre ne connaît son mot de passe. La personne est prévenue
 * de chaque renouvellement, et ses sessions se ferment.
 */
class MotDePasseProvisoireTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAU_PROVISOIRE = 'nouveau-provisoire-1';

    /**
     * Le propriétaire et le responsable de tous les magasins du compte
     * redonnent un provisoire à un compte dont le mot de passe l'est
     * encore ; jamais à un compte qui a choisi le sien, ni par le
     * responsable d'un seul de ses magasins, ni par un administrateur.
     */
    public function test_le_mot_de_passe_provisoire_ne_se_redonne_que_tant_que_le_mot_de_passe_est_provisoire_et_par_qui_a_tous_les_droits_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $responsableDesDeux = $this->responsable($magasin, $autre);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        [$provisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $deuxMagasins = $this->creerAuProvisoire($proprietaire, [$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Vendeur]]);
        $definitif = $this->membre($magasin, RoleMagasin::Vendeur);

        app(DonnerUnMotDePasseProvisoireAction::class)->handle($proprietaire, $provisoire, self::NOUVEAU_PROVISOIRE);
        $this->assertTrue(Hash::check(self::NOUVEAU_PROVISOIRE, $provisoire->refresh()->password));
        app(DonnerUnMotDePasseProvisoireAction::class)->handle($responsable, $provisoire, 'encore-un-provisoire');
        $this->assertTrue(Hash::check('encore-un-provisoire', $provisoire->refresh()->password));
        app(DonnerUnMotDePasseProvisoireAction::class)->handle($responsableDesDeux, $deuxMagasins, self::NOUVEAU_PROVISOIRE);
        $this->assertTrue(Hash::check(self::NOUVEAU_PROVISOIRE, $deuxMagasins->refresh()->password));

        foreach ([
            'compte au mot de passe choisi' => [$proprietaire, $definitif],
            'responsable d\'un seul de ses magasins' => [$responsable, $deuxMagasins],
            'administrateur' => [$administrateur, $provisoire],
        ] as $cas => [$auteur, $compte]) {
            $empreinte = $compte->refresh()->password;
            $this->assertThrows(fn () => app(DonnerUnMotDePasseProvisoireAction::class)->handle($auteur, $compte, 'provisoire-refuse-1'), AuthorizationException::class, null);
            $this->assertSame($empreinte, $compte->refresh()->password, $cas);
        }
    }

    /**
     * Un compte qui a choisi son mot de passe reçoit un lien à son adresse
     * pour en choisir un nouveau : son mot de passe ne change pas, et
     * l'auteur ne reçoit ni le lien ni le mot de passe.
     */
    public function test_un_compte_au_mot_de_passe_definitif_recoit_un_lien_et_l_auteur_ne_connait_pas_son_mot_de_passe(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $empreinte = $vendeur->password;

        app(EnvoyerUnLienDeReinitialisationAction::class)->handle($proprietaire, $vendeur);

        $this->assertSame($empreinte, $vendeur->refresh()->password);
        Notification::assertSentTo($vendeur, LienPourChoisirSonMotDePasse::class);
        Notification::assertNotSentTo($proprietaire, LienPourChoisirSonMotDePasse::class);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
    }

    public function test_le_membre_est_prevenu_de_toute_reinitialisation(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        [$provisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $definitif = $this->membre($magasin, RoleMagasin::Observateur);

        app(DonnerUnMotDePasseProvisoireAction::class)->handle($proprietaire, $provisoire, self::NOUVEAU_PROVISOIRE);
        app(EnvoyerUnLienDeReinitialisationAction::class)->handle($proprietaire, $definitif);

        Notification::assertSentTo($provisoire, MotDePasseProvisoireRenouvele::class);
        Notification::assertSentTo($definitif, LienPourChoisirSonMotDePasse::class);
    }

    /**
     * Personne ne se donne un mot de passe provisoire, ni un lien, depuis
     * l'équipe : ni le propriétaire sur sa fiche, ni un responsable sur sa
     * propre ligne, par le bouton, une requête forgée ou l'action.
     */
    public function test_personne_ne_se_donne_un_mot_de_passe_provisoire_depuis_les_membres(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);

        foreach ([$proprietaire, $responsable] as $compte) {
            $empreinte = $compte->refresh()->password;
            $this->assertThrows(fn () => app(DonnerUnMotDePasseProvisoireAction::class)->handle($compte, $compte, self::NOUVEAU_PROVISOIRE), AuthorizationException::class);
            $this->assertThrows(fn () => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($compte, $compte), AuthorizationException::class);
            $this->assertSame($empreinte, $compte->refresh()->password);
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $proprietaire->getRouteKey()])
            ->assertActionHidden('redonnerUnProvisoire')
            ->assertActionHidden('renvoyerUnLien');
        $this->forgerLAction($fiche, 'redonnerUnProvisoire', ['mot_de_passe_provisoire' => self::NOUVEAU_PROVISOIRE]);

        $this->dansLeMagasin($magasin, $responsable);
        $saLigne = Affectation::query()->where('user_id', $responsable->id)->sole();
        $equipe = Livewire::test(ListEquipeDuMagasin::class)->assertTableActionHidden('redonner_un_provisoire', $saLigne);
        $this->forgerLActionDeLigne($equipe, 'redonner_un_provisoire', $saLigne, ['mot_de_passe_provisoire' => self::NOUVEAU_PROVISOIRE]);

        $this->assertFalse(Hash::check(self::NOUVEAU_PROVISOIRE, $proprietaire->refresh()->password));
        $this->assertFalse(Hash::check(self::NOUVEAU_PROVISOIRE, $responsable->refresh()->password));
        $this->assertFalse($responsable->doitChangerSonMotDePasse());
    }

    /**
     * Le propriétaire redonne un provisoire à un administrateur ou à un
     * expert-comptable qui ne l'a pas encore changé ; un administrateur ne
     * le fait pour personne, et un responsable ni pour le propriétaire, ni
     * pour un administrateur, ni pour l'expert-comptable.
     */
    public function test_jamais_pour_le_proprietaire_ni_pour_un_administrateur_sauf_par_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $responsable = $this->responsable($magasin);
        $administrateurAuProvisoire = app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Admin', 'email' => 'admin@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [], []);
        $expertAuProvisoire = app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Cabinet', 'email' => 'cabinet@example.test'], self::MOT_DE_PASSE_PROVISOIRE);
        [$vendeurAuProvisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);

        foreach ([[$administrateur, $administrateurAuProvisoire], [$administrateur, $expertAuProvisoire], [$administrateur, $vendeurAuProvisoire], [$administrateur, $proprietaire], [$responsable, $administrateurAuProvisoire], [$responsable, $expertAuProvisoire], [$responsable, $proprietaire]] as [$auteur, $compte]) {
            $this->assertThrows(fn () => app(DonnerUnMotDePasseProvisoireAction::class)->handle($auteur, $compte, self::NOUVEAU_PROVISOIRE), AuthorizationException::class);
            $this->assertFalse(Hash::check(self::NOUVEAU_PROVISOIRE, $compte->refresh()->password), "{$auteur->email} → {$compte->email}");
        }

        app(DonnerUnMotDePasseProvisoireAction::class)->handle($proprietaire, $administrateurAuProvisoire, self::NOUVEAU_PROVISOIRE);
        app(DonnerUnMotDePasseProvisoireAction::class)->handle($proprietaire, $expertAuProvisoire, self::NOUVEAU_PROVISOIRE);
        $this->assertTrue(Hash::check(self::NOUVEAU_PROVISOIRE, $administrateurAuProvisoire->refresh()->password));
        $this->assertTrue(Hash::check(self::NOUVEAU_PROVISOIRE, $expertAuProvisoire->refresh()->password));
    }

    public function test_donner_un_mot_de_passe_provisoire_coupe_le_se_souvenir_de_moi(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->ouvrirUneSessionAilleurs($compte);

        app(DonnerUnMotDePasseProvisoireAction::class)->handle($this->proprietaireDe($magasin), $compte, self::NOUVEAU_PROVISOIRE);

        $this->assertSessionsFermees($compte);
    }

    /**
     * Une fois que la personne a choisi son mot de passe par « Mot de passe
     * oublié », son auteur ne lui redonne plus de provisoire : il ne
     * connaîtrait plus jamais son mot de passe.
     */
    public function test_l_auteur_ne_redonne_pas_de_mot_de_passe_provisoire_apres_un_mot_de_passe_oublie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, Password::broker()->createToken($compte), self::MOT_DE_PASSE_CHOISI);

        $this->assertThrows(fn () => app(DonnerUnMotDePasseProvisoireAction::class)->handle($this->proprietaireDe($magasin), $compte, self::NOUVEAU_PROVISOIRE), AuthorizationException::class);

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->refresh()->password));
        $this->assertFalse($compte->doitChangerSonMotDePasse());
    }

    public function test_le_responsable_d_un_seul_des_magasins_d_un_vendeur_ne_renouvelle_pas_son_acces(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Vendeur]], $magasin);

        $this->assertFalse($responsable->can('renouvelerLAcces', $vendeur));
        $this->assertThrows(fn () => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($responsable, $vendeur), AuthorizationException::class);

        Notification::assertNothingSentTo($vendeur);
    }

    /**
     * Un administrateur autorisé à nommer les responsables renouvelle, par
     * un lien, l'accès d'une personne qui n'est responsable que de
     * magasins de sa portée ; pas celui d'un responsable d'un magasin hors
     * de sa portée.
     */
    public function test_un_administrateur_autorise_renouvelle_par_un_lien_l_acces_d_un_responsable_de_ses_seuls_magasins(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $responsableDesDeux = $this->responsable($magasin, $horsPortee);

        app(EnvoyerUnLienDeReinitialisationAction::class)->handle($administrateur, $responsable);
        $this->assertThrows(fn () => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($administrateur, $responsableDesDeux), AuthorizationException::class);

        Notification::assertSentTo($responsable, LienPourChoisirSonMotDePasse::class);
        Notification::assertNothingSentTo($responsableDesDeux);
    }

    /**
     * Même pour un responsable qu'il a lui-même nommé avec un nouveau
     * compte, un administrateur ne redonne jamais de mot de passe
     * provisoire : il passe par le lien.
     */
    public function test_un_administrateur_autorise_ne_redonne_jamais_de_mot_de_passe_provisoire_a_un_responsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $this->assertFalse($administrateur->can('donnerUnProvisoire', $responsable));
        $this->assertThrows(fn () => app(DonnerUnMotDePasseProvisoireAction::class)->handle($administrateur, $responsable, self::NOUVEAU_PROVISOIRE), AuthorizationException::class);

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_PROVISOIRE, $responsable->refresh()->password));
    }

    /**
     * Un compte créé par cet auteur, son mot de passe encore provisoire.
     *
     * @param  array<int, array{role: RoleMagasin}>  $affectations
     */
    private function creerAuProvisoire(User $auteur, array $affectations): User
    {
        $magasin = Magasin::query()->findOrFail(array_key_first($affectations));

        return app(CreerMembreAction::class)->handle($auteur, $magasin->societe, ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail()], self::MOT_DE_PASSE_PROVISOIRE, $affectations);
    }
}
