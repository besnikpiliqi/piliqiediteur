<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\LimitationDesLiens;
use App\Domain\Tenancy\Support\IdentifiantDeConnexion;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * « Mot de passe oublié », pour le seul propriétaire : il tape son adresse
 * de connexion ou son identifiant, et un lien part vers son adresse de
 * connexion, cherchée parmi les propriétaires seulement (voir
 * FournisseurDesComptes ; une adresse en attente de son lien ou celle d'une
 * inscription close n'en est pas une, et ne reçoit rien ; un identifiant
 * qui désigne un autre compte qu'un propriétaire à adresse confirmée non
 * plus), et mène à la page du lien (voir PageDuLien), où il choisit son mot
 * de passe. L'adresse de contact d'un autre compte ne reçoit rien : un
 * accès perdu s'y redonne par qui gère le compte (voir
 * RedonnerLAccesAction).
 * La réponse est toujours la même, que la saisie désigne un propriétaire ou
 * non, qu'un lien vienne ou non de partir, et elle vient après le même
 * délai (voir Timebox, auth.timebox_duration) : elle ne révèle rien. La
 * demande est limitée (voir LimitationDesLiens) : au plus 3 par heure et 10
 * par jour pour une même saisie, 10 par heure par adresse IP, chacune
 * comptée que la saisie désigne quelqu'un ou non, jamais pour de bon. Un
 * seul lien vaut à la fois : le nouveau rend caducs les précédents. Un
 * serveur d'envoi en panne n'y change rien et ne donne jamais une erreur
 * 500 : le lien ne part pas, le journal de l'application le dit (voir
 * CanalDesCourriels), et la personne le redemande plus tard.
 */
class DemandeDeLien extends RequestPasswordReset
{
    public const REPONSE = 'Si cette saisie est celle d\'un propriétaire, un lien vient d\'être envoyé à son adresse de connexion.';

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Adresse e-mail ou identifiant')
            ->helperText('L\'adresse de connexion du propriétaire, ou son identifiant de 8 chiffres.')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus();
    }

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $donnees = $this->form->getState();
        $saisie = trim(ValeurSimple::enTexte($donnees['email']));
        $normalisee = str_contains($saisie, '@') ? User::normaliserEmail($saisie) : IdentifiantDeConnexion::normaliser($saisie);
        $attente = app(LimitationDesLiens::class)->demande($normalisee, (string) request()->ip());

        if ($attente > 0) {
            Notification::make()->title(LimitationDesLiens::message($attente))->danger()->send();

            return;
        }

        (new Timebox)->call(function () use ($normalisee): void {
            $adresse = $this->adresseDuPropietaire($normalisee);

            if ($adresse === null) {
                Hash::make(Str::random(40));

                return;
            }

            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $adresse],
                function (User $compte, string $jeton): void {
                    $compte->notify(new LienPourChoisirSonMotDePasse(
                        Filament::getPanel('app')->getResetPasswordUrl($jeton, $compte),
                        Reglage::entier('auth.passwords.'.Reglage::texte('auth.defaults.passwords').'.expire'),
                    ));
                },
            );
        }, ValeurSimple::enEntier(config('auth.timebox_duration')));

        Notification::make()->title(self::REPONSE)->success()->send();

        $this->form->fill();
    }

    /**
     * L'adresse de connexion du propriétaire que désigne cette saisie : son
     * adresse elle-même, ou, pour un identifiant, l'adresse du propriétaire
     * qu'il désigne ; null pour toute autre saisie.
     */
    private function adresseDuPropietaire(string $normalisee): ?string
    {
        if (str_contains($normalisee, '@')) {
            return $normalisee;
        }

        $compte = FournisseurDesComptes::compteDeLIdentifiant($normalisee);

        return $compte?->estProprietaire() === true && $compte->aUneAdresseDeConnexion() ? (string) $compte->email : null;
    }
}
