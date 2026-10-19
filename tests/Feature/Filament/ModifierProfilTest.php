<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chacun change lui-même son nom, son adresse et son mot de passe en
 * donnant son mot de passe actuel, et y lit son identifiant, qui ne se
 * modifie pas. L'adresse du propriétaire est son adresse de connexion,
 * unique parmi les propriétaires : une nouvelle adresse ne prend effet
 * qu'après confirmation par le lien envoyé à cette adresse, et l'ancienne
 * est prévenue. L'adresse de tout autre compte n'est qu'un contact, qui
 * s'enregistre aussitôt et peut être celle d'un autre compte.
 */
class ModifierProfilTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le mot de passe que la fabrique donne au propriétaire.
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    public function test_la_page_de_profil_souvre_depuis_le_panneau(): void
    {
        $vendeur = $this->creerVendeur();

        $this->actingAs($vendeur)
            ->get('/app/profile')
            ->assertSee('Mot de passe actuel');
    }

    /**
     * Le nom change tout de suite ; l'adresse de connexion du propriétaire,
     * seulement quand le lien envoyé à la nouvelle adresse est suivi :
     * l'ouvrir ne change rien, le bouton confirme. La nouvelle adresse
     * ouvre alors le compte, l'ancienne plus, et l'ancienne en est prévenue
     * ; le lien « Mot de passe oublié » déjà envoyé à l'ancienne ne sert
     * plus, celui d'un autre propriétaire reste valable. Le journal d'audit
     * retient le changement sans aucune adresse.
     */
    public function test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue(): void
    {
        Mail::fake();
        $proprietaire = $this->creerProprietaire();
        $autreProprietaire = User::factory()->create(['email' => 'autre@example.test']);
        Password::broker()->createToken($proprietaire);
        Password::broker()->createToken($autreProprietaire);
        $this->actingAs($proprietaire);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Jeanne Martin',
                'email' => 'jeanne.martin@example.test',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_DU_PROPRIETAIRE,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['email' => 'jeanne@example.test']);

        $proprietaire->refresh();
        $this->assertSame(['Jeanne Martin', 'jeanne@example.test'], [$proprietaire->name, $proprietaire->email]);
        $this->assertTrue(Auth::validate(['login' => 'jeanne@example.test', 'password' => self::MOT_DE_PASSE_DU_PROPRIETAIRE]));
        $jeton = $this->jetonEnvoyeA('jeanne.martin@example.test');
        Mail::assertNotSent(AdresseChangeeMail::class);

        $this->get(route('profil.adresse.afficher', ['jeton' => $jeton]))->assertOk()->assertSeeText('jeanne.martin@example.test');
        $this->assertSame('jeanne@example.test', $proprietaire->refresh()->email);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertOk()->assertSeeText('Votre adresse de connexion est désormais jeanne.martin@example.test');

        $proprietaire->refresh();
        $this->assertSame('jeanne.martin@example.test', $proprietaire->email);
        $this->assertNull($proprietaire->nouvelle_adresse);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_DU_PROPRIETAIRE, $proprietaire->password));
        $this->assertTrue(Auth::validate(['login' => 'jeanne.martin@example.test', 'password' => self::MOT_DE_PASSE_DU_PROPRIETAIRE]));
        $this->assertFalse(Auth::validate(['login' => 'jeanne@example.test', 'password' => self::MOT_DE_PASSE_DU_PROPRIETAIRE]));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'jeanne@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'autre@example.test']);
        Mail::assertSent(AdresseChangeeMail::class, fn (AdresseChangeeMail $mail): bool => $mail->hasTo('jeanne@example.test') && $mail->nom === 'Jeanne Martin');
        [$entree] = $this->entreesDAudit($proprietaire->societe, ActionAuditee::AdresseDeConnexionChangee);
        $this->assertSame(['compte' => ['id' => $proprietaire->id, 'nom' => 'Jeanne Martin']], $entree->nouvelles_valeurs);
        $this->assertStringNotContainsString('example.test', json_encode([$entree->anciennes_valeurs, $entree->nouvelles_valeurs]));
    }

    /**
     * Un lien de confirmation ne sert qu'une fois et vaut 24 heures ; une
     * nouvelle demande remplace la précédente, dont le lien ne sert plus.
     */
    public function test_un_lien_de_confirmation_expire_ou_rejoue_est_refuse(): void
    {
        Mail::fake();
        $proprietaire = $this->creerProprietaire();

        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'premiere@example.test');
        $remplace = $this->jetonEnvoyeA('premiere@example.test');
        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'seconde@example.test');
        $valable = $this->jetonEnvoyeA('seconde@example.test');

        $this->get(route('profil.adresse.afficher', ['jeton' => $remplace]))->assertStatus(410);
        $this->post(route('profil.adresse.confirmer', ['jeton' => $remplace]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);
        $this->assertSame('jeanne@example.test', $proprietaire->refresh()->email);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $valable]))->assertOk();
        $this->assertSame('seconde@example.test', $proprietaire->refresh()->email);
        $this->post(route('profil.adresse.confirmer', ['jeton' => $valable]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);

        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire->refresh(), 'troisieme@example.test');
        $expire = $this->jetonEnvoyeA('troisieme@example.test');
        $this->travel(DemanderUneNouvelleAdresseAction::DUREE_DE_VALIDITE_EN_HEURES)->hours();
        $this->travel(1)->minute();

        $this->get(route('profil.adresse.afficher', ['jeton' => $expire]))->assertStatus(410);
        $this->assertThrows(fn () => app(ConfirmerNouvelleAdresseAction::class)->handle($expire), AuthorizationException::class, ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);
        $this->assertSame('seconde@example.test', $proprietaire->refresh()->email);
    }

    /**
     * Le lien suivi confirme la nouvelle adresse de connexion du
     * propriétaire, à sa date ; aucun autre compte n'a d'adresse de
     * connexion à confirmer.
     */
    public function test_confirmer_une_nouvelle_adresse_pose_sa_date_de_confirmation_et_seul_le_proprietaire_en_demande(): void
    {
        Mail::fake();
        $this->travelTo('2026-10-15 10:00:00');
        $proprietaire = $this->creerProprietaire();
        $proprietaire->forceFill(['email_verified_at' => now()->subYear()])->save();

        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'jeanne.martin@example.test');
        app(ConfirmerNouvelleAdresseAction::class)->handle($this->jetonEnvoyeA('jeanne.martin@example.test'));

        $this->assertSame('2026-10-15 10:00:00', $proprietaire->refresh()->email_verified_at?->toDateTimeString());
        $vendeur = $this->membre($this->magasinDe($proprietaire), RoleMagasin::Vendeur);
        $this->assertThrows(fn () => app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur, 'paul@example.test'), LogicException::class, 'Seul le propriétaire a une adresse de connexion à confirmer.');
        Mail::assertNotSent(ConfirmationNouvelleAdresseMail::class, fn (ConfirmationNouvelleAdresseMail $mail): bool => $mail->hasTo('paul@example.test'));
    }

    /**
     * Une adresse en attente ne bloque rien : prise entre-temps par un
     * autre propriétaire, elle est refusée à la confirmation, sans dire à
     * qui elle appartient ; rien ne change, et le lien ne sert plus.
     */
    public function test_une_adresse_prise_avant_la_confirmation_est_refusee_sans_rien_changer(): void
    {
        Mail::fake();
        $proprietaire = $this->creerProprietaire();
        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'convoitee@example.test');
        $jeton = $this->jetonEnvoyeA('convoitee@example.test');
        $verifieeLe = $proprietaire->refresh()->email_verified_at;

        User::factory()->create(['email' => 'convoitee@example.test']);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_ADRESSE_INUTILISABLE);
        $proprietaire->refresh();
        $this->assertSame(['jeanne@example.test', null], [$proprietaire->email, $proprietaire->nouvelle_adresse]);
        $this->assertEquals($verifieeLe, $proprietaire->email_verified_at);
        $this->assertNull(ConfirmerNouvelleAdresseAction::compteEnAttente($jeton));
        $this->assertSame([], $this->entreesDAudit($proprietaire->societe, ActionAuditee::AdresseDeConnexionChangee));
        Mail::assertNotSent(AdresseChangeeMail::class);
    }

    /**
     * Le lien d'une nouvelle adresse compte au débit des e-mails du
     * propriétaire, avec ceux qu'il fait déjà partir (ici, les liens de la
     * transmission de la propriété) : au-delà de 60 dans l'heure, son
     * profil n'envoie rien et ne change rien, pas même son nom.
     */
    public function test_une_nouvelle_adresse_au_dela_du_debit_des_e_mails_est_refusee_sans_rien_changer(): void
    {
        Mail::fake();
        $this->travelTo('2026-10-15 10:00:00');
        $proprietaire = $this->creerProprietaire();
        $adresse = $proprietaire->email;
        $administrateur = $this->administrateurPretAReprendre($this->magasinDe($proprietaire));
        app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        foreach (range(2, DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE) as $numero) {
            app(DemanderLaTransmissionDeLaProprieteAction::class)->renvoyerLeLien($proprietaire, $administrateur);
        }
        $envoyes = count(Mail::sent(TransmissionDeLaProprieteMail::class));
        $this->actingAs($proprietaire);

        $page = Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Jeanne Martin',
                'email' => 'jeanne.martin@example.test',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_DU_PROPRIETAIRE,
            ])
            ->call('save')
            ->assertHasErrors(['data.email']);

        $this->assertSame(['Trop d\'e-mails envoyés en une heure : réessayez dans 60 minute(s).'], $page->errors()->get('data.email'));
        $this->assertSame(DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE, $envoyes);
        $proprietaire->refresh();
        $this->assertSame('Jeanne Dupont', $proprietaire->name);
        $this->assertSame([$adresse, null], [$proprietaire->email, $proprietaire->nouvelle_adresse]);
        Mail::assertNotSent(ConfirmationNouvelleAdresseMail::class);
    }

    public function test_changer_son_nom_et_son_adresse_sans_le_mot_de_passe_actuel_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Paul Martin',
                'email' => 'paul.martin@example.test',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel.']);

        $vendeur->refresh();
        $this->assertSame('Paul', $vendeur->name);
        $this->assertSame('paul@example.test', $vendeur->email);
    }

    /**
     * L'adresse de connexion d'un autre propriétaire, même suspendu (un
     * compte ne s'efface jamais), est refusée sans dire à qui elle
     * appartient.
     *
     * @return array<string, array{Closure(): User}>
     */
    public static function proprietairesQuiOntDejaLAdresse(): array
    {
        return [
            'propriétaire actif' => [static fn (): User => User::factory()->create(['email' => 'prise@example.test'])],
            'propriétaire suspendu' => [static fn (): User => User::factory()->create(['email' => 'prise@example.test', 'statut' => StatutCompte::Suspendu])],
        ];
    }

    /**
     * @param  Closure(): User  $creerLeCompte
     */
    #[DataProvider('proprietairesQuiOntDejaLAdresse')]
    public function test_une_adresse_deja_prise_est_refusee(Closure $creerLeCompte): void
    {
        Mail::fake();
        $creerLeCompte();
        $proprietaire = $this->creerProprietaire();
        $this->actingAs($proprietaire);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'email' => 'prise@example.test',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_DU_PROPRIETAIRE,
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée.']);

        $this->assertSame(['jeanne@example.test', null], [$proprietaire->fresh()->email, $proprietaire->fresh()->nouvelle_adresse]);
        Mail::assertNothingSent();
    }

    /**
     * « PRISE@Example.test » est la même adresse que « prise@example.test » :
     * elle reste refusée.
     */
    public function test_une_adresse_deja_prise_sous_une_autre_casse_est_refusee(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'prise@example.test']);
        $proprietaire = $this->creerProprietaire();
        $this->actingAs($proprietaire);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'email' => ' PRISE@Example.test ',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_DU_PROPRIETAIRE,
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée.']);

        $this->assertSame('jeanne@example.test', $proprietaire->fresh()->email);
        Mail::assertNothingSent();
    }

    /**
     * L'adresse d'un compte qui n'est pas propriétaire n'est qu'un contact
     * : elle s'enregistre aussitôt, en minuscules, sans lien de
     * confirmation, peut être celle d'un autre compte, même d'un
     * propriétaire, ne lui ouvre jamais un compte et s'efface.
     */
    public function test_une_adresse_de_contact_s_enregistre_sans_confirmation_et_peut_etre_celle_d_un_autre_compte(): void
    {
        Mail::fake();
        $vendeur = $this->creerVendeur();
        $collegue = $this->renseignerSonAdresse($this->membre($this->magasinDe($vendeur), RoleMagasin::Vendeur), 'boutique@example.test');
        $proprietaire = $this->proprietaireDe($this->magasinDe($vendeur));
        $adresseDuProprietaire = $proprietaire->email;
        $this->actingAs($vendeur);

        foreach ([' Boutique@Example.TEST ' => 'boutique@example.test', $proprietaire->email => $proprietaire->email] as $saisie => $enregistree) {
            Livewire::test(ModifierProfil::class)
                ->assertSee('Adresse e-mail de contact (facultative)')
                ->fillForm(['email' => $saisie, 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame([$enregistree, null], [$vendeur->refresh()->email, $vendeur->nouvelle_adresse]);
            $this->assertFalse(Auth::validate(['login' => $enregistree, 'password' => self::MOT_DE_PASSE_CHOISI]));
        }

        Livewire::test(ModifierProfil::class)
            ->fillForm(['email' => '', 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($vendeur->refresh()->email);
        $this->assertSame(['boutique@example.test', $adresseDuProprietaire], [$collegue->refresh()->email, $proprietaire->refresh()->email]);
        Mail::assertNothingSent();
    }

    /**
     * L'identifiant de connexion se lit dans le profil ; aucune saisie ne
     * le modifie.
     */
    public function test_l_identifiant_se_lit_dans_le_profil_et_ne_se_modifie_pas(): void
    {
        $vendeur = $this->creerVendeur();
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $this->actingAs($vendeur);

        $this->get('/app/profile')->assertSee('Votre identifiant de connexion')->assertSee($identifiant);
        Livewire::test(ModifierProfil::class)
            ->set('data.identifiant', '48210937')
            ->fillForm(['mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($identifiant, $vendeur->refresh()->identifiantDeConnexion());
    }

    /**
     * Le mot de passe choisi dans le profil suit la règle : ni
     * l'identifiant, ni une chaîne qui le contient, ni moins de 8
     * caractères, ni un seul caractère répété, ni une suite de chiffres.
     */
    public function test_un_mot_de_passe_egal_a_l_identifiant_qui_le_contient_trop_court_ou_trivial_est_refuse(): void
    {
        [$compte, $identifiant] = $this->compteAuMotDePasseIdentifiant($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $this->actingAs($compte);

        foreach ([
            $identifiant => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            "mon-{$identifiant}-a-moi" => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            'court' => RegleDuMotDePasse::MESSAGE_TROP_COURT,
            'aaaaaaaaaa' => RegleDuMotDePasse::MESSAGE_REPETE,
            '23456789' => RegleDuMotDePasse::MESSAGE_SUITE,
            '98765432' => RegleDuMotDePasse::MESSAGE_SUITE,
        ] as $motDePasse => $message) {
            $page = Livewire::test(ModifierProfil::class)
                ->fillForm(['password' => (string) $motDePasse, 'passwordConfirmation' => (string) $motDePasse, 'mot_de_passe_actuel' => $identifiant])
                ->call('save')
                ->assertHasFormErrors(['password']);

            $this->assertContains($message, $page->errors()->get('data.password'), (string) $motDePasse);
        }

        $this->assertTrue($compte->refresh()->mot_de_passe_est_l_identifiant);
        $this->assertTrue(Hash::check($identifiant, $compte->password));
    }

    /**
     * Tant que le mot de passe est encore l'identifiant, le profil le
     * rappelle ; le mot de passe choisi, le rappel disparaît.
     */
    public function test_le_profil_rappelle_que_le_mot_de_passe_est_encore_l_identifiant(): void
    {
        [$compte, $identifiant] = $this->compteAuMotDePasseIdentifiant($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $this->actingAs($compte);

        Livewire::test(ModifierProfil::class)
            ->assertSee(ModifierProfil::RAPPEL_IDENTIFIANT)
            ->fillForm(['password' => 'mon-propre-mot', 'passwordConfirmation' => 'mon-propre-mot', 'mot_de_passe_actuel' => $identifiant])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($compte->refresh()->mot_de_passe_est_l_identifiant);
        Livewire::test(ModifierProfil::class)->assertDontSee(ModifierProfil::RAPPEL_IDENTIFIANT);
    }

    public function test_un_nom_vide_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => '',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasFormErrors(['name' => 'Le champ nom est obligatoire.']);

        $this->assertSame('Paul', $vendeur->fresh()->name);
    }

    public function test_un_membre_change_son_mot_de_passe_avec_son_mot_de_passe_actuel(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    /**
     * Changer son mot de passe dans son profil ferme les autres sessions
     * du compte, partout, et son « se souvenir de moi » ; celle-ci reste
     * ouverte.
     */
    public function test_changer_son_mot_de_passe_ferme_ses_autres_sessions(): void
    {
        $vendeur = $this->creerVendeur();
        $ailleurs = $this->ouvrirUneSessionAilleurs($vendeur);
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('sessions', ['id' => $ailleurs]);
        $this->assertNotSame('jeton-de-l-autre-appareil', $vendeur->refresh()->getRememberToken());
        $this->assertAuthenticatedAs($vendeur);
    }

    public function test_changer_son_mot_de_passe_sans_le_mot_de_passe_actuel_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel.']);

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->fresh()->password));
    }

    /**
     * Le nouveau mot de passe est facultatif : laissé vide, l'ancien
     * reste.
     */
    public function test_enregistrer_sans_nouveau_mot_de_passe_garde_l_ancien(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->fresh()->password));
    }

    public function test_un_mot_de_passe_actuel_faux_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => 'devine-au-hasard',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Le mot de passe actuel est incorrect.']);

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->fresh()->password));
    }

    /**
     * Le jeton du lien de confirmation envoyé à cette adresse.
     */
    private function jetonEnvoyeA(string $adresse): string
    {
        $lien = null;
        Mail::assertSent(ConfirmationNouvelleAdresseMail::class, function (ConfirmationNouvelleAdresseMail $mail) use ($adresse, &$lien): bool {
            if (! $mail->hasTo($adresse)) {
                return false;
            }

            $lien = $mail->lien;

            return true;
        });

        return Str::afterLast((string) $lien, '/');
    }

    /**
     * Le propriétaire d'une société qui a un magasin, Jeanne Dupont,
     * jeanne@example.test.
     */
    private function creerProprietaire(): User
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], User::factory()->create(['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.test']));

        return $this->proprietaireDe($magasin);
    }

    /**
     * Un vendeur, Paul, qui a renseigné l'adresse de contact
     * paul@example.test.
     */
    private function creerVendeur(): User
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        return $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul',
            'email' => 'paul@example.test',
        ]);
    }

    /**
     * Le premier magasin de la société de ce compte.
     */
    private function magasinDe(User $compte): Magasin
    {
        return Magasin::query()->where('societe_id', $compte->societe_id)->where('est_demonstration', false)->orderBy('id')->firstOrFail();
    }
}
