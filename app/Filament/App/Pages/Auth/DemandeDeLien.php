<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Password;

/**
 * « Mot de passe oublié », pour le seul propriétaire : un lien part vers
 * son adresse de connexion, cherchée parmi les propriétaires seulement
 * (voir FournisseurDesComptes ; une adresse en attente de son lien ou
 * celle d'une inscription close n'en est pas une, et ne reçoit rien), et
 * mène à la page du lien (voir PageDuLien), où il choisit son mot de
 * passe. L'adresse de contact d'un autre compte ne reçoit rien : un accès
 * perdu s'y redonne par qui gère le compte (voir RedonnerLAccesAction).
 * La réponse est toujours la même, que
 * l'adresse soit celle d'un propriétaire ou non, qu'un lien vienne ou non
 * de partir : elle ne révèle rien. Un serveur d'envoi en panne n'y change
 * rien et ne donne jamais une erreur 500 : le lien ne part pas, le journal
 * de l'application le dit (voir CanalDesCourriels), et la personne le
 * redemande plus tard.
 */
class DemandeDeLien extends RequestPasswordReset
{
    public const REPONSE = 'Si cette adresse est celle d\'un propriétaire, un lien vient d\'y être envoyé.';

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
            ['email' => User::normaliserEmail(ValeurSimple::enTexte($donnees['email']))],
            function (User $compte, string $jeton): void {
                $compte->notify(new LienPourChoisirSonMotDePasse(
                    Filament::getPanel('app')->getResetPasswordUrl($jeton, $compte),
                    Reglage::entier('auth.passwords.'.Reglage::texte('auth.defaults.passwords').'.expire'),
                ));
            },
        );

        Notification::make()->title(self::REPONSE)->success()->send();

        $this->form->fill();
    }
}
