<?php

namespace App\Filament\Societe\Resources\MembreResource\Pages;

use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Concerns\MontreLeCompteCree;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * La liste des comptes, avec les créations que permet le rôle de la
 * personne connectée : un membre (pour un seul magasin), un administrateur
 * ou un expert-comptable pour le propriétaire ; un responsable pour lui et
 * pour un administrateur autorisé à nommer les responsables, dans un
 * magasin réel de sa portée. Chaque fenêtre de création tire son jeton à
 * son ouverture (une demande répétée après une réponse perdue ne crée
 * jamais un second compte) et ne demande aucune adresse e-mail ; elle est
 * suivie de la fenêtre qui montre l'identifiant du compte créé, aussi son
 * premier mot de passe (voir GereLesComptes::fenetreDuCompteCree()).
 * Chaque action du domaine revérifie tout sur l'auteur relu sous ses
 * verrous.
 */
class ListMembres extends ListRecords
{
    use MontreLeCompteCree;

    protected static string $resource = MembreResource::class;

    public const AVERTISSEMENT_IDENTIFIANT = 'Son mot de passe est encore son identifiant : toute personne qui le connaît pourra agir avec ses nouveaux droits.';

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
     * Nommer un responsable dans un magasin réel de sa portée : un nouveau
     * compte de ce magasin, ou un vendeur ou un observateur actif de ce
     * magasin ; un compte dont le mot de passe est encore son identifiant
     * est nommé avec un avertissement, et la personne présente peut choisir
     * son mot de passe dans la même fenêtre.
     */
    public function nommerUnResponsableAction(): Action
    {
        return Action::make('nommerUnResponsable')
            ->label('Nommer un responsable')
            ->icon('heroicon-o-star')
            ->visible(fn (): bool => $this->magasinsOuNommerUnResponsable() !== [])
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid(), 'jeton_acces' => (string) Str::uuid(), 'mode' => 'plus_tard'])
            ->form([
                Select::make('magasin_id')
                    ->label('Magasin')
                    ->options(fn (): array => $this->magasinsOuNommerUnResponsable())
                    ->required()
                    ->live(),
                Radio::make('compte')
                    ->label('Qui ?')
                    ->options(['nouveau' => 'Un nouveau compte de ce magasin', 'existant' => 'Un vendeur ou un observateur de ce magasin'])
                    ->required()
                    ->live(),
                Select::make('candidat')
                    ->label('Personne')
                    ->options(fn (Get $get): array => ($magasin = $this->magasinChoisi($get)) === null
                        ? []
                        : app(PorteeDesDroits::class)->candidatsResponsables($this->auteur(), $magasin)->orderBy('name')->pluck('name', 'users.id')->all())
                    ->required(fn (Get $get): bool => $get('compte') === 'existant')
                    ->visible(fn (Get $get): bool => $get('compte') === 'existant')
                    ->live(),
                Placeholder::make('avertissement')
                    ->hiddenLabel()
                    ->content(self::AVERTISSEMENT_IDENTIFIANT)
                    ->visible(fn (Get $get): bool => $this->candidatAuMotDePasseIdentifiant($get)),
                Hidden::make('jeton_acces'),
                Radio::make('mode')
                    ->label('La personne est-elle là ?')
                    ->options(['plus_tard' => 'Non : elle choisira son mot de passe à sa prochaine connexion', 'present' => 'Oui : elle choisit son mot de passe maintenant'])
                    ->live()
                    ->visible(fn (Get $get): bool => $this->candidatAuMotDePasseIdentifiant($get)),
                ...MembreResource::champsDuMotDePasseChoisi(fn (Get $get): bool => $this->candidatAuMotDePasseIdentifiant($get) && $get('mode') === 'present'),
                Group::make(MembreResource::champsDuNouveauMembre(fn (Get $get): ?Magasin => $this->magasinChoisi($get)))
                    ->visible(fn (Get $get): bool => $get('compte') === 'nouveau'),
            ])
            ->modalDescription('Un responsable a tous les droits dans son magasin, sans case, et ne vaut que pour ce magasin. Le propriétaire est prévenu de chaque responsable qu\'un administrateur nomme, et peut annuler la nomination.')
            ->action(function (array $data, Form $form): void {
                $cree = null;

                if ($data['compte'] === 'existant') {
                    $candidat = User::query()->where('societe_id', MembreResource::societe()->getKey())->find($data['candidat']);
                    $enPresence = $candidat?->mot_de_passe_est_l_identifiant && ($data['mode'] ?? null) === 'present';

                    if ($enPresence) {
                        MembreResource::exigerLeMotDePasseChoisi((string) ($data['mot_de_passe'] ?? ''), $candidat, $form);
                    }

                    MembreResource::executerLeGeste(function () use ($candidat, $enPresence, $data): void {
                        app(ModifierMembreAction::class)->handle($this->auteur(), $candidat ?? throw new AuthorizationException(PorteeDesDroits::REFUS), RoleMagasin::Responsable);

                        if ($enPresence) {
                            app(RedonnerLAccesAction::class)->handle($this->auteur(), $candidat, true, (string) $data['mot_de_passe'], (string) $data['jeton_acces']);
                        }

                        Notification::make()->title('Le responsable est nommé.')->success()->send();
                    });

                    return;
                }

                MembreResource::executerLeGeste(function () use ($data, &$cree): void {
                    $magasin = $this->magasinChoisi(fn (string $champ): mixed => $data[$champ] ?? null) ?? throw new AuthorizationException(PorteeDesDroits::REFUS);
                    $cree = MembreResource::creerOuReintegrerUnMembre($this->auteur(), $magasin, $data, RoleMagasin::Responsable, []);
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }

    /**
     * Créer le compte d'un membre pour un magasin, le seul qu'il ouvrira :
     * le propriétaire, dans tout magasin, démonstration comprise (des
     * comptes d'entraînement).
     */
    public function creerUnMembreAction(): Action
    {
        return Action::make('creerUnMembre')
            ->label('Créer un membre')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => $this->gereLEquipe() && $this->auteur()->estProprietaire())
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid()])
            ->form([
                Select::make('magasin_id')
                    ->label('Magasin')
                    ->options(fn (): array => MembreResource::magasinsOuCreerUnCompte())
                    ->helperText('Un compte de membre ne vaut que pour ce magasin : une personne qui travaille dans plusieurs magasins a un compte dans chacun.')
                    ->required()
                    ->live(),
                ...MembreResource::champsDuNouveauMembre(fn (Get $get): ?Magasin => $this->magasinChoisi($get)),
                ...MembreResource::champsDuRoleDeMembre(),
            ])
            ->action(function (array $data): void {
                $cree = null;

                MembreResource::executerLeGeste(function () use ($data, &$cree): void {
                    $magasin = $this->magasinChoisi(fn (string $champ): mixed => $data[$champ] ?? null) ?? throw new AuthorizationException(PorteeDesDroits::REFUS);
                    $role = MembreResource::roleSaisi($data);
                    $cree = MembreResource::creerOuReintegrerUnMembre($this->auteur(), $magasin, $data, $role['role'], $role['autorisations']);
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }

    public function creerUnAdministrateurAction(): Action
    {
        return Action::make('creerUnAdministrateur')
            ->label('Créer un administrateur')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->auteur()->can('creerUnAdministrateur', User::class))
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid(), ...MembreResource::modeleDeLAdministrateur()])
            ->form([
                Hidden::make('jeton'),
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
                ...MembreResource::champsDeLAdministrateur(),
            ])
            ->modalDescription('Il choisira son propre mot de passe à sa première connexion, depuis un poste reconnu d\'un magasin de sa portée, ou tout de suite, dans la fenêtre suivante, s\'il est là.')
            ->action(function (array $data): void {
                $cree = null;

                MembreResource::executerLeGeste(function () use ($data, &$cree): void {
                    $cree = app(CreerAdministrateurAction::class)->handle(
                        $this->auteur(),
                        MembreResource::societe(),
                        ['name' => $data['name']],
                        $data['portee'] === 'tous' ? null : array_map(intval(...), $data['magasins'] ?? []),
                        MembreResource::autorisationsSaisies($data, 'autorisations_magasin'),
                        array_values($data['autorisations_societe'] ?? []),
                        filled($data['jeton'] ?? null) ? (string) $data['jeton'] : null,
                    );
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }

    public function creerUnExpertComptableAction(): Action
    {
        return Action::make('creerUnExpertComptable')
            ->label('Créer un expert-comptable')
            ->icon('heroicon-o-calculator')
            ->visible(fn (): bool => $this->auteur()->can('creerLExpertComptable', User::class))
            ->modalDescription('Un compte par collaborateur du cabinet : il lit tous les magasins réels de la société, présents et futurs, et n\'écrit rien. Il choisira son propre mot de passe à sa première connexion, depuis un poste reconnu d\'un magasin réel de la société, ou tout de suite, dans la fenêtre suivante, s\'il est là.')
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid()])
            ->form([
                Hidden::make('jeton'),
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $cree = null;

                MembreResource::executerLeGeste(function () use ($data, &$cree): void {
                    $cree = app(CreerExpertComptableAction::class)->handle($this->auteur(), MembreResource::societe(), ['name' => $data['name']], filled($data['jeton'] ?? null) ? (string) $data['jeton'] : null);
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }

    /**
     * Le magasin choisi dans la fenêtre, s'il est de sa portée et qu'un
     * compte peut y être créé (voir MembreResource::magasinsOuCreerUnCompte()).
     *
     * @param  callable(string): mixed  $get
     */
    private function magasinChoisi(callable $get): ?Magasin
    {
        $id = (int) $get('magasin_id');

        return array_key_exists($id, MembreResource::magasinsOuCreerUnCompte()) ? MembreResource::magasin($id) : null;
    }

    /**
     * Le vendeur ou l'observateur choisi pour être nommé responsable a-t-il
     * encore son identifiant pour mot de passe ?
     */
    private function candidatAuMotDePasseIdentifiant(Get $get): bool
    {
        if ($get('compte') !== 'existant' || blank($get('candidat'))) {
            return false;
        }

        return (bool) User::query()->where('societe_id', MembreResource::societe()->getKey())->whereKey($get('candidat'))->value('mot_de_passe_est_l_identifiant');
    }

    /**
     * Les magasins de sa portée où la personne connectée peut nommer un
     * responsable.
     *
     * @return array<int, string>
     */
    private function magasinsOuNommerUnResponsable(): array
    {
        return collect(MembreResource::magasinsOuCreerUnCompte())
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
