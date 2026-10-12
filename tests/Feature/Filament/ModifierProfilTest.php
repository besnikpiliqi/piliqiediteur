<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chacun change lui-même son nom, son adresse de connexion et son mot de
 * passe en donnant son mot de passe actuel ; une nouvelle adresse ne prend
 * effet qu'après confirmation par le lien envoyé à cette adresse, et
 * l'ancienne est prévenue. S'il l'a oublié, il passe par « Mot de passe
 * oublié » (voir MotDePasseOublieTest). Ce sont les seuls chemins pour le
 * propriétaire, dont le mot de passe ne se réinitialise pas depuis
 * l'équipe.
 */
class ModifierProfilTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_de_profil_souvre_depuis_le_panneau(): void
    {
        $vendeur = $this->creerVendeur();

        $this->actingAs($vendeur)
            ->get('/app/profile')
            ->assertSee('Mot de passe actuel');
    }

    /**
     * Le nom change tout de suite ; l'adresse, seulement quand le lien
     * envoyé à la nouvelle adresse est suivi : l'ouvrir ne change rien, le
     * bouton confirme. La nouvelle adresse ouvre alors le compte,
     * l'ancienne plus, et l'ancienne en est prévenue ; le lien « Mot de
     * passe oublié » déjà envoyé à l'ancienne ne sert plus, celui d'un
     * autre compte reste valable. Le journal d'audit retient le changement
     * sans aucune adresse.
     */
    public function test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue(): void
    {
        Mail::fake();
        $vendeur = $this->creerVendeur();
        $autreCompte = User::factory()->create(['email' => 'autre@example.test']);
        Password::broker()->createToken($vendeur);
        Password::broker()->createToken($autreCompte);
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Paul Martin',
                'email' => 'paul.martin@example.test',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['email' => 'paul@example.test']);

        $vendeur->refresh();
        $this->assertSame(['Paul Martin', 'paul@example.test'], [$vendeur->name, $vendeur->email]);
        $this->assertTrue(Auth::validate(['email' => 'paul@example.test', 'password' => self::MOT_DE_PASSE_CHOISI]));
        $jeton = $this->jetonEnvoyeA('paul.martin@example.test');
        Mail::assertNotSent(AdresseChangeeMail::class);

        $this->get(route('profil.adresse.afficher', ['jeton' => $jeton]))->assertOk()->assertSeeText('paul.martin@example.test');
        $this->assertSame('paul@example.test', $vendeur->refresh()->email);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertOk()->assertSeeText('Votre adresse de connexion est désormais paul.martin@example.test');

        $vendeur->refresh();
        $this->assertSame('paul.martin@example.test', $vendeur->email);
        $this->assertNull($vendeur->nouvelle_adresse);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
        $this->assertTrue(Auth::validate(['email' => 'paul.martin@example.test', 'password' => self::MOT_DE_PASSE_CHOISI]));
        $this->assertFalse(Auth::validate(['email' => 'paul@example.test', 'password' => self::MOT_DE_PASSE_CHOISI]));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'paul@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'autre@example.test']);
        Mail::assertSent(AdresseChangeeMail::class, fn (AdresseChangeeMail $mail): bool => $mail->hasTo('paul@example.test') && $mail->nom === 'Paul Martin');
        [$entree] = $this->entreesDAudit($vendeur->societe, ActionAuditee::AdresseDeConnexionChangee);
        $this->assertSame(['compte' => ['id' => $vendeur->id, 'nom' => 'Paul Martin']], $entree->nouvelles_valeurs);
        $this->assertStringNotContainsString('example.test', json_encode([$entree->anciennes_valeurs, $entree->nouvelles_valeurs]));
    }

    /**
     * Un lien de confirmation ne sert qu'une fois et vaut 24 heures ; une
     * nouvelle demande remplace la précédente, dont le lien ne sert plus.
     */
    public function test_un_lien_de_confirmation_expire_ou_rejoue_est_refuse(): void
    {
        Mail::fake();
        $vendeur = $this->creerVendeur();

        app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur, 'premiere@example.test');
        $remplace = $this->jetonEnvoyeA('premiere@example.test');
        app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur, 'seconde@example.test');
        $valable = $this->jetonEnvoyeA('seconde@example.test');

        $this->get(route('profil.adresse.afficher', ['jeton' => $remplace]))->assertStatus(410);
        $this->post(route('profil.adresse.confirmer', ['jeton' => $remplace]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);
        $this->assertSame('paul@example.test', $vendeur->refresh()->email);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $valable]))->assertOk();
        $this->assertSame('seconde@example.test', $vendeur->refresh()->email);
        $this->post(route('profil.adresse.confirmer', ['jeton' => $valable]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);

        app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur->refresh(), 'troisieme@example.test');
        $expire = $this->jetonEnvoyeA('troisieme@example.test');
        $this->travel(DemanderUneNouvelleAdresseAction::DUREE_DE_VALIDITE_EN_HEURES)->hours();
        $this->travel(1)->minute();

        $this->get(route('profil.adresse.afficher', ['jeton' => $expire]))->assertStatus(410);
        $this->assertThrows(fn () => app(ConfirmerNouvelleAdresseAction::class)->handle($expire), AuthorizationException::class, ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE);
        $this->assertSame('seconde@example.test', $vendeur->refresh()->email);
    }

    /**
     * Le lien suivi confirme l'adresse : un compte créé par la société, qui
     * pouvait encore être refusé, ne le peut plus.
     */
    public function test_confirmer_une_nouvelle_adresse_pose_l_adresse_confirmee(): void
    {
        Mail::fake();
        $vendeur = $this->creerVendeur();
        $this->assertTrue($vendeur->peutRefuserSonCompte());

        app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur, 'paul.martin@example.test');
        $this->assertFalse($vendeur->refresh()->aConfirmeSonAdresse());
        app(ConfirmerNouvelleAdresseAction::class)->handle($this->jetonEnvoyeA('paul.martin@example.test'));

        $vendeur->refresh();
        $this->assertTrue($vendeur->aConfirmeSonAdresse());
        $this->assertFalse($vendeur->peutRefuserSonCompte());
    }

    /**
     * Une adresse en attente ne bloque rien : prise entre-temps par un
     * autre compte, elle est refusée à la confirmation, sans dire à qui
     * elle appartient ; rien ne change, et le lien ne sert plus.
     */
    public function test_une_adresse_prise_avant_la_confirmation_est_refusee_sans_rien_changer(): void
    {
        Mail::fake();
        $vendeur = $this->creerVendeur();
        app(DemanderUneNouvelleAdresseAction::class)->handle($vendeur, 'convoitee@example.test');
        $jeton = $this->jetonEnvoyeA('convoitee@example.test');

        User::factory()->create(['email' => 'convoitee@example.test']);

        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_ADRESSE_INUTILISABLE);
        $this->assertSame('paul@example.test', $vendeur->refresh()->email);
        $this->assertNull($vendeur->email_verified_at);
        $this->assertNull($vendeur->nouvelle_adresse);
        $this->assertNull(ConfirmerNouvelleAdresseAction::compteEnAttente($jeton));
        $this->assertSame([], $this->entreesDAudit($vendeur->societe, ActionAuditee::AdresseDeConnexionChangee));
        Mail::assertNotSent(AdresseChangeeMail::class);
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
     * L'adresse d'un autre compte, même suspendu (un compte ne s'efface
     * jamais), est refusée sans dire à qui elle appartient.
     *
     * @return array<string, array{Closure(): User}>
     */
    public static function comptesQuiOntDejaLAdresse(): array
    {
        return [
            'compte actif' => [static fn (): User => User::factory()->create(['email' => 'prise@example.test'])],
            'compte suspendu' => [static fn (): User => User::factory()->create(['email' => 'prise@example.test', 'statut' => StatutCompte::Suspendu])],
        ];
    }

    /**
     * @param  Closure(): User  $creerLeCompte
     */
    #[DataProvider('comptesQuiOntDejaLAdresse')]
    public function test_une_adresse_deja_prise_est_refusee(Closure $creerLeCompte): void
    {
        $creerLeCompte();
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'email' => 'prise@example.test',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée.']);

        $this->assertSame('paul@example.test', $vendeur->fresh()->email);
    }

    /**
     * « PRISE@Example.test » est la même adresse que « prise@example.test » :
     * elle reste refusée.
     */
    public function test_une_adresse_deja_prise_sous_une_autre_casse_est_refusee(): void
    {
        User::factory()->create(['email' => 'prise@example.test']);
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'email' => ' PRISE@Example.test ',
                'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée.']);

        $this->assertSame('paul@example.test', $vendeur->fresh()->email);
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

    private function creerVendeur(): User
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        return $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul',
            'email' => 'paul@example.test',
        ]);
    }
}
