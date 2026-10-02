<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Register;
use Illuminate\Database\Eloquent\Model;

/**
 * Un magasin ne s'ouvre jamais "vide" : l'inscription crée en une seule
 * opération le compte utilisateur ET son magasin, avec l'utilisateur
 * positionné comme propriétaire (voir CreerMagasinAction). On ne sépare
 * pas "créer un compte" de "créer un magasin" dans l'UI, ça n'aurait pas
 * de sens métier ici -- un utilisateur qui s'inscrit ouvre toujours son
 * propre magasin.
 */
class RegisterMagasin extends Register
{
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNomMagasinFormComponent(),
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getNomMagasinFormComponent(): Component
    {
        return TextInput::make('nom_magasin')
            ->label('Nom du magasin')
            ->required()
            ->maxLength(150)
            ->autofocus();
    }

    protected function handleRegistration(array $data): Model
    {
        $utilisateur = $this->getUserModel()::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        app(CreerMagasinAction::class)->handle([
            'nom' => $data['nom_magasin'],
            'email' => $data['email'],
        ], $utilisateur);

        return $utilisateur;
    }
}
