<?php

namespace App\Filament\Societe\Resources\MembreResource\Pages;

use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * La liste des comptes, avec les créations que permet le rôle de la
 * personne connectée : un membre, un administrateur ou un expert-comptable
 * pour le propriétaire ; un responsable pour lui et pour un administrateur
 * autorisé à nommer les responsables. Chaque action du domaine revérifie
 * tout sur l'auteur relu sous ses verrous.
 */
class ListMembres extends ListRecords
{
    protected static string $resource = MembreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->nommerUnResponsableAction(),
            $this->creerUnMembreAction(),
            $this->creerUnAdministrateurAction(),
            $this->creerUnExpertComptableAction(),
        ];
    }

    /**
     * Nommer un responsable dans un magasin de sa portée : un nouveau
     * compte, ou un membre existant (pour un administrateur, un vendeur ou
     * un observateur de ce magasin qui a choisi son mot de passe ou qu'il a
     * créé).
     */
    public function nommerUnResponsableAction(): Action
    {
        return Action::make('nommerUnResponsable')
            ->label('Nommer un responsable')
            ->icon('heroicon-o-star')
            ->visible(fn (): bool => collect(MembreResource::magasinsDeLaPortee())->keys()->contains(fn (int $id): bool => app(PorteeDesDroits::class)->peutNommerUnResponsable($this->auteur(), MembreResource::magasin($id))))
            ->form([
                Select::make('magasin_id')
                    ->label('Magasin')
                    ->options(fn (): array => collect(MembreResource::magasinsDeLaPortee())->filter(fn (string $nom, int $id): bool => app(PorteeDesDroits::class)->peutNommerUnResponsable($this->auteur(), MembreResource::magasin($id)))->all())
                    ->required()
                    ->live(),
                Radio::make('compte')
                    ->label('Qui ?')
                    ->options(['nouveau' => 'Un nouveau compte', 'existant' => 'Une personne qui a déjà un compte'])
                    ->required()
                    ->live(),
                Select::make('candidat')
                    ->label('Personne')
                    ->options(fn (Get $get): array => filled($get('magasin_id'))
                        ? app(PorteeDesDroits::class)->candidatsResponsables($this->auteur(), MembreResource::magasin((int) $get('magasin_id')))->orderBy('name')->pluck('name', 'users.id')->all()
                        : [])
                    ->required(fn (Get $get): bool => $get('compte') === 'existant')
                    ->visible(fn (Get $get): bool => $get('compte') === 'existant'),
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255)->visible(fn (Get $get): bool => $get('compte') === 'nouveau'),
                TextInput::make('email')->label('Adresse e-mail de connexion')->email()->adresseDeConnexion()->required()->maxLength(255)->visible(fn (Get $get): bool => $get('compte') === 'nouveau'),
                MembreResource::champMotDePasseProvisoire()->visible(fn (Get $get): bool => $get('compte') === 'nouveau'),
            ])
            ->modalDescription('Un responsable a tous les droits dans son magasin, sans case. Le propriétaire est prévenu de chaque responsable qu\'un administrateur nomme, et peut annuler la nomination.')
            ->action(function (array $data): void {
                MembreResource::executerLeGeste(function () use ($data): void {
                    $affectation = [(int) $data['magasin_id'] => ['role' => RoleMagasin::Responsable]];

                    if ($data['compte'] === 'existant') {
                        app(ModifierMembreAction::class)->handle($this->auteur(), User::query()->where('societe_id', MembreResource::societe()->getKey())->findOrFail($data['candidat']), $affectation);
                    } else {
                        app(CreerMembreAction::class)->handle($this->auteur(), MembreResource::societe(), ['name' => $data['name'], 'email' => $data['email']], $data['mot_de_passe_provisoire'], $affectation);
                    }

                    Notification::make()->title('Le responsable est nommé.')->success()->send();
                });
            });
    }

    public function creerUnMembreAction(): Action
    {
        return Action::make('creerUnMembre')
            ->label('Créer un membre')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => $this->auteur()->estProprietaire())
            ->form([
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                TextInput::make('email')->label('Adresse e-mail de connexion')->email()->adresseDeConnexion()->required()->maxLength(255),
                MembreResource::champMotDePasseProvisoire(),
                MembreResource::champAffectations(),
            ])
            ->action(function (array $data): void {
                MembreResource::executerLeGeste(function () use ($data): void {
                    app(CreerMembreAction::class)->handle($this->auteur(), MembreResource::societe(), ['name' => $data['name'], 'email' => $data['email']], $data['mot_de_passe_provisoire'], MembreResource::affectationsSaisies($data['affectations']));
                    Notification::make()->title('Le compte est créé : remettez le mot de passe provisoire à la personne.')->success()->send();
                });
            });
    }

    public function creerUnAdministrateurAction(): Action
    {
        return Action::make('creerUnAdministrateur')
            ->label('Créer un administrateur')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->auteur()->can('creerUnAdministrateur', User::class))
            ->form([
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                TextInput::make('email')->label('Adresse e-mail de connexion')->email()->adresseDeConnexion()->required()->maxLength(255),
                MembreResource::champMotDePasseProvisoire(),
                ...MembreResource::champsDeLAdministrateur(),
            ])
            ->action(function (array $data): void {
                MembreResource::executerLeGeste(function () use ($data): void {
                    app(CreerAdministrateurAction::class)->handle(
                        $this->auteur(),
                        MembreResource::societe(),
                        ['name' => $data['name'], 'email' => $data['email']],
                        $data['mot_de_passe_provisoire'],
                        $data['portee'] === 'tous' ? null : array_map(intval(...), $data['magasins'] ?? []),
                        array_values($data['autorisations_magasin'] ?? []),
                        array_values($data['autorisations_societe'] ?? []),
                    );
                    Notification::make()->title('L\'administrateur est créé : remettez-lui son mot de passe provisoire.')->success()->send();
                });
            });
    }

    public function creerUnExpertComptableAction(): Action
    {
        return Action::make('creerUnExpertComptable')
            ->label('Créer un expert-comptable')
            ->icon('heroicon-o-calculator')
            ->visible(fn (): bool => $this->auteur()->can('creerLExpertComptable', User::class))
            ->modalDescription('Un compte par collaborateur du cabinet : il lit tous les magasins réels de la société, présents et futurs, et n\'écrit rien.')
            ->form([
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                TextInput::make('email')->label('Adresse e-mail de connexion')->email()->adresseDeConnexion()->required()->maxLength(255),
                MembreResource::champMotDePasseProvisoire(),
            ])
            ->action(function (array $data): void {
                MembreResource::executerLeGeste(function () use ($data): void {
                    app(CreerExpertComptableAction::class)->handle($this->auteur(), MembreResource::societe(), ['name' => $data['name'], 'email' => $data['email']], $data['mot_de_passe_provisoire']);
                    Notification::make()->title('Le compte de l\'expert-comptable est créé : remettez-lui son mot de passe provisoire.')->success()->send();
                });
            });
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
