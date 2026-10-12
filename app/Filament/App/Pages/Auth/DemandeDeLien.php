<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Password;

/**
 * « Mot de passe oublié » : un lien part vers l'adresse de tout compte qui
 * l'utilise, qu'il soit actif, suspendu ou retiré, et mène à la page du
 * lien (voir PageDuLien), où la personne choisit son mot de passe ou
 * refuse le compte s'il peut encore l'être. La réponse est toujours la
 * même, que l'adresse soit celle d'un compte ou non, qu'un lien vienne ou
 * non de partir : elle ne révèle rien de l'existence ni de l'état d'un
 * compte.
 */
class DemandeDeLien extends RequestPasswordReset
{
    public const REPONSE = 'Si cette adresse est celle d\'un compte, un lien vient d\'y être envoyé.';

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $donnees = $this->form->getState();

        Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => User::normaliserEmail((string) $donnees['email'])],
            function (User $compte, string $jeton): void {
                $compte->notify(new LienPourChoisirSonMotDePasse(
                    Filament::getPanel('app')->getResetPasswordUrl($jeton, $compte),
                    null,
                    (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                ));
            },
        );

        Notification::make()->title(self::REPONSE)->success()->send();

        $this->form->fill();
    }
}
