<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\ChangerSonMotDePasseProvisoireAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use LogicException;

/**
 * La première page de toute personne dont le compte a été créé par la
 * société, avant toute autre (voir ExigerUnMotDePasseDefinitif), dans les
 * deux panneaux : elle remplace son mot de passe provisoire par le sien,
 * ce qui accepte le compte (voir ChangerSonMotDePasseProvisoireAction), ou
 * le refuse tant que son adresse n'a jamais été confirmée (voir
 * RefuserSonCompteAction) : refusé, le compte est retiré et sa session se
 * ferme. Fermer la page n'est ni une acceptation ni un refus. Un mot de
 * passe provisoire expiré n'ouvre plus rien : la page le dit, et laisse
 * seulement refuser le compte, s'il peut l'être.
 *
 * @property Form $form
 */
class ChangerMotDePasseProvisoire extends SimplePage
{
    use InteractsWithFormActions;

    protected static string $view = 'filament.pages.auth.changer-mot-de-passe-provisoire';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function registerRoutes(): void
    {
        Route::get('/mot-de-passe-provisoire', static::class)->name('mot-de-passe-provisoire');
    }

    public function mount(): void
    {
        if (! $this->compte()->doitChangerSonMotDePasse()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('expire')
                    ->hiddenLabel()
                    ->content(ChangerSonMotDePasseProvisoireAction::MESSAGE_EXPIRE)
                    ->visible(fn (): bool => $this->compte()->sonMotDePasseProvisoireAExpire()),
                TextInput::make('password')
                    ->label('Votre nouveau mot de passe')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation')
                    ->hidden(fn (): bool => $this->compte()->sonMotDePasseProvisoireAExpire()),
                TextInput::make('passwordConfirmation')
                    ->label('Confirmez-le')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->dehydrated(false)
                    ->hidden(fn (): bool => $this->compte()->sonMotDePasseProvisoireAExpire()),
            ])
            ->statePath('data');
    }

    public function accepter(): void
    {
        $compte = $this->compte();
        $donnees = $this->form->getState();

        try {
            app(ChangerSonMotDePasseProvisoireAction::class)->handle($compte, $donnees['password'], session()->getId());
        } catch (LogicException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        session()->put(['password_hash_'.Filament::getAuthGuard() => $compte->refresh()->getAuthPassword()]);
        Notification::make()->title('Votre mot de passe est enregistré.')->success()->send();

        $this->redirect(Filament::getUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('accepter')
                ->label(fn (): string => $this->compte()->peutRefuserSonCompte() ? 'Accepter et choisir mon mot de passe' : 'Choisir mon mot de passe')
                ->submit('accepter')
                ->hidden(fn (): bool => $this->compte()->sonMotDePasseProvisoireAExpire()),
            $this->refuserCeCompteAction(),
        ];
    }

    public function refuserCeCompteAction(): Action
    {
        return Action::make('refuserCeCompte')
            ->label('Refuser ce compte')
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->compte()->peutRefuserSonCompte())
            ->requiresConfirmation()
            ->modalHeading('Refuser ce compte ?')
            ->modalDescription('Ce compte a été créé à votre adresse par une société. Si vous le refusez, il est retiré de cette société, son mot de passe ne sert plus et vous êtes déconnecté. Vous pourrez ensuite, après avoir choisi votre mot de passe par « Mot de passe oublié », créer votre propre société avec ce compte.')
            ->action(function (): void {
                $compte = $this->compte();

                try {
                    app(RefuserSonCompteAction::class)->handle($compte);
                } catch (AuthorizationException $refus) {
                    Notification::make()->title($refus->getMessage())->danger()->send();

                    return;
                }

                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();
                Notification::make()->title('Vous avez refusé ce compte : il est retiré de la société.')->success()->send();

                $this->redirect(Filament::getLoginUrl());
            });
    }

    public function getTitle(): string|Htmlable
    {
        return 'Votre mot de passe';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Choisissez votre mot de passe';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Votre compte a été créé avec un mot de passe provisoire : remplacez-le par le vôtre, que vous seul connaîtrez.';
    }

    private function compte(): User
    {
        $compte = Filament::auth()->user();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
