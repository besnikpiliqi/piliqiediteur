<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * La page d'un lien envoyé à l'adresse d'un compte (« Mot de passe
 * oublié », voir DemandeDeLien, ou renouvellement de l'accès, voir
 * EnvoyerUnLienDeReinitialisationAction), propre à PILIQI. Elle s'affiche
 * selon l'état du compte, sans rien consommer :
 * - compte actif : « Accepter et choisir mon mot de passe » (« Choisir mon
 *   mot de passe » s'il n'est plus refusable), et « Refuser ce compte »
 *   s'il l'est encore ;
 * - compte suspendu : seulement « Refuser ce compte » s'il est refusable ;
 *   ni nouveau mot de passe, ni levée de la suspension ;
 * - compte retiré, par la société ou par son propre refus : « Choisir mon
 *   mot de passe », sans ouvrir aucun panneau, puis la page de création de
 *   sa société (voir CreerSaSocieteController), ou un rattachement par le
 *   lien qu'une société lui envoie ; « Refuser ce compte » s'il est
 *   refusable, la page prévenant que choisir son mot de passe confirme
 *   l'adresse et ferme le refus.
 * Seule la requête qui décide consomme le jeton, dans sa transaction (voir
 * ChoisirSonMotDePasseParLeLienAction et RefuserSonCompteAction) : le lien
 * suivi confirme l'adresse, et toutes les sessions du compte se ferment.
 * Un lien expiré, déjà servi ou forgé reçoit la même réponse, quel que
 * soit le compte. La page n'ouvre aucune session.
 *
 * @property Form $form
 */
class PageDuLien extends SimplePage
{
    use InteractsWithFormActions;

    public const MESSAGE_RETIRE = 'Choisir votre mot de passe confirme votre adresse : vous ne pourrez plus refuser ce compte, et la société qui l\'a créé pourra vous réintégrer.';

    protected static string $view = 'filament.pages.auth.page-du-lien';

    #[Locked]
    public ?string $email = null;

    #[Locked]
    public ?string $token = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(?string $email = null, ?string $token = null): void
    {
        $this->email = User::normaliserEmail((string) ($email ?? request()->query('email')));
        $this->token = (string) ($token ?? request()->query('token'));

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('etat')
                    ->hiddenLabel()
                    ->content(fn (): string => $this->messageDeLEtat()),
                TextInput::make('password')
                    ->label('Votre mot de passe')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation')
                    ->visible(fn (): bool => $this->peutChoisir()),
                TextInput::make('passwordConfirmation')
                    ->label('Confirmez-le')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->peutChoisir()),
            ])
            ->statePath('data');
    }

    public function choisir(): void
    {
        $compte = $this->compteDuLien();

        if ($compte === null || ! $this->peutChoisir()) {
            Notification::make()->title(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE)->danger()->send();

            return;
        }

        $donnees = $this->form->getState();

        try {
            app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, (string) $this->token, $donnees['password']);
        } catch (AuthorizationException|LogicException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        if ($compte->refresh()->estRetire()) {
            Notification::make()->title('Votre mot de passe est enregistré : ce compte n\'appartient plus à aucune société, et vous pouvez créer la vôtre avec lui.')->success()->send();
            $this->redirect(route('societe.creer'));

            return;
        }

        Notification::make()->title('Votre mot de passe est enregistré : vous pouvez vous connecter.')->success()->send();
        $this->redirect(Filament::getLoginUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('choisir')
                ->label(fn (): string => ($this->compteDuLien()?->estActif() ?? false) && ($this->compteDuLien()?->peutRefuserSonCompte() ?? false) ? 'Accepter et choisir mon mot de passe' : 'Choisir mon mot de passe')
                ->submit('choisir')
                ->visible(fn (): bool => $this->peutChoisir()),
            $this->refuserCeCompteAction(),
        ];
    }

    public function refuserCeCompteAction(): Action
    {
        return Action::make('refuserCeCompte')
            ->label('Refuser ce compte')
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->lienValable() && ($this->compteDuLien()?->peutRefuserSonCompte() ?? false))
            ->requiresConfirmation()
            ->modalHeading('Refuser ce compte ?')
            ->modalDescription('Ce compte a été créé à votre adresse par une société. Refusé, il est retiré de cette société et son mot de passe ne sert plus ; vous pourrez ensuite, en choisissant votre mot de passe par un nouveau lien, créer votre propre société avec lui.')
            ->action(function (): void {
                $compte = $this->compteDuLien();

                try {
                    abort_if($compte === null, 404);
                    app(RefuserSonCompteAction::class)->handle($compte, fn (User $relu): bool => app(JetonsDuLien::class)->consommer($relu, (string) $this->token));
                } catch (AuthorizationException $refus) {
                    Notification::make()->title($refus->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Vous avez refusé ce compte : il est retiré de la société qui l\'avait créé.')->success()->send();

                $this->redirect(Filament::getLoginUrl());
            });
    }

    /**
     * Le compte du lien, tel que la base le donne ; null sans lien
     * valable.
     */
    public function compteDuLien(): ?User
    {
        if (! $this->lienValable()) {
            return null;
        }

        return User::query()->where('email', $this->email)->first();
    }

    public function lienValable(): bool
    {
        $compte = User::query()->where('email', (string) $this->email)->first();

        return $compte !== null && filled($this->token) && app(JetonsDuLien::class)->estValable($compte, (string) $this->token);
    }

    /**
     * Choisir son mot de passe : un compte actif ou retiré, jamais un
     * compte suspendu.
     */
    private function peutChoisir(): bool
    {
        $compte = $this->compteDuLien();

        return $compte !== null && ! $compte->estSuspendu();
    }

    private function messageDeLEtat(): string
    {
        $compte = $this->compteDuLien();

        return match (true) {
            $compte === null => RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE,
            $compte->estSuspendu() && $compte->peutRefuserSonCompte() => 'Ce compte est suspendu par sa société : vous pouvez seulement le refuser, s\'il n\'est pas le vôtre.',
            $compte->estSuspendu() => ChoisirSonMotDePasseParLeLienAction::MESSAGE_SUSPENDU,
            $compte->estRetire() && $compte->peutRefuserSonCompte() => 'Ce compte a été retiré de sa société. '.self::MESSAGE_RETIRE,
            $compte->estRetire() => 'Ce compte n\'appartient plus à aucune société : choisissez votre mot de passe pour créer votre propre société avec lui, ou accepter un rattachement.',
            $compte->peutRefuserSonCompte() => 'Une société a créé un compte à votre adresse : acceptez-le en choisissant votre mot de passe, ou refusez-le s\'il n\'est pas le vôtre.',
            default => 'Choisissez votre nouveau mot de passe.',
        };
    }

    public function getTitle(): string|Htmlable
    {
        return 'Votre compte';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Votre compte '.config('app.name');
    }
}
