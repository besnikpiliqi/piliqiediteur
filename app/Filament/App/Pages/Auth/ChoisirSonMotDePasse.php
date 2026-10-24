<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Http\Middleware\ExigerSonPropreMotDePasse;
use App\Http\Middleware\MenerUnCompteSansMagasin;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

/**
 * La page où un responsable, un administrateur ou un expert-comptable dont
 * le mot de passe est encore son identifiant choisit le sien, avant toute
 * autre page des deux panneaux (voir ExigerSonPropreMotDePasse) : à sa
 * première connexion, ou à sa connexion suivante après avoir été promu à
 * l'un de ces rôles. Le mot de passe suit la règle du mot de passe choisi
 * (voir RegleDuMotDePasse) ; ses autres sessions se ferment, celle-ci reste
 * (voir ChangerSonMotDePasseAction), et n'exige plus rien.
 *
 * @property Form $form
 */
class ChoisirSonMotDePasse extends SimplePage
{
    use InteractsWithFormActions;

    protected static string $view = 'filament.pages.auth.choisir-son-mot-de-passe';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function registerRoutes(): void
    {
        Route::get('/mot-de-passe', static::class)->name('choisir-son-mot-de-passe');
    }

    public function mount(): void
    {
        if (! $this->compte()->doitChoisirSonMotDePasse()) {
            $this->redirect(MenerUnCompteSansMagasin::accueil());

            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('password')
                    ->label('Votre nouveau mot de passe')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->rules(RegleDuMotDePasse::regles($this->compte()->identifiantDeConnexion()))
                    ->helperText('Au moins 8 caractères ; ni votre identifiant, ni un seul caractère répété, ni une suite de chiffres.')
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label('Confirmez-le')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function choisir(): void
    {
        $compte = $this->compte();
        $donnees = $this->form->getState();

        app(ChangerSonMotDePasseAction::class)->handle($compte, $donnees['password'], session()->getId(), 'data.password');

        session()->put(['password_hash_'.Filament::getAuthGuard() => $compte->refresh()->getAuthPassword()]);
        session()->forget(ExigerSonPropreMotDePasse::A_LA_CONNEXION);
        Notification::make()->title('Votre mot de passe est enregistré.')->success()->send();

        $this->redirect(MenerUnCompteSansMagasin::accueil());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('choisir')->label('Choisir mon mot de passe')->submit('choisir'),
        ];
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
        return 'Votre mot de passe est encore votre identifiant : avec les droits de votre compte, choisissez-en un à vous, que vous seul connaîtrez.';
    }

    private function compte(): User
    {
        $compte = Filament::auth()->user();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
