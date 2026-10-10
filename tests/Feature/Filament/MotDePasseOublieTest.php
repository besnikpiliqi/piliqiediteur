<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Models\User;
use Filament\Notifications\Auth\ResetPassword as LienDeReinitialisation;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un propriétaire qui a oublié son mot de passe n'a personne pour le lui
 * réinitialiser : il le récupère lui-même par un lien envoyé par e-mail.
 */
class MotDePasseOublieTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_de_connexion_propose_mot_de_passe_oublie(): void
    {
        $this->get('/app/login')
            ->assertSee(route('filament.app.auth.password-reset.request'));
    }

    public function test_la_demande_envoie_un_lien_de_reinitialisation_au_proprietaire(): void
    {
        Notification::fake();
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($proprietaire, LienDeReinitialisation::class);
    }

    /**
     * Le compte se retrouve par son adresse quelle que soit la casse tapée
     * (voir FournisseurDesComptes).
     */
    public function test_la_demande_avec_l_adresse_tapee_en_majuscules_envoie_le_lien(): void
    {
        Notification::fake();
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'Patron@Boutique.TEST'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($proprietaire, LienDeReinitialisation::class);
    }

    public function test_la_demande_et_le_lien_recu_sont_rediges_en_francais(): void
    {
        Notification::fake();
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertNotified('Le lien de réinitialisation du mot de passe a été envoyé par e-mail.');

        $email = Notification::sent($proprietaire, LienDeReinitialisation::class)->sole()->toMail($proprietaire);
        $contenu = (string) $email->render();
        $this->assertSame('Réinitialisation de votre mot de passe', $email->subject);
        $this->assertStringContainsString('Vous recevez cet e-mail car une réinitialisation du mot de passe de votre compte a été demandée.', $contenu);
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $contenu);
        $this->assertStringContainsString('Ce lien expire dans 60 minutes.', $contenu);
        $this->assertStringContainsString('Cordialement,', $contenu);
        $this->assertStringNotContainsString('Reset Password', $contenu);
        $this->assertStringNotContainsString('Regards', $contenu);
    }

    public function test_le_lien_recu_permet_de_choisir_un_nouveau_mot_de_passe(): void
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test', 'password' => 'mot-de-passe-oublie']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $jeton = Password::broker()->createToken($proprietaire);

        Livewire::test(ResetPassword::class, ['email' => 'patron@boutique.test', 'token' => $jeton])
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $proprietaire->fresh()->password));
    }

    public function test_un_lien_invalide_est_refuse_avec_un_message_en_francais(): void
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test', 'password' => 'mot-de-passe-actuel']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(ResetPassword::class, ['email' => 'patron@boutique.test', 'token' => 'jeton-invente'])
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
            ])
            ->call('resetPassword')
            ->assertNotified('Ce lien de réinitialisation du mot de passe n\'est pas valide ou a expiré.');

        $this->assertTrue(Hash::check('mot-de-passe-actuel', $proprietaire->fresh()->password));
    }
}
