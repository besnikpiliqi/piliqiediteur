<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Actions\AfficherLIdentifiantAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as ActionDeTable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Les gestes sur tout un compte, partagés par l'équipe d'un magasin (voir
 * EquipeDuMagasinResource) et l'espace d'administration de la société
 * (voir MembreResource) : suspendre, réactiver, retirer de la société,
 * redonner l'accès (la personne présente tape elle-même son nouveau mot de
 * passe ; absente, il redevient son identifiant), afficher l'identifiant
 * (pour un administrateur, geste tracé), et la fenêtre qui suit la
 * création d'un compte. Chaque bouton n'apparaît qu'à qui peut faire ce
 * geste pour ce compte (voir MembrePolicy), et l'action du domaine le
 * revérifie sous ses verrous sur des comptes relus ; un refus s'affiche
 * sans casser la page (voir GereLesExceptionsMetier). Avant de créer le
 * compte d'un membre, la fenêtre propose les anciens comptes du même nom
 * dans ce magasin, pour réintégrer un ancien salarié plutôt que de lui
 * donner un second compte.
 */
trait GereLesComptes
{
    use GereLesExceptionsMetier;

    public const AIDE_DU_MOT_DE_PASSE = 'Au moins 8 caractères ; ni son identifiant, ni un seul caractère répété, ni une suite de chiffres.';

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionSuspendre(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Suspendre')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('La personne n\'ouvre plus son magasin dès sa prochaine page, et ses sessions se ferment. Elle garde son rôle et ses autorisations pour sa réactivation.')
            ->visible(fn (mixed $record = null): bool => self::peut('suspendreOuReactiver', $compte($record)) && $compte($record)->estActif())
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->suspendre(self::auteur(), $compte($record))));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionReactiver(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Réactiver')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (mixed $record = null): bool => self::peut('suspendreOuReactiver', $compte($record)) && $compte($record)->estSuspendu())
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->reactiver(self::auteur(), $compte($record))));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionRetirerDeLaSociete(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Retirer de la société')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (mixed $record = null): string => "{$compte($record)->name} perd aussitôt tout accès à la société et à son magasin. Son compte et son identifiant restent, avec son historique : la société pourra le réintégrer.")
            ->visible(fn (mixed $record = null): bool => self::peut('retirerDeLaSociete', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->retirer(self::auteur(), $compte($record))));
    }

    /**
     * « Redonner l'accès » (voir RedonnerLAccesAction) : la fenêtre tire
     * son jeton à son ouverture ; la même demande répétée après une
     * réponse perdue reste sans effet. Absente, la personne retrouve son
     * identifiant pour mot de passe, que la fenêtre affiche à qui l'a
     * redonné.
     *
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionRedonnerLAcces(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Redonner l\'accès')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->modalDescription('Toutes ses sessions se ferment. À sa prochaine connexion, la personne verra que son accès a été redonné, par vous, en sa présence ou en son absence.')
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid()])
            ->form([
                Hidden::make('jeton'),
                Radio::make('mode')
                    ->label('La personne est-elle là ?')
                    ->options([
                        'present' => 'La personne est là : elle tape elle-même son nouveau mot de passe',
                        'absent' => 'Elle est absente : son mot de passe redevient son identifiant',
                    ])
                    ->required()
                    ->live(),
                ...self::champsDuMotDePasseChoisi(fn (Get $get): bool => $get('mode') === 'present'),
            ])
            ->visible(fn (mixed $record = null): bool => self::peut('redonnerLAcces', $compte($record)))
            ->action(function (array $data, Form $form, mixed $record = null) use ($compte): void {
                $cible = $compte($record);
                $enPresence = $data['mode'] === 'present';

                self::exigerLeMotDePasseChoisi($enPresence ? (string) ($data['mot_de_passe'] ?? '') : null, $cible, $form);

                static::executerLeGeste(function () use ($cible, $enPresence, $data): void {
                    $redonne = app(RedonnerLAccesAction::class)->handle(self::auteur(), $cible, $enPresence, $enPresence ? (string) $data['mot_de_passe'] : null, (string) ($data['jeton'] ?? ''));

                    Notification::make()
                        ->title(match (true) {
                            ! $redonne => RedonnerLAccesAction::MESSAGE_DEJA_REDONNE,
                            $enPresence => 'Accès redonné : la personne se connecte avec le mot de passe qu\'elle vient de choisir.',
                            default => "Accès redonné. Son identifiant, qui est de nouveau son mot de passe : {$cible->identifiantDeConnexion()}. Remettez-le-lui en main propre.",
                        })
                        ->success()
                        ->persistent()
                        ->send();
                });
            });
    }

    /**
     * « Afficher l'identifiant », pour un administrateur qui peut redonner
     * l'accès à ce compte : le geste s'inscrit au journal d'audit, et le
     * propriétaire en est prévenu quand le mot de passe du compte est encore
     * son identifiant (voir AfficherLIdentifiantAction).
     *
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionAfficherLIdentifiant(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Afficher l\'identifiant')
            ->icon('heroicon-o-eye')
            ->requiresConfirmation()
            ->modalDescription('Ce geste s\'inscrit au journal d\'audit. Si le mot de passe de ce compte est encore son identifiant, le propriétaire en est prévenu.')
            ->visible(fn (mixed $record = null): bool => self::peut('afficherLIdentifiant', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(function () use ($compte, $record): void {
                $identifiant = app(AfficherLIdentifiantAction::class)->handle(self::auteur(), $compte($record));
                Notification::make()->title("Identifiant de {$compte($record)->name} : {$identifiant}")->info()->persistent()->send();
            }));
    }

    /**
     * La fenêtre qui suit la création d'un compte, la seule à montrer son
     * identifiant à qui l'a créé : il est aussi son premier mot de passe, à
     * remettre en main propre ; ou la personne, présente, choisit le sien
     * tout de suite (voir RedonnerLAccesAction, en sa présence). Elle ne
     * montre que le compte que la page vient de créer (voir
     * MontreLeCompteCree), jamais un compte désigné par la requête : relu
     * dans la société, créé par la personne connectée, qui doit pouvoir lui
     * redonner l'accès ; sans lui, elle ne s'ouvre pas.
     *
     * L'identifiant n'ouvre le compte que depuis un poste reconnu d'un
     * magasin que ce compte ouvre (voir PostesReconnus), et un responsable,
     * un administrateur ou un expert-comptable y choisit son mot de passe à
     * sa première connexion (voir ExigerSonPropreMotDePasse) : la fenêtre le
     * dit. Un administrateur ou un expert-comptable qui travaille à
     * distance, ou dont aucun magasin n'a de poste reconnu, ne peut pas se
     * connecter avec son identifiant : pour lui, la fenêtre propose d'abord
     * que la personne présente choisisse son mot de passe.
     *
     * @param  Closure(): ?int  $compteCree  l'identifiant technique du compte que la page vient de créer
     */
    public static function fenetreDuCompteCree(Action $action, Closure $compteCree): Action
    {
        $compte = fn (): ?User => ($id = $compteCree()) === null ? null : User::query()
            ->where('societe_id', self::auteur()->societe_id)
            ->where('cree_par', self::auteur()->getKey())
            ->whereKey($id)
            ->first();

        return $action
            ->label('Compte créé')
            ->modalHeading('Compte créé')
            ->modalSubmitActionLabel('Terminé')
            ->modalCancelAction(false)
            ->visible(fn (): bool => ($cree = $compte()) !== null && self::peut('redonnerLAcces', $cree))
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid(), 'mode' => $compte()?->estMembre() === false ? 'present' : 'remettre'])
            ->form(fn (): array => [
                Hidden::make('jeton'),
                Placeholder::make('identifiant')
                    ->hiddenLabel()
                    ->content(fn (): string => ($cree = $compte()) === null ? '' : self::messageDuCompteCree($cree)),
                Radio::make('mode')
                    ->label('Et maintenant ?')
                    ->options([
                        'remettre' => 'Je remets l\'identifiant à la personne',
                        'present' => 'La personne est là : elle choisit son mot de passe maintenant',
                    ])
                    ->required()
                    ->live(),
                ...self::champsDuMotDePasseChoisi(fn (Get $get): bool => $get('mode') === 'present'),
            ])
            ->action(function (array $data, Form $form) use ($compte): void {
                $cible = $compte();

                if ($cible === null || ($data['mode'] ?? 'remettre') !== 'present') {
                    return;
                }

                self::exigerLeMotDePasseChoisi((string) ($data['mot_de_passe'] ?? ''), $cible, $form);

                static::executerLeGeste(function () use ($cible, $data): void {
                    app(RedonnerLAccesAction::class)->handle(self::auteur(), $cible, true, (string) $data['mot_de_passe'], (string) ($data['jeton'] ?? ''));
                    Notification::make()->title('Son mot de passe est enregistré : la personne se connecte avec son identifiant et ce mot de passe.')->success()->send();
                });
            });
    }

    /**
     * Ce que la fenêtre « Compte créé » dit du compte : son identifiant, qui
     * est aussi son premier mot de passe, où il ouvre le compte, et quand la
     * personne choisit le sien.
     */
    public static function messageDuCompteCree(User $compte): string
    {
        $identifiant = "Identifiant : {$compte->identifiantDeConnexion()}. Mot de passe : le même que l'identifiant.";

        return match (true) {
            ! $compte->estMembre() => "{$identifiant} Il n'ouvre le compte que depuis un poste reconnu d'un magasin de sa portée, où la personne choisira son propre mot de passe à sa première connexion. À distance, ou si aucun magasin de sa portée n'a de poste reconnu, elle ne pourra pas se connecter avec son identifiant : si elle est là, qu'elle choisisse son mot de passe maintenant.",
            $compte->estResponsable() => "{$identifiant} Il n'ouvre le compte que depuis un poste reconnu du magasin, où la personne choisira son propre mot de passe à sa première connexion. Remettez-les en main propre ; elle pourra renseigner son adresse e-mail dans son profil.",
            default => "{$identifiant} Il n'ouvre le compte que depuis un poste reconnu du magasin. Remettez-les en main propre ; la personne pourra changer son mot de passe et renseigner son adresse e-mail dans son profil.",
        };
    }

    /**
     * Les champs de la création du compte d'un membre de ce magasin : le
     * jeton de la fenêtre, tiré à son ouverture (voir fillForm()), le nom,
     * puis, quand un compte suspendu ou retiré de ce magasin porte déjà ce
     * nom, la proposition de le réactiver ou de le réintégrer (voir
     * CreationDesComptes::comptesDuMemeNom()). Aucune adresse e-mail : la
     * personne la renseigne elle-même dans son profil, si elle le veut.
     *
     * @param  Closure(Get): ?Magasin  $magasin
     * @return list<Component>
     */
    public static function champsDuNouveauMembre(Closure $magasin): array
    {
        $anciens = function (Get $get, StatutCompte $statut) use ($magasin): Collection {
            $leMagasin = $magasin($get);

            return $leMagasin === null
                ? collect()
                : app(CreationDesComptes::class)->comptesDuMemeNom($leMagasin, (string) $get('name'))->filter(fn (User $compte): bool => $compte->statut === $statut)->values();
        };

        return [
            Hidden::make('jeton'),
            TextInput::make('name')->label('Nom complet')->required()->maxLength(255)->live(onBlur: true),
            Placeholder::make('compte_suspendu')
                ->hiddenLabel()
                ->content('Un compte suspendu porte déjà ce nom dans ce magasin : s\'il s\'agit de la même personne, réactivez-le plutôt que de lui en créer un autre.')
                ->visible(fn (Get $get): bool => $anciens($get, StatutCompte::Suspendu)->isNotEmpty()),
            Radio::make('reprendre')
                ->label('Un ancien compte porte ce nom dans ce magasin')
                ->options(fn (Get $get): array => $anciens($get, StatutCompte::Retire)
                    ->mapWithKeys(fn (User $compte): array => [(string) $compte->getKey() => "Réintégrer le compte retiré de {$compte->name} : son identifiant et son historique sont gardés"])
                    ->all() + ['nouveau' => 'Créer un nouveau compte'])
                ->required(fn (Get $get): bool => $anciens($get, StatutCompte::Retire)->isNotEmpty())
                ->visible(fn (Get $get): bool => $anciens($get, StatutCompte::Retire)->isNotEmpty()),
        ];
    }

    /**
     * Crée le compte d'un membre de ce magasin, ou réintègre l'ancien compte
     * retiré du même nom que la fenêtre a proposé (voir
     * ChangerStatutCompteAction::reintegrer()) : rend le compte créé, ou
     * null pour une réintégration, qui garde son identifiant et son mot de
     * passe.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $autorisations
     *
     * @throws AuthorizationException
     */
    public static function creerOuReintegrerUnMembre(User $auteur, Magasin $magasin, array $data, RoleMagasin|string $role, ?array $autorisations): ?User
    {
        $reprendre = (string) ($data['reprendre'] ?? 'nouveau');

        if ($reprendre !== 'nouveau' && $reprendre !== '') {
            $ancien = app(CreationDesComptes::class)->comptesDuMemeNom($magasin, (string) $data['name'])
                ->first(fn (User $compte): bool => (string) $compte->getKey() === $reprendre && $compte->estRetire())
                ?? throw new AuthorizationException(PorteeDesDroits::REFUS);

            app(ChangerStatutCompteAction::class)->reintegrer($auteur, $ancien, $role, $autorisations);
            Notification::make()->title('Le compte est réintégré : la personne se connecte avec son identifiant et son mot de passe.')->success()->send();

            return null;
        }

        return app(CreerMembreAction::class)->handle($auteur, $magasin, ['name' => $data['name']], $role, $autorisations, filled($data['jeton'] ?? null) ? (string) $data['jeton'] : null);
    }

    /**
     * Le nouveau mot de passe que la personne présente tape elle-même deux
     * fois sur l'écran de qui lui redonne l'accès, jamais affiché.
     *
     * @param  Closure(Get): bool  $visible
     * @return list<TextInput>
     */
    public static function champsDuMotDePasseChoisi(Closure $visible): array
    {
        return [
            TextInput::make('mot_de_passe')
                ->label('Son nouveau mot de passe, tapé par elle')
                ->password()
                ->autocomplete('new-password')
                ->helperText(self::AIDE_DU_MOT_DE_PASSE)
                ->required($visible)
                ->visible($visible)
                ->same('mot_de_passe_confirmation'),
            TextInput::make('mot_de_passe_confirmation')
                ->label('Qu\'elle le tape une seconde fois')
                ->password()
                ->autocomplete('new-password')
                ->required($visible)
                ->visible($visible)
                ->dehydrated(false),
        ];
    }

    /**
     * Comme executerSansCasser(), et une donnée refusée par l'action (un
     * mot de passe qui ne suit pas la règle, par exemple) s'affiche aussi,
     * message par message.
     */
    public static function executerLeGeste(Closure $geste): void
    {
        try {
            static::executerSansCasser($geste);
        } catch (ValidationException $refus) {
            Notification::make()->title(implode(' ', array_merge(...array_values($refus->errors()))))->danger()->send();
        }
    }

    protected static function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }

    /**
     * Refuse, sur son champ et la fenêtre restant ouverte, un mot de passe
     * choisi qui ne suit pas la règle (voir RegleDuMotDePasse) ; rien sans
     * mot de passe (la personne est absente).
     *
     * @throws ValidationException
     */
    public static function exigerLeMotDePasseChoisi(?string $motDePasse, User $compte, Form $form): void
    {
        if ($motDePasse !== null) {
            RegleDuMotDePasse::exiger($motDePasse, $compte->identifiantDeConnexion(), $form->getStatePath().'.mot_de_passe');
        }
    }

    /**
     * La personne connectée peut-elle faire ce geste sur ce compte (voir
     * MembrePolicy) ?
     */
    public static function peut(string $geste, User $compte): bool
    {
        return auth()->user()?->can($geste, $compte) ?? false;
    }
}
