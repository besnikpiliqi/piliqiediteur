<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Services\CodeMagasin;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Auth\Register;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Un magasin ne s'ouvre jamais "vide" : l'inscription crée en une seule
 * opération le compte utilisateur ET son magasin, avec l'utilisateur
 * positionné comme propriétaire (voir CreerMagasinAction). On ne sépare
 * pas "créer un compte" de "créer un magasin" dans l'UI, ça n'aurait pas
 * de sens métier ici -- un utilisateur qui s'inscrit ouvre toujours son
 * propre magasin.
 *
 * Le code du magasin, qui figurera dans les numéros de tous ses documents,
 * se choisit dès l'inscription : proposé depuis le nom du magasin tant que
 * la personne ne l'a pas changé, modifiable, et validé comme dans
 * l'identité du magasin (voir CodeMagasinLibre).
 *
 * L'inscription s'enregistre dans une seule transaction : un magasin
 * refusé n'y laisse jamais un compte sans magasin, dont l'adresse e-mail
 * empêcherait ensuite de recommencer.
 */
class RegisterMagasin extends Register
{
    protected ?bool $hasDatabaseTransactions = true;

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNomMagasinFormComponent(),
                        $this->getCodeMagasinFormComponent(),
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    /**
     * Quand le nom change, le code proposé suit, sauf si la personne a
     * saisi le sien : le code n'est remplacé que vide ou encore égal à la
     * proposition faite pour l'ancien nom.
     */
    protected function getNomMagasinFormComponent(): Component
    {
        return TextInput::make('nom_magasin')
            ->label('Nom du magasin')
            ->required()
            ->maxLength(150)
            ->autofocus()
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                $code = (string) $get('code');

                if ($code === '' || ($old !== null && $code === CodeMagasin::proposer($old))) {
                    $set('code', CodeMagasin::proposer((string) $state));
                }
            });
    }

    protected function getCodeMagasinFormComponent(): Component
    {
        return TextInput::make('code')
            ->label('Code du magasin')
            ->helperText('Il figurera dans les numéros de tous vos documents (ex. FAC-LYON1-2026-000001) et ne changera plus après votre premier document.')
            ->required()
            ->maxLength(CodeMagasin::LONGUEUR_MAXIMALE)
            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
            ->rule(new CodeMagasinLibre)
            ->dehydrateStateUsing(fn (string $state): string => CodeMagasin::normaliser($state));
    }

    /**
     * Le code, libre à la validation, peut être pris par une inscription
     * simultanée avant l'enregistrement : l'index unique de magasins.code
     * refuse alors le magasin, et la personne choisit un autre code, comme
     * si la validation l'avait refusé, et l'inscription entière s'annule,
     * compte compris.
     *
     * @throws ValidationException
     */
    protected function handleRegistration(array $data): Model
    {
        $utilisateur = $this->getUserModel()::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        try {
            app(CreerMagasinAction::class)->handle([
                'nom' => $data['nom_magasin'],
                'code' => $data['code'],
                'email' => $data['email'],
            ], $utilisateur);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['data.code' => CodeMagasinLibre::MESSAGE_DEJA_UTILISE]);
        }

        return $utilisateur;
    }
}
