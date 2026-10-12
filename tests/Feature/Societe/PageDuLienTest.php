<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La page d'un lien envoyé à l'adresse d'un compte (voir PageDuLien) :
 * elle n'ouvre aucune session, ne lève jamais une suspension, ne laisse
 * pas un compte suspendu choisir son mot de passe, répond pareil à un lien
 * faux quel que soit le compte, ne sert qu'une fois et seulement au compte
 * du lien, et chaque décision qu'on y prend ferme les sessions du compte.
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
     * La page ne connecte personne ; à un compte suspendu, elle ne propose
     * pas de choisir un mot de passe, et une requête forgée n'en enregistre
     * aucun : un refus rend désormais le mot de passe inutilisable, mais un
     * compte suspendu ne choisit pas le sien.
     */
    public function test_la_page_de_lien_n_ouvre_aucune_session_et_ne_laisse_pas_un_compte_suspendu_choisir_son_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $compte);
        $empreinte = $compte->refresh()->password;
        $jeton = Password::broker()->createToken($compte);

        $this->get(Filament::getPanel('app')->getResetPasswordUrl($jeton, $compte))->assertOk();
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => $jeton])
            ->assertFormFieldIsHidden('password')
            ->assertActionHidden('choisir')
            ->set('data.password', 'mot-de-passe-force-1')
            ->set('data.passwordConfirmation', 'mot-de-passe-force-1')
            ->call('choisir');

        $this->assertGuest();
        $this->assertSame([$empreinte, StatutCompte::Suspendu], [$compte->refresh()->password, $compte->statut]);
        $this->assertTrue(Password::broker()->tokenExists($compte, $jeton));
    }

    /**
     * Choisir son mot de passe sur la page du lien ne lève jamais une
     * suspension : ni par la page, ni par l'action.
     */
    public function test_accepter_sur_la_page_de_lien_ne_leve_jamais_une_suspension(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $compte);
        $jeton = Password::broker()->createToken($compte);

        $this->assertThrows(fn () => app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, ChoisirSonMotDePasseParLeLienAction::MESSAGE_SUSPENDU);

        $this->assertSame([StatutCompte::Suspendu, true], [$compte->refresh()->statut, $compte->doitChangerSonMotDePasse()]);
        $this->assertFalse($compte->peutAgir());
    }

    /**
     * Un lien faux, expiré ou déjà servi reçoit la même réponse, que
     * l'adresse soit celle d'un compte actif, suspendu, retiré, ou d'aucun
     * compte : la page ne révèle rien.
     */
    public function test_la_page_de_lien_repond_pareil_quel_que_soit_l_etat_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $actif = $this->membre($magasin, RoleMagasin::Vendeur);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        $reponses = [];
        foreach ([$actif->email, $suspendu->email, $retire->email, 'personne@example.test'] as $email) {
            $page = Livewire::test(PageDuLien::class, ['email' => $email, 'token' => 'jeton-faux'])
                ->assertSee(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE)
                ->assertActionHidden('choisir')
                ->assertActionHidden('refuserCeCompte');
            $reponses[] = [$page->instance()->lienValable(), $page->instance()->compteDuLien()];
        }

        $this->assertSame(array_fill(0, 4, [false, null]), $reponses);
    }

    /**
     * Un jeton déjà servi, ou le jeton d'un autre compte, est refusé.
     */
    public function test_un_jeton_rejoue_ou_d_un_autre_compte_est_refuse_sur_la_page_de_lien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        $autre = $this->membre($magasin, RoleMagasin::Vendeur);
        $jeton = Password::broker()->createToken($compte);
        $jetonDeLAutre = Password::broker()->createToken($autre);

        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => $jeton])
            ->fillForm(['password' => 'premier-mot-de-passe', 'passwordConfirmation' => 'premier-mot-de-passe'])
            ->call('choisir');
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => $jeton])
            ->assertSee(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'second-mot-de-passe')
            ->set('data.passwordConfirmation', 'second-mot-de-passe')
            ->call('choisir');
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => $jetonDeLAutre])
            ->assertSee(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'troisieme-mot-de-passe')
            ->set('data.passwordConfirmation', 'troisieme-mot-de-passe')
            ->call('choisir');

        $this->assertTrue(Hash::check('premier-mot-de-passe', $compte->refresh()->password));
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $autre->refresh()->password));
    }

    /**
     * Un compte retiré choisit son mot de passe sur la page du lien, sans
     * ouvrir aucun panneau, puis arrive sur la création de sa société (ou
     * accepte un rattachement par le lien d'une société).
     */
    public function test_un_compte_retire_choisit_son_mot_de_passe_sur_la_page_de_lien_sans_ouvrir_aucun_panneau(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        app(RefuserSonCompteAction::class)->handle($compte);

        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => Password::broker()->createToken($compte)])
            ->assertSee('n\'appartient plus à aucune société')
            ->fillForm(['password' => 'mon-nouveau-mot-de-passe', 'passwordConfirmation' => 'mon-nouveau-mot-de-passe'])
            ->call('choisir')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('societe.creer'));

        $compte->refresh();
        $this->assertTrue(Hash::check('mon-nouveau-mot-de-passe', $compte->password));
        $this->assertSame(StatutCompte::Retire, $compte->statut);
        $this->assertGuest();
        $this->assertFalse($compte->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($compte->canAccessPanel(Filament::getPanel('societe')));
    }

    /**
     * Choisir son mot de passe ou refuser le compte, sur la page du lien,
     * ferme toutes les sessions du compte.
     */
    public function test_toute_decision_sur_la_page_de_lien_supprime_les_sessions_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $choisit = $this->membre($magasin, RoleMagasin::Vendeur);
        $refuse = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->ouvrirUneSessionAilleurs($choisit);
        $this->ouvrirUneSessionAilleurs($refuse);

        Livewire::test(PageDuLien::class, ['email' => $choisit->email, 'token' => Password::broker()->createToken($choisit)])
            ->fillForm(['password' => 'mon-nouveau-mot-de-passe', 'passwordConfirmation' => 'mon-nouveau-mot-de-passe'])
            ->call('choisir');
        Livewire::test(PageDuLien::class, ['email' => $refuse->email, 'token' => Password::broker()->createToken($refuse)])
            ->callAction('refuserCeCompte');

        $this->assertSessionsFermees($choisit);
        $this->assertSessionsFermees($refuse);
        $this->assertSame([StatutCompte::Actif, StatutCompte::Retire], [$choisit->refresh()->statut, $refuse->refresh()->statut]);
        $this->assertInstanceOf(User::class, $choisit);
    }
}
