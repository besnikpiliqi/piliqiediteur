<?php

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Support\Facades\DB;

/**
 * Chacun change ici son nom, son adresse e-mail de connexion et son mot
 * de passe, en donnant toujours son mot de passe actuel : une caisse
 * restée ouverte ne suffit pas à s'approprier le compte. Le magasin ne
 * les change jamais ; depuis l'équipe, il ne peut que donner un nouveau
 * mot de passe à un membre (voir ReinitialiserMotDePasseMembreAction).
 * Qui a oublié son mot de passe passe par « Mot de passe oublié » sur la
 * page de connexion.
 *
 * Le nouveau mot de passe est facultatif : laissé vide, l'ancien reste.
 * L'adresse e-mail, enregistrée en minuscules (voir
 * User::normaliserEmail()), est unique parmi tous les comptes, supprimés
 * compris, et le refus ne dit pas à qui elle appartient. Une fois l'adresse
 * changée, un lien « Mot de passe oublié » déjà envoyé à l'ancienne ne
 * sert plus.
 *
 * Le registre fiscal fige le nom de l'opérateur dans le contenu signé de
 * chaque enregistrement : un changement de nom n'y réécrit rien.
 *
 * Après un changement de mot de passe, AuthenticateSession déconnecte les
 * autres sessions du compte ; celle-ci reste ouverte.
 */
class ModifierProfil extends EditProfile
{
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
     * Filament vide les champs du nouveau mot de passe après
     * l'enregistrement ; le mot de passe actuel ne doit pas rester saisi
     * non plus. Quand l'adresse a changé, le jeton « Mot de passe oublié »
     * de l'ancienne adresse est effacé : le lien déjà envoyé ne sert plus,
     * même si l'adresse sert un jour à un autre compte. La table des
     * jetons appartient aux comptes, pas à un magasin : elle se filtre par
     * adresse.
     */
    protected function afterSave(): void
    {
        $this->data['mot_de_passe_actuel'] = null;

        $utilisateur = $this->getUser();

        if ($utilisateur->wasChanged('email')) {
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
                ->where('email', $utilisateur->getPrevious()['email'])
                ->delete();
        }
    }
}
