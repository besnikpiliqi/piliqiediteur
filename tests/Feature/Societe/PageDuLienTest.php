<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La page du lien « Mot de passe oublié », envoyé au seul propriétaire à
 * son adresse de connexion (voir PageDuLien) : elle n'ouvre aucune
 * session, répond pareil à un lien faux quelle que soit l'adresse, ne sert
 * qu'une fois et seulement au propriétaire du lien, applique la règle du
 * mot de passe choisi, et le mot de passe choisi ferme toutes les sessions
 * du compte.
 */
class PageDuLienTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    /**
     * Le propriétaire choisit son mot de passe sur la page du lien sans
     * qu'aucune session ne s'ouvre : il est mené à la connexion, et le lien
     * suivi confirme son adresse.
     */
    public function test_la_page_de_lien_n_ouvre_aucune_session(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $proprietaire->forceFill(['email_verified_at' => null])->save();
        $jeton = Password::broker()->createToken($proprietaire);

        $this->get(Filament::getPanel('app')->getResetPasswordUrl($jeton, $proprietaire))->assertOk()->assertSee('Choisissez votre nouveau mot de passe.');
        Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => $jeton])
            ->fillForm(['password' => 'mon-nouveau-mot-de-passe', 'passwordConfirmation' => 'mon-nouveau-mot-de-passe'])
            ->call('choisir')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertGuest();
        $proprietaire->refresh();
        $this->assertTrue(Hash::check('mon-nouveau-mot-de-passe', $proprietaire->password));
        $this->assertNotNull($proprietaire->email_verified_at);
        $this->assertFalse(Password::broker()->tokenExists($proprietaire, $jeton));
    }

    /**
     * Un lien faux reçoit la même réponse, que l'adresse soit celle d'un
     * propriétaire, l'adresse de contact d'un autre compte, ou d'aucun
     * compte : la page ne révèle rien.
     */
    public function test_la_page_de_lien_repond_pareil_quel_que_soit_l_etat_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'vendeur@example.test']);
        $jetonDuVendeur = Password::broker()->createToken($proprietaire);

        $reponses = [];
        foreach ([[$proprietaire->email, 'jeton-faux'], [$vendeur->email, 'jeton-faux'], [$vendeur->email, $jetonDuVendeur], ['personne@example.test', 'jeton-faux']] as [$adresse, $jeton]) {
            $page = Livewire::test(PageDuLien::class, ['email' => $adresse, 'token' => $jeton])
                ->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
                ->assertActionHidden('choisir')
                ->assertFormFieldIsHidden('password');
            $reponses[] = $page->instance()->compteDuLien();
        }

        $this->assertSame([null, null, null, null], $reponses);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->refresh()->password));
    }

    /**
     * Un jeton déjà servi, ou le jeton d'un autre propriétaire, est refusé.
     */
    public function test_un_jeton_rejoue_ou_d_un_autre_compte_est_refuse_sur_la_page_de_lien(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $autre = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $jeton = Password::broker()->createToken($proprietaire);
        $jetonDeLAutre = Password::broker()->createToken($autre);

        Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => $jeton])
            ->fillForm(['password' => 'premier-mot-de-passe', 'passwordConfirmation' => 'premier-mot-de-passe'])
            ->call('choisir');
        Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => $jeton])
            ->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'second-mot-de-passe')
            ->set('data.passwordConfirmation', 'second-mot-de-passe')
            ->call('choisir');
        Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => $jetonDeLAutre])
            ->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'troisieme-mot-de-passe')
            ->set('data.passwordConfirmation', 'troisieme-mot-de-passe')
            ->call('choisir');

        $this->assertTrue(Hash::check('premier-mot-de-passe', $proprietaire->refresh()->password));
        $this->assertTrue(Hash::check('password', $autre->refresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($autre, $jetonDeLAutre));
    }

    /**
     * Le mot de passe choisi sur la page du lien suit la règle : ni
     * l'identifiant du propriétaire, ni trop court ; rien ne s'enregistre
     * et le lien reste valable.
     */
    public function test_le_mot_de_passe_choisi_sur_la_page_de_lien_suit_la_regle(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $identifiant = (string) $proprietaire->identifiantDeConnexion();
        $jeton = Password::broker()->createToken($proprietaire);

        foreach ([$identifiant => RegleDuMotDePasse::MESSAGE_IDENTIFIANT, 'court' => RegleDuMotDePasse::MESSAGE_TROP_COURT] as $motDePasse => $message) {
            $page = Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => $jeton])
                ->fillForm(['password' => (string) $motDePasse, 'passwordConfirmation' => (string) $motDePasse])
                ->call('choisir')
                ->assertHasFormErrors(['password']);

            $this->assertContains($message, $page->errors()->get('data.password'));
        }

        $this->assertTrue(Hash::check('password', $proprietaire->refresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($proprietaire, $jeton));
    }

    /**
     * Choisir son mot de passe sur la page du lien ferme toutes les
     * sessions du compte et renouvelle son « se souvenir de moi ».
     */
    public function test_choisir_son_mot_de_passe_sur_la_page_de_lien_supprime_les_sessions_du_compte(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->ouvrirUneSessionAilleurs($proprietaire);

        Livewire::test(PageDuLien::class, ['email' => $proprietaire->email, 'token' => Password::broker()->createToken($proprietaire)])
            ->fillForm(['password' => 'mon-nouveau-mot-de-passe', 'passwordConfirmation' => 'mon-nouveau-mot-de-passe'])
            ->call('choisir');

        $this->assertSessionsFermees($proprietaire);
        $this->assertInstanceOf(User::class, $proprietaire->refresh());
    }
}
