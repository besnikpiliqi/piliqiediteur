<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
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
use Livewire\Attributes\Locked;
use LogicException;

/**
 * La page du lien de la transmission de la propriété (voir
 * DemanderLaTransmissionDeLaProprieteAction), dans l'espace de la société,
 * hors de toute société : l'administrateur s'y connecte s'il ne l'est pas
 * (le panneau le mène à la connexion, puis le ramène ici), puis confirme
 * en redonnant son mot de passe (voir
 * AccepterLaTransmissionDeLaProprieteAction). Un lien inconnu, expiré,
 * annulé, déjà suivi, remplacé ou ouvert par un autre compte ne propose
 * rien et le dit sans détail. Ouvrir la page ne change rien.
 *
 * @property Form $form
 */
class AccepterLaTransmission extends SimplePage
{
    use InteractsWithFormActions;

    protected static string $view = 'filament.societe.pages.accepter-la-transmission';

    #[Locked]
    public string $jeton = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function registerRoutes(): void
    {
        Route::get('/transmission/{jeton}', static::class)
            ->where('jeton', '[A-Za-z0-9]{64}')
            ->middleware('throttle:10,1')
            ->name('transmission');
    }

    public function mount(string $jeton): void
    {
        $this->jeton = $jeton;
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('etat')
                    ->hiddenLabel()
                    ->content(fn (): string => $this->estValable()
                        ? 'En confirmant, vous devenez le propriétaire de la société : vous aurez tous les droits dans la société et dans chacun de ses magasins, sauf celui de vendre, et votre adresse '.$this->compte()->email.' deviendra votre adresse de connexion.'
                        : AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN),
                TextInput::make('mot_de_passe')
                    ->label('Votre mot de passe')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')
                    ->required()
                    ->visible(fn (): bool => $this->estValable()),
            ])
            ->statePath('data');
    }

    public function accepter(): void
    {
        if (! $this->estValable()) {
            Notification::make()->title(AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN)->danger()->send();

            return;
        }

        $donnees = $this->form->getState();

        try {
            app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($this->compte(), $this->jeton, (string) $donnees['mot_de_passe']);
        } catch (AuthorizationException|LogicException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();
            $this->data['mot_de_passe'] = null;

            return;
        }

        Notification::make()->title('Vous êtes désormais le propriétaire de la société.')->success()->send();
        $this->redirect(TableauDeBord::getUrl(tenant: $this->compte()->refresh()->societe));
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('accepter')
                ->label('Accepter la propriété')
                ->submit('accepter')
                ->visible(fn (): bool => $this->estValable()),
        ];
    }

    public function estValable(): bool
    {
        return AccepterLaTransmissionDeLaProprieteAction::demandeDestineeA($this->compte(), $this->jeton) !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Transmission de la propriété';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Accepter la propriété de la société';
    }

    private function compte(): User
    {
        $compte = Filament::auth()->user();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
