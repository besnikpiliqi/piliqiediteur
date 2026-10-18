<?php

namespace App\Filament\Societe\Resources\MembreResource\Pages;

use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\ProposerUnRattachementAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
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
use Illuminate\Auth\Access\AuthorizationException;

/**
 * La liste des comptes, avec les créations que permet le rôle de la
 * personne connectée : un membre, un administrateur ou un expert-comptable
 * pour le propriétaire ; un responsable pour lui et pour un administrateur
 * autorisé à nommer les responsables. Chacun d'eux propose aussi, par son
 * adresse, à une personne dont le compte a été retiré de sa société de
 * rejoindre l'équipe avec ce compte, dans les rôles qu'il peut donner
 * (voir ProposerUnRattachementAction). Chaque action du domaine revérifie
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
            $this->proposerUnRattachementAction(),
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
            ->visible(fn (): bool => $this->magasinsOuNommerUnResponsable() !== [])
            ->form([
                Select::make('magasin_id')
                    ->label('Magasin')
                    ->options(fn (): array => $this->magasinsOuNommerUnResponsable())
                    ->required()
                    ->live(),
                Radio::make('compte')
                    ->label('Qui ?')
                    ->options(['nouveau' => 'Un nouveau compte', 'existant' => 'Une personne qui a déjà un compte'])
                    ->required()
                    ->live(),
                Select::make('candidat')
                    ->label('Personne')
                    ->options(fn (Get $get): array => array_key_exists((int) $get('magasin_id'), $this->magasinsOuNommerUnResponsable())
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
                        app(ModifierMembreAction::class)->handle($this->auteur(), User::query()->where('societe_id', MembreResource::societe()->getKey())->find($data['candidat']) ?? throw new AuthorizationException(PorteeDesDroits::REFUS), $affectation);
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
            ->visible(fn (): bool => $this->gereLEquipe() && $this->auteur()->estProprietaire())
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
                        MembreResource::autorisationsSaisies($data, 'autorisations_magasin'),
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

    /**
     * Proposer à une personne dont le compte a été retiré de sa société de
     * rejoindre l'équipe : le propriétaire, comme membre de magasins qu'il
     * choisit ou comme expert-comptable ; un administrateur autorisé, comme
     * responsable d'un magasin de sa portée. La réponse ne dit jamais si
     * l'adresse est celle d'un compte.
     */
    public function proposerUnRattachementAction(): Action
    {
        return Action::make('proposerUnRattachement')
            ->label('Proposer à un compte existant')
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => $this->gereLEquipe() && ($this->auteur()->estProprietaire() || $this->magasinsOuNommerUnResponsable() !== []))
            ->modalDescription('Une personne dont le compte a été retiré de sa société peut rejoindre la vôtre avec ce compte, si elle accepte : elle reçoit une proposition à son adresse, valable sept jours.')
            ->form([
                TextInput::make('email')->label('Adresse e-mail de son compte')->email()->adresseDeConnexion()->required()->maxLength(255),
                Radio::make('role_societe')
                    ->label('Rôle proposé')
                    ->options([RoleSociete::Membre->value => 'Membre de magasins', RoleSociete::ExpertComptable->value => 'Expert-comptable (lecture de tous les magasins)'])
                    ->default(RoleSociete::Membre->value)
                    ->required()
                    ->live()
                    ->visible(fn (): bool => $this->auteur()->estProprietaire()),
                MembreResource::champAffectations()
                    ->visible(fn (Get $get): bool => $this->auteur()->estProprietaire() && $get('role_societe') === RoleSociete::Membre->value),
                Select::make('magasin_id')
                    ->label('Responsable de')
                    ->options(fn (): array => $this->magasinsOuNommerUnResponsable())
                    ->required()
                    ->visible(fn (): bool => ! $this->auteur()->estProprietaire()),
            ])
            ->action(function (array $data): void {
                MembreResource::executerLeGeste(function () use ($data): void {
                    $role = RoleSociete::from($data['role_societe'] ?? RoleSociete::Membre->value);
                    $affectations = match (true) {
                        $role === RoleSociete::ExpertComptable => [],
                        isset($data['affectations']) => MembreResource::affectationsSaisies($data['affectations']),
                        default => [(int) $data['magasin_id'] => ['role' => RoleMagasin::Responsable->value]],
                    };

                    app(ProposerUnRattachementAction::class)->handle($this->auteur(), MembreResource::societe(), $data['email'], $role, $affectations);
                    Notification::make()->title(ProposerUnRattachementAction::REPONSE)->success()->send();
                });
            });
    }

    /**
     * Les magasins de sa portée où la personne connectée peut nommer un
     * responsable.
     *
     * @return array<int, string>
     */
    private function magasinsOuNommerUnResponsable(): array
    {
        return collect(MembreResource::magasinsDeLaPortee())
            ->filter(fn (string $nom, int $id): bool => app(PorteeDesDroits::class)->peutNommerUnResponsable($this->auteur(), MembreResource::magasin($id)))
            ->all();
    }

    /**
     * La personne connectée gère-t-elle encore l'équipe de sa société (pas
     * dans une société révoquée, voir PorteeDesDroits::gereSonEquipe()) ?
     */
    private function gereLEquipe(): bool
    {
        return app(PorteeDesDroits::class)->gereSonEquipe($this->auteur());
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
