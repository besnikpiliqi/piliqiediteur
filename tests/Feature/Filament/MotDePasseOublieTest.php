<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un propriétaire qui a oublié son mot de passe n'a personne pour le lui
 * réinitialiser : il le récupère lui-même par un lien envoyé à son adresse
 * de connexion (voir DemandeDeLien et PageDuLien). L'adresse de contact
 * d'un autre compte ne reçoit rien, et la réponse est toujours la même.
 */
class MotDePasseOublieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_la_page_de_connexion_propose_mot_de_passe_oublie(): void
    {
        $this->get('/app/login')
            ->assertSee(route('filament.app.auth.password-reset.request'));
    }

    public function test_la_demande_envoie_un_lien_de_reinitialisation_au_proprietaire(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaire();

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($proprietaire, LienPourChoisirSonMotDePasse::class, fn (LienPourChoisirSonMotDePasse $lien): bool => str_contains($lien->url, 'token='));
    }

    /**
     * Le compte se retrouve par son adresse quelle que soit la casse tapée
     * (voir FournisseurDesComptes).
     */
    public function test_la_demande_avec_l_adresse_tapee_en_majuscules_envoie_le_lien(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaire();

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'Patron@Boutique.TEST'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($proprietaire, LienPourChoisirSonMotDePasse::class);
    }

    public function test_la_demande_et_le_lien_recu_sont_rediges_en_francais(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaire();

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertNotified(DemandeDeLien::REPONSE);

        $email = Notification::sent($proprietaire, LienPourChoisirSonMotDePasse::class)->sole()->toMail($proprietaire);
        $contenu = (string) $email->render();
        $this->assertSame('Choisissez votre mot de passe '.config('app.name'), $email->subject);
        $this->assertStringContainsString('Un lien pour choisir votre mot de passe', $contenu);
        $this->assertStringContainsString('Choisir mon mot de passe', $contenu);
        $this->assertStringContainsString('Ce lien est valable 60 minutes.', $contenu);
        $this->assertStringContainsString('Cordialement,', $contenu);
        $this->assertStringNotContainsString('Reset Password', $contenu);
        $this->assertStringNotContainsString('Regards', $contenu);
    }

    public function test_le_lien_recu_permet_de_choisir_un_nouveau_mot_de_passe(): void
    {
        $proprietaire = $this->proprietaire();
        $jeton = Password::broker()->createToken($proprietaire);

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jeton])
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
            ])
            ->call('choisir')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $proprietaire->fresh()->password));
    }

    public function test_un_lien_invalide_est_refuse_avec_un_message_en_francais(): void
    {
        $proprietaire = $this->proprietaire();

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => 'jeton-invente'])
            ->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'nouveau-mot-de-passe')
            ->set('data.passwordConfirmation', 'nouveau-mot-de-passe')
            ->call('choisir')
            ->assertNotified(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE);

        $this->assertTrue(Hash::check('mot-de-passe-actuel', $proprietaire->fresh()->password));
    }

    /**
     * L'adresse de contact d'un compte qui n'est pas propriétaire, comme
     * une adresse inconnue, ne reçoit aucun lien ; la réponse est la même
     * que pour le propriétaire.
     */
    public function test_mot_de_passe_oublie_avec_l_adresse_d_un_compte_qui_n_est_pas_proprietaire_n_envoie_rien_et_repond_pareil(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Notification::fake();
        $proprietaire = $this->proprietaire();
        $magasin = $proprietaire->societe->magasins()->firstOrFail();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'vendeur@boutique.test']);

        foreach (['vendeur@boutique.test', 'personne@boutique.test', 'patron@boutique.test'] as $adresse) {
            Livewire::test(DemandeDeLien::class)
                ->fillForm(['email' => $adresse])
                ->call('request')
                ->assertHasNoFormErrors()
                ->assertNotified(DemandeDeLien::REPONSE);
            $this->travel(2)->minutes();
        }

        Notification::assertNothingSentTo($vendeur);
        Notification::assertSentTo($proprietaire, LienPourChoisirSonMotDePasse::class);
        Notification::assertSentTimes(LienPourChoisirSonMotDePasse::class, 1);
        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', ['vendeur@boutique.test', 'personne@boutique.test'])->count());
    }

    /**
     * Le lien « Mot de passe oublié » d'un propriétaire qui transmet
     * ensuite la propriété ne sert plus : ni la page, ni l'action.
     */
    public function test_le_lien_d_un_proprietaire_ne_sert_plus_apres_la_transmission_de_la_propriete(): void
    {
        $proprietaire = $this->proprietaire();
        $magasin = $proprietaire->societe->magasins()->firstOrFail();
        $jeton = Password::broker()->createToken($proprietaire);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $this->demanderLaTransmission($proprietaire, $administrateur, 'mot-de-passe-actuel'), self::MOT_DE_PASSE_CHOISI);

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jeton])
            ->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
            ->assertActionHidden('choisir');
        $this->assertThrows(fn () => app(ChoisirSonMotDePasseParLeLienAction::class)->handle($proprietaire->refresh(), $jeton, 'nouveau-mot-de-passe'), AuthorizationException::class, ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE);

        $this->assertTrue(Hash::check('mot-de-passe-actuel', $proprietaire->refresh()->password));
    }

    /**
     * Le propriétaire d'une société qui a un magasin, patron@boutique.test.
     */
    private function proprietaire(): User
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test', 'password' => 'mot-de-passe-actuel']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        return $proprietaire->refresh();
    }
}
