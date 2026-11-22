<?php

namespace App\Filament\Concerns;

use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Actions\AfficherLIdentifiantAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseProvisoire;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Domain\Tenancy\Support\ValeurSimple;
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
 * passe ; absente, elle reçoit un mot de passe provisoire tiré au hasard,
 * montré une seule fois à qui le remet en main propre), afficher
 * l'identifiant (pour un administrateur, geste tracé), et la fenêtre qui
 * suit la création d'un compte. Chaque bouton n'apparaît qu'à qui peut faire ce
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
            ->modalDescription(fn (mixed $record = null): string => self::texteDeLaSuspension($compte($record)))
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
            ->modalDescription(fn (mixed $record = null): string => self::texteDuRetrait($compte($record)))
            ->visible(fn (mixed $record = null): bool => self::peut('retirerDeLaSociete', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->retirer(self::auteur(), $compte($record))));
    }

    /**
     * Le texte de la fenêtre « Suspendre » : pour un expert-comptable de la
     * société dont le lien du mot de passe vaut encore, ce lien ne vaudra
     * plus (voir ChangerStatutCompteAction::suspendre()).
     */
    public static function texteDeLaSuspension(User $compte): string
    {
        $texte = 'La personne n\'ouvre plus son magasin dès sa prochaine page, et ses sessions se ferment. Elle garde son rôle et ses autorisations pour sa réactivation.';

        if ($compte->estExpertComptable() && app(ExpertsComptablesDeclares::class)->attachementDe($compte)?->lienValable() === true) {
            return $texte.' Son lien en cours ne vaudra plus.';
        }

        return $texte;
    }

    /**
     * Le texte de la fenêtre « Retirer de la société » : retirer la dernière
     * personne non retirée d'un expert-comptable déclaré met fin à la
     * déclaration (voir ChangerStatutCompteAction::retirer()).
     */
    public static function texteDuRetrait(User $compte): string
    {
        $texte = "{$compte->name} perd aussitôt tout accès à la société et à son magasin. Son compte et son identifiant restent, avec son historique : la société pourra le réintégrer.";
        $declaration = $compte->declarationDeLExpertComptable();

        if ($declaration?->estDeclare() !== true) {
            return $texte;
        }

        $restantes = app(ExpertsComptablesDeclares::class)->comptesDe($declaration)->reject(fn (User $personne): bool => $personne->is($compte));

        return $restantes->isEmpty()
            ? "{$compte->name} perd aussitôt tout accès à la société et à ses magasins. C'est la dernière personne de votre expert-comptable déclaré : la retirer met fin à la déclaration."
            : $texte;
    }

    /**
     * « Redonner l'accès » (voir RedonnerLAccesAction) : la fenêtre tire
     * son jeton à son ouverture ; la même demande répétée après une
     * réponse perdue reste sans effet. Absente, la personne reçoit un mot de
     * passe provisoire tiré au hasard, que la notification montre une seule
     * fois à qui l'a redonné, pour qu'il le remette en main propre.
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
            ->modalDescription('Toutes ses sessions se ferment. À sa prochaine connexion, la personne verra que son accès a été redonné, par vous, en sa présence, en son absence ou par un lien.')
            ->fillForm(fn (mixed $record = null): array => ['jeton' => (string) Str::uuid(), ...($compte($record)->estCollaborateur() ? ['mode' => 'present'] : [])])
            ->form(fn (mixed $record = null): array => [
                Hidden::make('jeton'),
                Radio::make('mode')
                    ->label('La personne est-elle là ?')
                    ->options(match (true) {
                        $compte($record)->estCollaborateur() => ['present' => 'La personne est là : elle tape elle-même son nouveau mot de passe (un collaborateur ne se connecte jamais avec son identifiant)'],
                        $compte($record)->estExpertComptable() => [
                            'present' => 'En sa présence : elle tape elle-même son nouveau mot de passe',
                            'lien' => 'Lui envoyer un nouveau lien, valable 7 jours, à '.$compte($record)->email.' : son mot de passe actuel ne vaudra plus',
                        ],
                        default => [
                            'present' => 'La personne est là : elle tape elle-même son nouveau mot de passe',
                            'absent' => 'Elle est absente : un mot de passe provisoire, tiré au hasard, lui sera remis en main propre (il ne sert qu\'une fois, pendant '.MotsDePasseProvisoires::dureeEnHeures().' heures)',
                        ],
                    })
                    ->required()
                    ->live(),
                ...self::champsDuMotDePasseChoisi(fn (Get $get): bool => $get('mode') === 'present'),
            ])
            ->visible(fn (mixed $record = null): bool => self::peut('redonnerLAcces', $compte($record)))
            ->action(function (array $data, Form $form, mixed $record = null) use ($compte): void {
                $cible = $compte($record);
                $enPresence = $data['mode'] === 'present';

                if ($data['mode'] === 'lien') {
                    static::executerLeGeste(function () use ($cible, $data): void {
                        $envoye = app(RedonnerLAccesAction::class)->parUnNouveauLien(self::auteur(), $cible, ValeurSimple::enTexte($data['jeton'] ?? ''));

                        Notification::make()
                            ->title($envoye ? "Accès redonné : un nouveau lien, valable 7 jours, est parti à {$cible->email}." : RedonnerLAccesAction::MESSAGE_DEJA_REDONNE)
                            ->success()
                            ->send();
                    });

                    return;
                }

                self::exigerLeMotDePasseChoisi($enPresence ? ValeurSimple::enTexte($data['mot_de_passe'] ?? '') : null, $cible, $form);

                static::executerLeGeste(function () use ($cible, $enPresence, $data): void {
                    $jeton = ValeurSimple::enTexte($data['jeton'] ?? '');

                    if ($enPresence) {
                        $redonne = app(RedonnerLAccesAction::class)->enSaPresence(self::auteur(), $cible, ValeurSimple::enTexte($data['mot_de_passe']), $jeton);
                        Notification::make()
                            ->title($redonne ? 'Accès redonné : la personne se connecte avec le mot de passe qu\'elle vient de choisir.' : RedonnerLAccesAction::MESSAGE_DEJA_REDONNE)
                            ->success()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $provisoire = app(RedonnerLAccesAction::class)->enSonAbsence(self::auteur(), $cible, $jeton);

                    Notification::make()
                        ->title($provisoire === null ? RedonnerLAccesAction::MESSAGE_DEJA_REDONNE : self::phraseDuMotDePasseProvisoire($cible, $provisoire))
                        ->success()
                        ->persistent()
                        ->send();
                });
            });
    }

    /**
     * Ce que montre, une seule fois, la notification d'un accès redonné en
     * l'absence de la personne : l'identifiant, le mot de passe provisoire
     * à remettre en main propre, sa durée et ses conditions.
     */
    public static function phraseDuMotDePasseProvisoire(User $compte, MotDePasseProvisoire $provisoire): string
    {
        return "Accès redonné. Remettez en main propre l'identifiant {$compte->identifiantDeConnexion()} et le mot de passe provisoire {$provisoire->valeur()} : il ne s'affiche qu'une fois, ne sert qu'une fois, jusqu'au ".Calendrier::deParis()->afficher($provisoire->expireLe, 'd/m/Y à H:i').' (heure de Paris), depuis un poste reconnu, et la personne le remplace aussitôt par le sien.';
    }

    /**
     * « Afficher l'identifiant », pour un administrateur qui peut redonner
     * l'accès à ce compte : le geste s'inscrit au journal d'audit (voir
     * AfficherLIdentifiantAction).
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
            ->modalDescription('Ce geste s\'inscrit au journal d\'audit.')
            ->visible(fn (mixed $record = null): bool => self::peut('afficherLIdentifiant', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(function () use ($compte, $record): void {
                $identifiant = app(AfficherLIdentifiantAction::class)->handle(self::auteur(), $compte($record));
                Notification::make()->title("Identifiant de {$compte($record)->name} : {$identifiant}")->info()->persistent()->send();
            }));
    }

    /**
     * La fenêtre qui suit la création d'un compte, la seule à montrer son
     * identifiant et son mot de passe provisoire à qui l'a créé : le mot de
     * passe provisoire, tiré au hasard, ne s'affiche qu'une fois, à remettre
     * en main propre ; ou la personne, présente, choisit le sien tout de
     * suite (voir RedonnerLAccesAction::enSaPresence()). Elle ne montre que
     * le compte que la page vient de créer (voir MontreLeCompteCree),
     * jamais un compte désigné par la requête : relu dans la société, créé
     * par la personne connectée, qui doit pouvoir lui redonner l'accès ;
     * sans lui, elle ne s'ouvre pas.
     *
     * Le mot de passe provisoire n'ouvre le compte que depuis un poste
     * reconnu d'un magasin que ce compte ouvre (voir PostesReconnus), une
     * seule fois et pendant 72 heures, et la personne y choisit aussitôt son
     * mot de passe (voir ExigerSonPropreMotDePasse) : la fenêtre le dit. Un
     * administrateur qui travaille à distance, ou dont aucun magasin n'a de
     * poste reconnu, ne peut pas se connecter avec lui : si la personne est
     * là, qu'elle choisisse son mot de passe maintenant. Un collaborateur
     * d'un cabinet n'a aucun mot de passe provisoire (voir
     * PostesReconnus::ouvreLeCompte()) : la fenêtre propose seulement qu'il
     * choisisse son mot de passe maintenant ou que l'accès lui soit redonné
     * plus tard, en sa présence ; « Redonner l'accès » ne lui propose de même
     * que sa présence. Quand la demande de création est une demande répétée,
     * le mot de passe provisoire déjà montré ne se montre plus : « Redonner
     * l'accès » en tire un nouveau.
     *
     * @param  Closure(): ?int  $compteCree  l'identifiant technique du compte que la page vient de créer
     * @param  Closure(): ?string  $motDePasseProvisoire  son mot de passe provisoire, en clair, que la page tient le temps de le montrer
     * @param  Closure(): void  $oublier  oublie le mot de passe provisoire quand la fenêtre se ferme
     */
    public static function fenetreDuCompteCree(Action $action, Closure $compteCree, Closure $motDePasseProvisoire, Closure $oublier): Action
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
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid(), 'mode' => self::modeParDefaut($compte(), $motDePasseProvisoire())])
            ->form(fn (): array => [
                Hidden::make('jeton'),
                Placeholder::make('identifiant')
                    ->hiddenLabel()
                    ->content(fn (): string => ($cree = $compte()) === null ? '' : self::messageDuCompteCree($cree, $motDePasseProvisoire())),
                Radio::make('mode')
                    ->label('Et maintenant ?')
                    ->options(self::optionsDuCompteCree($compte(), $motDePasseProvisoire()))
                    ->required()
                    ->live(),
                ...self::champsDuMotDePasseChoisi(fn (Get $get): bool => $get('mode') === 'present'),
            ])
            ->action(function (array $data, Form $form) use ($compte, $oublier): void {
                $cible = $compte();

                if ($cible === null || ($data['mode'] ?? 'remettre') !== 'present') {
                    $oublier();

                    return;
                }

                self::exigerLeMotDePasseChoisi(ValeurSimple::enTexte($data['mot_de_passe'] ?? ''), $cible, $form);

                static::executerLeGeste(function () use ($cible, $data, $oublier): void {
                    app(RedonnerLAccesAction::class)->enSaPresence(self::auteur(), $cible, ValeurSimple::enTexte($data['mot_de_passe']), ValeurSimple::enTexte($data['jeton'] ?? ''));
                    $oublier();
                    Notification::make()->title('Son mot de passe est enregistré : la personne se connecte avec son identifiant et ce mot de passe.')->success()->send();
                });
            });
    }

    /**
     * Le choix proposé d'abord par la fenêtre du compte créé : remettre le
     * mot de passe provisoire quand on l'a, sinon la présence de la
     * personne (un collaborateur, un administrateur sans poste reconnu).
     */
    private static function modeParDefaut(?User $compte, ?string $motDePasseProvisoire): string
    {
        return $motDePasseProvisoire !== null && $compte?->estMembre() === true ? 'remettre' : 'present';
    }

    /**
     * @return array<string, string>
     */
    private static function optionsDuCompteCree(?User $compte, ?string $motDePasseProvisoire): array
    {
        if ($motDePasseProvisoire === null || $compte?->estCollaborateur() === true) {
            return [
                'present' => 'La personne est là : elle choisit son mot de passe maintenant',
                'plus_tard' => 'Plus tard : je lui redonnerai l\'accès en sa présence ou, en son absence, avec un mot de passe provisoire'.($compte?->estCollaborateur() === true ? '' : ' que je remettrai en main propre'),
            ];
        }

        return [
            'remettre' => 'Je remets l\'identifiant et le mot de passe provisoire à la personne',
            'present' => 'La personne est là : elle choisit son mot de passe maintenant',
        ];
    }

    /**
     * Ce que la fenêtre « Compte créé » dit du compte : son identifiant, son
     * mot de passe provisoire quand on le connaît, où il ouvre le compte, et
     * quand la personne choisit le sien. Le mot de passe provisoire ne se
     * montre qu'ici, une seule fois, et ne s'écrit nulle part.
     */
    public static function messageDuCompteCree(User $compte, ?string $motDePasseProvisoire): string
    {
        $identifiant = (string) $compte->identifiantDeConnexion();

        if ($compte->estCollaborateur()) {
            return "Identifiant : {$identifiant}. Un cabinet n'a pas de poste reconnu : un collaborateur n'a aucun mot de passe provisoire, et ne se connecte pas avec son seul identifiant. Il choisit son mot de passe maintenant, sur cet écran, ou plus tard, quand vous lui redonnerez l'accès en sa présence.";
        }

        if ($motDePasseProvisoire === null) {
            return "Identifiant : {$identifiant}. Le mot de passe provisoire de ce compte ne s'affiche qu'une fois, à sa création, et il n'est plus montré : « Redonner l'accès » en tire un nouveau, ou la personne, si elle est là, choisit son mot de passe maintenant.";
        }

        $expire = $compte->mot_de_passe_provisoire_expire_le === null ? '' : ' jusqu\'au '.Calendrier::deParis()->afficher($compte->mot_de_passe_provisoire_expire_le, 'd/m/Y à H:i').' (heure de Paris)';
        $poste = $compte->estMembre()
            ? 'un poste reconnu du magasin'
            : 'un poste reconnu d\'un magasin de sa portée';
        $texte = "Identifiant : {$identifiant}. Mot de passe provisoire : {$motDePasseProvisoire}. Remettez-les en main propre : le mot de passe provisoire ne s'affiche qu'ici, une seule fois ; il ne sert qu'une fois{$expire}, depuis {$poste}, et la personne le remplace aussitôt, à sa première connexion, par un mot de passe à elle.";

        return $compte->estMembre()
            ? $texte.' Elle pourra renseigner son adresse e-mail dans son profil.'
            : $texte.' À distance, ou si aucun magasin de sa portée n\'a de poste reconnu, elle ne pourra pas se connecter avec : si elle est là, qu\'elle choisisse son mot de passe maintenant.';
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
        $anciens = fn (Get $get, StatutCompte $statut): Collection => self::anciensComptes($magasin($get), $get('name'), $statut);

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
                    ->mapWithKeys(fn (User $compte): array => [(string) CleDuModele::de($compte) => "Réintégrer le compte retiré de {$compte->name} : son identifiant et son historique sont gardés"])
                    ->all() + ['nouveau' => 'Créer un nouveau compte'])
                ->required(fn (Get $get): bool => $anciens($get, StatutCompte::Retire)->isNotEmpty())
                ->visible(fn (Get $get): bool => $anciens($get, StatutCompte::Retire)->isNotEmpty()),
        ];
    }

    /**
     * Les anciens comptes de ce statut que la fenêtre de création propose
     * de reprendre : ceux du magasin choisi qui portent le nom saisi (voir
     * CreationDesComptes::comptesDuMemeNom()), aucun tant qu'aucun magasin
     * n'est choisi.
     *
     * @return Collection<int, User>
     */
    private static function anciensComptes(?Magasin $magasin, mixed $nom, StatutCompte $statut): Collection
    {
        return $magasin === null
            ? new Collection
            : app(CreationDesComptes::class)->comptesDuMemeNom($magasin, ValeurSimple::enTexte($nom))->filter(fn (User $compte): bool => $compte->statut === $statut)->values();
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
        $reprendre = ValeurSimple::enTexte($data['reprendre'] ?? 'nouveau');

        if ($reprendre !== 'nouveau' && $reprendre !== '') {
            $ancien = app(CreationDesComptes::class)->comptesDuMemeNom($magasin, ValeurSimple::enTexte($data['name']))
                ->first(fn (User $compte): bool => (string) CleDuModele::de($compte) === $reprendre && $compte->estRetire())
                ?? throw new AuthorizationException(PorteeDesDroits::REFUS);

            app(ChangerStatutCompteAction::class)->reintegrer($auteur, $ancien, $role, $autorisations);
            Notification::make()->title('Le compte est réintégré : la personne se connecte avec son identifiant et son mot de passe.')->success()->send();

            return null;
        }

        return app(CreerMembreAction::class)->handle($auteur, $magasin, ['name' => $data['name']], $role, $autorisations, filled($data['jeton'] ?? null) ? ValeurSimple::enTexte($data['jeton']) : null);
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
                ->helperText(RegleDuMotDePasse::AIDE)
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
