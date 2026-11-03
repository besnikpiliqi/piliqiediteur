<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
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
use Livewire\Attributes\Locked;

/**
 * La page du lien « Mot de passe oublié » envoyé au propriétaire (voir
 * DemandeDeLien), propre à PILIQI : le propriétaire y choisit son mot de
 * passe, suivant la règle du mot de passe choisi (voir RegleDuMotDePasse).
 * Le compte se cherche par l'adresse du lien parmi les propriétaires
 * seulement (voir FournisseurDesComptes) ; seule la requête qui choisit
 * consomme le jeton, dans sa transaction (voir
 * ChoisirSonMotDePasseParLeLienAction). Un lien expiré, déjà servi,
 * forgé, ou celui d'un compte qui n'est plus propriétaire reçoit la même
 * réponse. La page n'ouvre aucune session.
 *
 * @property Form $form
 */
class PageDuLien extends SimplePage
{
    use InteractsWithFormActions;

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
                    ->content(fn (): string => $this->compteDuLien() === null ? ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE : 'Choisissez votre nouveau mot de passe.'),
                TextInput::make('password')
                    ->label('Votre mot de passe')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->rules(fn (): array => RegleDuMotDePasse::regles($this->compteDuLien()?->identifiantDeConnexion()))
                    ->same('passwordConfirmation')
                    ->visible(fn (): bool => $this->compteDuLien() !== null),
                TextInput::make('passwordConfirmation')
                    ->label('Confirmez-le')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->compteDuLien() !== null),
            ])
            ->statePath('data');
    }

    public function choisir(): void
    {
        $compte = $this->compteDuLien();

        if ($compte === null) {
            Notification::make()->title(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)->danger()->send();

            return;
        }

        $donnees = $this->form->getState();

        try {
            app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte, (string) $this->token, $donnees['password']);
        } catch (AuthorizationException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

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
                ->label('Choisir mon mot de passe')
                ->submit('choisir')
                ->visible(fn (): bool => $this->compteDuLien() !== null),
        ];
    }

    /**
     * Le propriétaire du lien, tel que la base le donne ; null sans lien
     * valable.
     */
    public function compteDuLien(): ?User
    {
        $compte = FournisseurDesComptes::proprietaireDeLAdresse((string) $this->email);

        return $compte !== null && filled($this->token) && app(JetonsDuLien::class)->estValable($compte, (string) $this->token) ? $compte : null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Votre mot de passe';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Votre mot de passe '.config('app.name');
    }
}
