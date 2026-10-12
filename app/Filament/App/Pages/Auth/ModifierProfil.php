<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * Chacun change ici son nom, son adresse e-mail de connexion et son mot
 * de passe, en donnant toujours son mot de passe actuel : une caisse
 * restée ouverte ne suffit pas à s'approprier le compte. Personne ne les
 * change pour un autre : sa société ne peut que lui faire envoyer un lien
 * pour choisir son mot de passe, ou redonner un provisoire à un compte qui
 * ne l'a pas encore changé. Qui a oublié son mot de passe passe par « Mot
 * de passe oublié » sur la page de connexion.
 *
 * Le nom change tout de suite. Le nouveau mot de passe est facultatif :
 * laissé vide, l'ancien reste ; changé, il ferme toutes les autres
 * sessions du compte, partout, et renouvelle le « se souvenir de moi » ;
 * celle-ci reste ouverte (voir SessionsDesComptes). Une nouvelle adresse
 * ne prend effet qu'après un clic sur le lien envoyé à cette adresse,
 * valable 24 heures, et l'ancienne est alors prévenue (voir
 * DemanderUneNouvelleAdresseAction et ConfirmerNouvelleAdresseAction) :
 * personne ne prend ainsi l'adresse d'un autre en la tapant ici. Elle
 * s'enregistre en minuscules (voir User::normaliserEmail()) et doit être
 * libre parmi tous les comptes, retirés compris, sans que le refus dise à
 * qui elle appartient.
 *
 * Le registre fiscal fige le nom de l'opérateur dans le contenu signé de
 * chaque enregistrement : un changement de nom n'y réécrit rien.
 */
class ModifierProfil extends EditProfile
{
    /**
     * La nouvelle adresse que l'enregistrement vient de demander, en
     * attente de confirmation.
     */
    private ?string $adresseDemandee = null;

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getMotDePasseActuelFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->adresseDeConnexion()
            ->helperText(function (): ?string {
                $compte = $this->getUser();

                return $compte instanceof User && $compte->nouvelle_adresse !== null && $compte->nouvelle_adresse_expire_le?->isFuture()
                    ? "Nouvelle adresse en attente : {$compte->nouvelle_adresse}. Elle remplacera celle-ci quand vous aurez suivi le lien qui lui a été envoyé."
                    : null;
            })
            ->validationMessages([
                'unique' => 'Cette adresse e-mail ne peut pas être utilisée.',
            ]);
    }

    protected function getMotDePasseActuelFormComponent(): Component
    {
        return TextInput::make('mot_de_passe_actuel')
            ->label('Mot de passe actuel')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required()
            ->rule('current_password:'.Filament::getAuthGuard())
            ->validationMessages([
                'required' => 'Saisissez votre mot de passe actuel.',
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ])
            ->dehydrated(false);
    }

    /**
     * Le nom et le mot de passe s'écrivent tout de suite ; une adresse qui
     * change ne fait que partir en confirmation.
     *
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $adresse = User::normaliserEmail((string) ($data['email'] ?? $record->email));
        unset($data['email']);

        $record->update($data);

        if (array_key_exists('password', $data)) {
            app(SessionsDesComptes::class)->fermer($record, request()->hasSession() ? request()->session()->getId() : null);
        }

        if ($adresse !== $record->email) {
            app(DemanderUneNouvelleAdresseAction::class)->handle($record, $adresse);
            $this->adresseDemandee = $adresse;
        }

        return $record;
    }

    /**
     * Le formulaire garde l'adresse actuelle, et le mot de passe actuel ne
     * reste pas saisi.
     */
    protected function afterSave(): void
    {
        $this->data['mot_de_passe_actuel'] = null;
        $this->data['email'] = $this->getUser()->email;
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->adresseDemandee === null) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->success()
            ->title('Profil enregistré')
            ->body("Un lien de confirmation, valable 24 heures, vient d'être envoyé à {$this->adresseDemandee} : votre adresse de connexion ne changera qu'une fois ce lien suivi.");
    }
}
