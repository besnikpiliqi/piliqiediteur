<?php

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Auth\EditProfile;

/**
 * Chacun change ici son nom, son adresse e-mail et son mot de passe :
 * c'est le seul chemin pour un compte que le magasin ne réinitialise pas
 * lui-même (propriétaire, compte rattaché à plusieurs magasins).
 *
 * Changer l'adresse ou le mot de passe demande le mot de passe actuel : une
 * caisse restée ouverte ne suffit pas à s'approprier le compte. Après un
 * changement de mot de passe, AuthenticateSession déconnecte les autres
 * sessions du compte ; celle-ci reste ouverte.
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

    protected function getMotDePasseActuelFormComponent(): Component
    {
        return TextInput::make('mot_de_passe_actuel')
            ->label('Mot de passe actuel')
            ->helperText('Obligatoire pour changer l\'adresse e-mail ou le mot de passe.')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required(fn (Get $get): bool => filled($get('password')) || $get('email') !== $this->getUser()->getAttribute('email'))
            ->rule('current_password:'.Filament::getAuthGuard())
            ->validationMessages([
                'required' => 'Saisissez votre mot de passe actuel pour changer l\'adresse e-mail ou le mot de passe.',
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ])
            ->dehydrated(false);
    }

    /**
     * Filament vide les champs du nouveau mot de passe après
     * l'enregistrement ; le mot de passe actuel ne doit pas rester saisi
     * non plus.
     */
    protected function afterSave(): void
    {
        $this->data['mot_de_passe_actuel'] = null;
    }
}
