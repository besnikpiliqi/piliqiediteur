<?php

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile;

/**
 * Chacun change ici son mot de passe en donnant l'actuel : une caisse
 * restée ouverte ne suffit pas à s'approprier le compte. S'il l'a oublié,
 * il passe par « Mot de passe oublié » sur la page de connexion. Ce sont
 * les seuls chemins pour un compte que le magasin ne réinitialise pas
 * lui-même depuis l'équipe (propriétaire, compte rattaché à plusieurs
 * magasins, membre qui agit sur son propre compte).
 *
 * Le nom et l'adresse e-mail de connexion n'y sont pas modifiables : ils
 * restent ceux saisis à la création du compte.
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

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->required()
            ->validationMessages([
                'required' => 'Saisissez le nouveau mot de passe.',
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
     * non plus.
     */
    protected function afterSave(): void
    {
        $this->data['mot_de_passe_actuel'] = null;
    }
}
