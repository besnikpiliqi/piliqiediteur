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
}
